<?php

namespace AppLocalPlugins\Youtubearr;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Jobs\GenerateEpgCache;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\StreamProfile;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

class Plugin implements ChannelProcessorPluginInterface, PluginInterface, ScheduledPluginInterface
{
    private const PLUGIN_MARKER = 'youtubearr';

    private const EPG_SOURCE_NAME = 'YouTubearr';

    private ?Epg $epgSource = null;

    /** @var array<string, string> */
    private array $avatarCache = [];

    // -------------------------------------------------------------------------
    // PluginInterface
    // -------------------------------------------------------------------------

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'check_now' => $this->handleCheckNow($context),
            'add_manual' => $this->handleAddManual($payload, $context),
            'cleanup' => $this->handleCleanup($context),
            'reset_all' => $this->handleResetAll($context),
            default => PluginActionResult::failure("Unknown action: {$action}"),
        };
    }

    // -------------------------------------------------------------------------
    // ScheduledPluginInterface
    // -------------------------------------------------------------------------

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? false)) {
            return [];
        }

        $cron = (string) ($settings['schedule_cron'] ?? '*/15 * * * *');

        if (! CronExpression::isValidExpression($cron)) {
            return [];
        }

        if (! (new CronExpression($cron))->isDue($now)) {
            return [];
        }

        return [[
            'type' => 'action',
            'name' => 'check_now',
            'payload' => ['source' => 'schedule'],
            'dry_run' => false,
        ]];
    }

    // -------------------------------------------------------------------------
    // Action Handlers
    // -------------------------------------------------------------------------

    private function handleCheckNow(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID. Ensure a Stream Profile is configured.');
        }

        $ytdlp = $this->findYtDlp();
        if (! $ytdlp) {
            return PluginActionResult::failure('yt-dlp binary not found. Ensure yt-dlp is installed.');
        }

        $channelLines = $this->parseMonitoredChannels($settings['monitored_channels'] ?? '');
        $monitoredStreamLines = $this->parseMonitoredStreams($settings['monitored_streams'] ?? '');

        if (empty($channelLines) && empty($monitoredStreamLines)) {
            return PluginActionResult::failure('No channels or streams configured. Add YouTube channel handles to Monitored Channels or direct URLs to Monitored Streams.');
        }

        $added = 0;
        $skipped = 0;
        $cleaned = 0;
        $errors = [];
        $cookiesFile = $this->getCookiesFile($profile);

        // Pre-fetch channel avatars for all monitored handles if avatar mode is active.
        $logoSource = $settings['channel_logo_source'] ?? 'stream_thumbnail';
        if ($logoSource === 'channel_avatar') {
            foreach ($channelLines as $entry) {
                $this->fetchChannelAvatar($entry['handle']);
            }
        }

        // --- Handle-based monitoring: scan each channel's /streams playlist ---
        foreach ($channelLines as $entry) {
            $handle = $entry['handle'];
            $baseNumber = $entry['base_number'];
            $titleFilter = $entry['title_filter'];

            $context->info("Checking @{$handle} for live streams…");

            $streams = $this->fetchAllLiveStreams($ytdlp, $handle, $settings, $cookiesFile);

            if (empty($streams)) {
                $context->info("@{$handle}: no live streams found");

                continue;
            }

            $context->info("@{$handle}: found ".count($streams).' live stream(s)');

            foreach ($streams as $metadata) {
                if ($titleFilter && ! preg_match('/'.$titleFilter.'/i', $metadata['title'])) {
                    $context->info("@{$handle}: skipping '{$metadata['title']}' — does not match filter '{$titleFilter}'");

                    continue;
                }

                $videoId = $metadata['video_id'];

                $existing = Channel::where('user_id', $userId)
                    ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                    ->whereJsonContains('info->youtube_video_id', $videoId)
                    ->first();

                if ($existing) {
                    $context->info("@{$handle}: '{$metadata['title']}' already tracked as channel #{$existing->channel} — updating.");
                    $this->updateChannel($existing, $metadata, $settings, $userId);
                    $skipped++;

                    continue;
                }

                $channelNumber = $this->nextChannelNumber($userId, $settings, $handle, $baseNumber);

                try {
                    $this->createChannel($metadata, $handle, $settings, $userId, $channelNumber);
                    $context->info("@{$handle}: added '{$metadata['title']}' as channel #{$channelNumber}");
                    $added++;
                } catch (\Throwable $e) {
                    $context->error("@{$handle}: failed to create channel for '{$metadata['title']}' — {$e->getMessage()}");
                    $errors[] = "@{$handle}: {$e->getMessage()}";
                }
            }
        }

        // --- Direct stream monitoring: check each specific video ID/URL ---
        foreach ($monitoredStreamLines as $videoIdOrUrl) {
            $videoId = $this->extractVideoId($ytdlp, $videoIdOrUrl, $cookiesFile);

            if (! $videoId) {
                $errors[] = 'Could not extract video ID from: '.substr($videoIdOrUrl, 0, 60);

                continue;
            }

            $existing = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->youtube_video_id', $videoId)
                ->first();

            $metadata = $this->fetchVideoMetadata($ytdlp, $videoId, $settings, $cookiesFile);

            if ($existing) {
                if ($metadata) {
                    $this->updateChannel($existing, $metadata, $settings, $userId);
                }
                $skipped++;

                continue;
            }

            if (! $metadata || ! $metadata['is_live']) {
                continue;
            }

            $channelNumber = $this->nextChannelNumber($userId, $settings, $metadata['youtube_handle'], null);

            try {
                $this->createChannel($metadata, $metadata['youtube_handle'], $settings, $userId, $channelNumber);
                $context->info("Added '{$metadata['title']}' as channel #{$channelNumber} (direct stream)");
                $added++;
            } catch (\Throwable $e) {
                $context->error("Failed to create channel for {$videoId}: {$e->getMessage()}");
                $errors[] = $e->getMessage();
            }
        }

        if ($settings['auto_cleanup'] ?? true) {
            $cleaned = $this->cleanupEndedChannels($ytdlp, $userId, $cookiesFile, $context, $settings);
        }

        $this->cleanupCookiesFile($cookiesFile);

        // Refresh expiring EPG programme windows and regenerate the XMLTV file.
        if ($settings['epg_enabled'] ?? false) {
            $epgSource = $this->ensureEpgSource($userId);
            $refreshed = $this->refreshEpgForActiveChannels($userId, $settings);
            $this->writeXmltvFile($userId, $epgSource);
            if ($refreshed > 0) {
                $context->info("EPG: extended programme window for {$refreshed} channel(s).");
            }
        }

        $parts = [];
        if ($added) {
            $parts[] = "{$added} channel(s) added";
        }
        if ($skipped) {
            $parts[] = "{$skipped} already tracked";
        }
        if ($cleaned) {
            $parts[] = "{$cleaned} ended channel(s) removed";
        }
        if (empty($parts)) {
            $parts[] = 'No changes';
        }

        $summary = implode(', ', $parts);
        if ($errors) {
            $summary .= '. Errors: '.implode('; ', array_slice($errors, 0, 3));
        }

        return PluginActionResult::success($summary, ['added' => $added, 'skipped' => $skipped, 'cleaned' => $cleaned]);
    }

    private function handleAddManual(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID. Ensure a Stream Profile is configured.');
        }

        $ytdlp = $this->findYtDlp();
        if (! $ytdlp) {
            return PluginActionResult::failure('yt-dlp binary not found.');
        }

        $rawUrls = trim($payload['manual_url'] ?? '');
        if (! $rawUrls) {
            return PluginActionResult::failure('No URL provided.');
        }

        $urls = array_filter(array_map('trim', preg_split('/[\n,]+/', $rawUrls)));
        if (empty($urls)) {
            return PluginActionResult::failure('No valid URLs found.');
        }

        $added = 0;
        $skipped = 0;
        $errors = [];
        $cookiesFile = $this->getCookiesFile($profile);

        foreach ($urls as $url) {
            $videoId = $this->extractVideoId($ytdlp, $url, $cookiesFile);

            if (! $videoId) {
                $errors[] = 'Could not extract video ID from: '.substr($url, 0, 60);

                continue;
            }

            $existing = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->youtube_video_id', $videoId)
                ->first();

            $metadata = $this->fetchVideoMetadata($ytdlp, $videoId, $settings, $cookiesFile);

            if ($existing) {
                $context->info("Video {$videoId} already tracked as channel #{$existing->channel} — updating.");
                if ($metadata) {
                    $this->updateChannel($existing, $metadata, $settings, $userId);
                }
                $skipped++;

                continue;
            }

            if (! $metadata) {
                $errors[] = "Failed to extract metadata for video {$videoId}";

                continue;
            }

            if (! $metadata['is_live']) {
                $errors[] = "Video {$videoId} is not currently live";

                continue;
            }

            $channelNumber = $this->nextChannelNumber($userId, $settings, $metadata['youtube_handle'], null);

            try {
                $this->createChannel($metadata, $metadata['youtube_handle'], $settings, $userId, $channelNumber);
                $context->info("Added '{$metadata['title']}' as channel #{$channelNumber}");
                $added++;
            } catch (\Throwable $e) {
                $context->error("Failed to create channel for {$videoId}: {$e->getMessage()}");
                $errors[] = $e->getMessage();
            }
        }

        $this->cleanupCookiesFile($cookiesFile);

        if ($added > 0 && ($settings['epg_enabled'] ?? false)) {
            $epgSource = $this->ensureEpgSource($userId);
            $this->writeXmltvFile($userId, $epgSource);
        }

        $parts = [];
        if ($added) {
            $parts[] = "{$added} stream(s) added";
        }
        if ($skipped) {
            $parts[] = "{$skipped} already tracked";
        }
        if ($errors) {
            $parts[] = count($errors).' failed';
        }

        $summary = implode(', ', $parts) ?: 'No streams processed';
        if ($errors && count($errors) <= 3) {
            $summary .= '. '.implode('; ', $errors);
        }

        return $added > 0
            ? PluginActionResult::success($summary)
            : PluginActionResult::failure($summary);
    }

    private function handleCleanup(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID.');
        }

        $ytdlp = $this->findYtDlp();
        if (! $ytdlp) {
            return PluginActionResult::failure('yt-dlp binary not found.');
        }

        $cookiesFile = $this->getCookiesFile($profile);
        $cleaned = $this->cleanupEndedChannels($ytdlp, $userId, $cookiesFile, $context, $settings);
        $this->cleanupCookiesFile($cookiesFile);

        if ($settings['epg_enabled'] ?? false) {
            $epgSource = $this->ensureEpgSource($userId);
            $this->writeXmltvFile($userId, $epgSource);
        }

        return PluginActionResult::success("Removed {$cleaned} ended channel(s).", ['cleaned' => $cleaned]);
    }

    private function handleResetAll(PluginExecutionContext $context): PluginActionResult
    {
        ['userId' => $userId] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID.');
        }

        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $count = $channels->count();

        foreach ($channels as $channel) {
            $this->removeEpgChannel($channel);
            $channel->delete();
        }

        // Remove the EPG source record and its XMLTV file.
        $epg = Epg::where('name', self::EPG_SOURCE_NAME)
            ->where('user_id', $userId)
            ->first();

        if ($epg) {
            Storage::disk('local')->deleteDirectory($epg->folder_path);
            $epg->delete();
        }

        $context->info("Deleted {$count} channel(s) created by YouTubearr.");

        return PluginActionResult::success("Reset complete — deleted {$count} channel(s).", ['deleted' => $count]);
    }

    // -------------------------------------------------------------------------
    // Channel lifecycle
    // -------------------------------------------------------------------------

    /**
     * Update the logo (and title in stream_title mode) on an existing plugin channel.
     *
     * Called when a tracked stream is still live but settings may have changed.
     */
    private function updateChannel(Channel $channel, array $metadata, array $settings, int $userId): void
    {
        $logoSource = $settings['channel_logo_source'] ?? 'stream_thumbnail';
        if ($logoSource === 'channel_avatar') {
            $avatarHandle = $metadata['youtube_handle'] ?? data_get($channel->info, 'youtube_handle', '');
            $logo = $this->fetchChannelAvatar($avatarHandle);
            // Only fall back to the stream thumbnail when avatar scraping succeeds
            // but returns an empty result — keep null rather than regressing to the
            // thumbnail, so we don't no-op an update that was previously applied.
            if ($logo === '') {
                $logo = null;
            }
        } else {
            $logo = $metadata['logo'] ?? '';
        }

        $changes = [];

        if ($logo !== null && $logo !== '' && $logo !== $channel->logo_internal) {
            $changes['logo_internal'] = $logo;
        }

        // In stream_title mode keep the title fresh (stream titles can change mid-broadcast).
        // In channel_name mode ensure the title follows the "ChannelName #N" format;
        // if it already matches (stable suffix) leave it alone, but if the channel was
        // previously tracked in stream_title mode it will still carry the old stream
        // title — in that case assign a new suffix so the rename takes effect.
        $mode = $settings['channel_name_mode'] ?? 'stream_title';
        if ($mode === 'channel_name') {
            $channelName = $metadata['youtube_channel_name'] ?? $metadata['title'];
            if (! preg_match('/^'.preg_quote($channelName, '/').' #\d+$/', $channel->title)) {
                $suffix = $this->nextChannelSuffix($userId, $channelName);
                $changes['title'] = "{$channelName} #{$suffix}";
            }
        } elseif ($metadata['title'] !== $channel->title) {
            $changes['title'] = $metadata['title'];
        }

        // Always keep stream_title in sync — it's the raw YouTube stream title used
        // as the EPG programme description, independent of channel_name_mode.
        $currentStreamTitle = data_get($channel->info, 'stream_title', '');
        if ($metadata['title'] !== $currentStreamTitle) {
            $info = $channel->info ?? [];
            $info['stream_title'] = $metadata['title'];
            $channel->info = $info;
            $channel->save();
        }

        if (! empty($changes)) {
            $channel->update($changes);
        }

        // Ensure EPG channel is linked and up-to-date if EPG is enabled.
        if ($settings['epg_enabled'] ?? false) {
            $epgSource = $this->ensureEpgSource($userId);
            $title = $changes['title'] ?? $channel->title;
            $logoForEpg = $changes['logo_internal'] ?? $channel->logo_internal ?? '';
            $epgChannel = $this->ensureEpgChannel($epgSource, $userId, $metadata['video_id'], $title, $logoForEpg);

            if ($channel->epg_channel_id !== $epgChannel->id) {
                $channel->update(['epg_channel_id' => $epgChannel->id]);

                $epgDays = max(1, (int) (($settings['epg_days'] ?? 3) ?: 3));
                $window = $this->epgProgrammeWindow($epgDays);
                $info = $channel->info ?? [];
                $info['epg_programme_start'] = $window['start'];
                $info['epg_programme_stop'] = $window['stop'];
                $channel->info = $info;
                $channel->save();
            }
        }
    }

    /**
     * @param  array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, logo: string, is_live: bool}  $metadata
     */
    private function createChannel(
        array $metadata,
        string $handle,
        array $settings,
        int $userId,
        int|float $channelNumber,
    ): Channel {
        $groupName = $settings['channel_group'] ?? 'YouTube Live';
        $profileId = (int) ($settings['stream_profile_id'] ?? 0);
        $playlistId = (int) ($settings['target_playlist_id'] ?? 0) ?: null;
        $customPlaylistId = (int) ($settings['target_custom_playlist_id'] ?? 0) ?: null;
        $epgEnabled = (bool) ($settings['epg_enabled'] ?? false);
        $epgDays = max(1, (int) (($settings['epg_days'] ?? 3) ?: 3));

        $title = $this->resolveChannelTitle($metadata, $settings, $userId);

        $logoSource = $settings['channel_logo_source'] ?? 'stream_thumbnail';
        if ($logoSource === 'channel_avatar') {
            $avatarHandle = $metadata['youtube_handle'] ?? ltrim($handle, '@');
            $logo = $this->fetchChannelAvatar($avatarHandle);
            if ($logo === '') {
                $logo = $metadata['logo'] ?? '';
            }
        } else {
            $logo = $metadata['logo'] ?? '';
        }

        $group = $playlistId
            ? Group::firstOrCreate(
                ['name' => $groupName, 'user_id' => $userId, 'playlist_id' => $playlistId],
                ['user_id' => $userId, 'playlist_id' => $playlistId],
            )
            : null;

        $customPlaylist = $customPlaylistId ? CustomPlaylist::find($customPlaylistId) : null;

        $groupTag = null;
        if ($customPlaylist) {
            $groupTag = $customPlaylist->groupTags()->where('name->en', $groupName)->first();
            if (! $groupTag) {
                $groupTag = Tag::create(['name' => ['en' => $groupName], 'type' => $customPlaylist->uuid]);
                $customPlaylist->attachTag($groupTag);
            }
        }

        $info = [
            'plugin' => self::PLUGIN_MARKER,
            'youtube_video_id' => $metadata['video_id'],
            'youtube_channel_id' => $metadata['youtube_channel_id'] ?? '',
            'youtube_channel_name' => $metadata['youtube_channel_name'] ?? '',
            'youtube_handle' => ltrim($handle, '@'),
            'stream_title' => $metadata['title'],
        ];

        $epgChannelId = null;
        if ($epgEnabled) {
            $epgSource = $this->ensureEpgSource($userId);
            $epgChannel = $this->ensureEpgChannel($epgSource, $userId, $metadata['video_id'], $title, $logo);
            $epgChannelId = $epgChannel->id;

            $window = $this->epgProgrammeWindow($epgDays);
            $info['epg_programme_start'] = $window['start'];
            $info['epg_programme_stop'] = $window['stop'];
        }

        $channel = Channel::create([
            'uuid' => Str::orderedUuid()->toString(),
            'title' => $title,
            'url' => "https://www.youtube.com/watch?v={$metadata['video_id']}",
            'channel' => (int) $channelNumber,
            'sort' => (float) $channelNumber,
            'is_custom' => true,
            'is_vod' => false,
            'enabled' => true,
            'shift' => 0,
            'logo_internal' => $logo,
            'logo_type' => 'channel',
            'enable_proxy' => true,
            'user_id' => $userId,
            'group_id' => $group?->id,
            'stream_profile_id' => $profileId ?: null,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylist?->id,
            'epg_channel_id' => $epgChannelId,
            'info' => $info,
        ]);

        if ($customPlaylist) {
            $channel->customPlaylists()->syncWithoutDetaching([$customPlaylist->id]);
            if ($groupTag) {
                $channel->attachTag($groupTag);
            }
        }

        return $channel;
    }

    private function cleanupEndedChannels(
        string $ytdlp,
        int $userId,
        ?string $cookiesFile,
        PluginExecutionContext $context,
        array $settings = [],
        int $graceMinutes = 5,
    ): int {
        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $cleaned = 0;
        $deletedChannelNames = [];

        foreach ($channels as $channel) {
            $videoId = data_get($channel->info, 'youtube_video_id');
            if (! $videoId) {
                continue;
            }

            // Skip channels that were just created — they may have been added
            // earlier in this same sync pass and their yt-dlp check hasn't settled yet.
            if ($channel->created_at && $channel->created_at->gt(now()->subMinutes($graceMinutes))) {
                $context->info("Skipping recently added channel #{$channel->channel} '{$channel->title}' (grace period).");

                continue;
            }

            if (! $this->isVideoStillLive($ytdlp, $videoId, $cookiesFile, $context)) {
                $context->info("Stream ended for video {$videoId} (channel #{$channel->channel} '{$channel->title}') — removing.");

                $channelName = data_get($channel->info, 'youtube_channel_name');

                $this->removeEpgChannel($channel);
                $channel->delete();
                $cleaned++;

                if ($channelName && ($settings['channel_name_mode'] ?? 'stream_title') === 'channel_name') {
                    $deletedChannelNames[] = $channelName;
                }
            }
        }

        // Compact #N suffixes for each affected YouTube channel name.
        foreach (array_unique($deletedChannelNames) as $channelName) {
            $this->renumberChannelSiblings($userId, $channelName);
        }

        return $cleaned;
    }

    // -------------------------------------------------------------------------
    // Channel naming
    // -------------------------------------------------------------------------

    /**
     * Resolve the display title for a new channel.
     *
     * In `stream_title` mode (default) the raw stream title is used unchanged.
     * In `channel_name` mode the YouTube channel name is used with a sequential
     * `#N` suffix to distinguish concurrent streams from the same channel.
     *
     * @param  array{title: string, youtube_channel_name: string}  $metadata
     */
    private function resolveChannelTitle(array $metadata, array $settings, int $userId): string
    {
        $mode = $settings['channel_name_mode'] ?? 'stream_title';

        if ($mode !== 'channel_name') {
            return $metadata['title'];
        }

        $channelName = $metadata['youtube_channel_name'] ?? $metadata['title'];
        $suffix = $this->nextChannelSuffix($userId, $channelName);

        return "{$channelName} #{$suffix}";
    }

    /**
     * Return the lowest unused integer suffix for channels from a given YouTube channel name.
     */
    private function nextChannelSuffix(int $userId, string $channelName): int
    {
        $titles = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->youtube_channel_name', $channelName)
            ->pluck('title')
            ->all();

        $used = [];
        foreach ($titles as $title) {
            if (preg_match('/#(\d+)$/', $title, $m)) {
                $used[] = (int) $m[1];
            }
        }

        for ($i = 1; ; $i++) {
            if (! in_array($i, $used, true)) {
                return $i;
            }
        }
    }

    /**
     * After a stream ends, compact the `#N` suffixes of the remaining sibling
     * channels from the same YouTube channel so there are no gaps.
     *
     * Example: NASA #1, NASA #3 → NASA #1, NASA #2.
     */
    private function renumberChannelSiblings(int $userId, string $channelName): void
    {
        $siblings = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereJsonContains('info->youtube_channel_name', $channelName)
            ->orderBy('channel')
            ->get();

        $idx = 1;
        foreach ($siblings as $sibling) {
            $newTitle = preg_replace('/#\d+$/', "#{$idx}", $sibling->title);

            if ($newTitle !== $sibling->title) {
                $sibling->title = $newTitle;
                $sibling->save();

                // Keep the EPG channel display name in sync.
                if ($sibling->epg_channel_id) {
                    $epgChannel = EpgChannel::find($sibling->epg_channel_id);
                    if ($epgChannel) {
                        $epgChannel->display_name = $newTitle;
                        $epgChannel->save();
                    }
                }
            }

            $idx++;
        }
    }

    // -------------------------------------------------------------------------
    // Channel logo
    // -------------------------------------------------------------------------

    /**
     * Fetch the YouTube channel's avatar (profile picture) URL.
     *
     * Results are cached per handle in `$this->avatarCache` for the duration of
     * the current plugin run. Returns an empty string on failure so the caller
     * can fall back to the stream thumbnail.
     */
    private function fetchChannelAvatar(string $handle): string
    {
        $handle = ltrim($handle, '@');

        if (array_key_exists($handle, $this->avatarCache)) {
            return $this->avatarCache[$handle];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get("https://www.youtube.com/@{$handle}");

            if (! $response->successful()) {
                return $this->avatarCache[$handle] = '';
            }

            $html = $response->body();

            // og:image on a channel page is the channel avatar. YouTube now serves
            // these from yt3.googleusercontent.com (previously yt3.ggpht.com).
            // Accept any URL from the og:image meta tag rather than hard-coding the CDN.
            if (preg_match('/<meta property="og:image"\s+content="([^"]+)"/', $html, $m)) {
                return $this->avatarCache[$handle] = $m[1];
            }

            // Fallback: JSON avatar object embedded in the page source.
            // Matches both yt3.ggpht.com and yt3.googleusercontent.com.
            if (preg_match('/"avatar":\{"thumbnails":\[\{"url":"(https:\/\/yt3\.(?:ggpht|googleusercontent)\.com\/[^"]+)"/', $html, $m)) {
                return $this->avatarCache[$handle] = $m[1];
            }
        } catch (\Throwable) {
            // Silently swallow — network errors should not interrupt a monitoring cycle.
        }

        return $this->avatarCache[$handle] = '';
    }

    // -------------------------------------------------------------------------
    // EPG management
    // -------------------------------------------------------------------------

    /**
     * Return the programme window that every managed channel should carry.
     *
     * Start is always 00:00 today so EPG viewers show a clean grid from
     * midnight regardless of when the sync runs. Stop is N days later.
     *
     * @return array{start: string, stop: string}
     */
    private function epgProgrammeWindow(int $days): array
    {
        $start = Carbon::today();

        return [
            'start' => $start->toIso8601String(),
            'stop' => $start->copy()->addDays($days)->toIso8601String(),
        ];
    }

    /**
     * Find or create the shared `Epg` record for this plugin.
     * The result is cached on `$this->epgSource` for the duration of the run.
     */
    private function ensureEpgSource(int $userId): Epg
    {
        if ($this->epgSource !== null) {
            return $this->epgSource;
        }

        $this->epgSource = Epg::firstOrCreate(
            ['name' => self::EPG_SOURCE_NAME, 'user_id' => $userId],
            [
                // A dummy HTTP url is used so EpgGenerateController falls back to reading
                // $epg->file_path from local storage (its processEpgWithXmlReader() checks
                // str_starts_with($epg->url, 'http') and then reads the local file).
                // We set auto_sync=false + synced=now() so ProcessEpgImport exits immediately
                // without trying to HTTP GET this url.
                'url' => 'https://youtubearr.local/epg',
                'source_type' => EpgSourceType::URL,
                'status' => Status::Pending,
                'is_cached' => false,
                'auto_sync' => false,
                'synced' => now(),
            ],
        );

        // If the record already exists (firstOrCreate found it) with a file on disk
        // but the cache has never been built, dispatch the cache job now so the
        // EPG viewer works without needing the user to hit "Generate Cache" manually.
        if (! $this->epgSource->wasRecentlyCreated
            && ! $this->epgSource->is_cached
            && ! $this->epgSource->processing
            && Storage::disk('local')->exists($this->epgSource->file_path)
        ) {
            GenerateEpgCache::dispatch($this->epgSource->uuid);
        }

        return $this->epgSource;
    }

    /**
     * Find or create an `EpgChannel` for a specific video, updating its
     * display name and icon if it already exists.
     */
    private function ensureEpgChannel(Epg $epg, int $userId, string $videoId, string $title, string $logoUrl): EpgChannel
    {
        return EpgChannel::updateOrCreate(
            [
                'epg_id' => $epg->id,
                'channel_id' => $videoId,
                'name' => $videoId,
                'user_id' => $userId,
            ],
            [
                'display_name' => $title,
                'icon' => $logoUrl ?: null,
            ],
        );
    }

    /**
     * Delete the `EpgChannel` linked to a channel if no other channels reference it.
     */
    private function removeEpgChannel(Channel $channel): void
    {
        $epgChannelId = $channel->epg_channel_id ?? null;
        if (! $epgChannelId) {
            return;
        }

        $othersCount = Channel::where('epg_channel_id', $epgChannelId)
            ->where('id', '!=', $channel->id)
            ->count();

        if ($othersCount === 0) {
            EpgChannel::find($epgChannelId)?->delete();
        }
    }

    /**
     * Write a fresh XMLTV file to local storage for the given EPG source.
     *
     * All plugin channels with an `epg_channel_id` are included. Each channel
     * gets a `<channel>` block and a single `<programme>` block that spans the
     * window stored in `channel.info` (`epg_programme_start` / `epg_programme_stop`).
     */
    private function writeXmltvFile(int $userId, Epg $epg): void
    {
        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereNotNull('epg_channel_id')
            ->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<!DOCTYPE tv SYSTEM "xmltv.dtd">'."\n";
        $xml .= '<tv generator-info-name="YouTubearr">'."\n";

        foreach ($channels as $channel) {
            $videoId = data_get($channel->info, 'youtube_video_id');
            if (! $videoId) {
                continue;
            }

            $displayName = htmlspecialchars($channel->title, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $logo = htmlspecialchars($channel->logo_internal ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8');

            $xml .= "  <channel id=\"{$videoId}\">\n";
            $xml .= "    <display-name>{$displayName}</display-name>\n";
            if ($logo !== '') {
                $xml .= "    <icon src=\"{$logo}\" />\n";
            }
            $xml .= "  </channel>\n";
        }

        foreach ($channels as $channel) {
            $videoId = data_get($channel->info, 'youtube_video_id');
            $start = data_get($channel->info, 'epg_programme_start');
            $stop = data_get($channel->info, 'epg_programme_stop');

            if (! $videoId || ! $start || ! $stop) {
                continue;
            }

            $startFmt = Carbon::parse($start)->format('YmdHis O');
            $stopFmt = Carbon::parse($stop)->format('YmdHis O');
            $title = htmlspecialchars($channel->title, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $desc = htmlspecialchars(
                data_get($channel->info, 'stream_title', $channel->title),
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            );

            $xml .= "  <programme start=\"{$startFmt}\" stop=\"{$stopFmt}\" channel=\"{$videoId}\">\n";
            $xml .= "    <title>{$title}</title>\n";
            $xml .= "    <desc lang=\"en\">{$desc}</desc>\n";
            $xml .= "  </programme>\n";
        }

        $xml .= '</tv>'."\n";

        Storage::disk('local')->put($epg->file_path, $xml);

        // Count channels and programmes that made it into the file so the
        // UI can show accurate channel_count / programme_count without
        // needing to dispatch ProcessEpgImport (which would conflict with
        // the plugin's own EpgChannel management).
        $channelCount = 0;
        $programmeCount = 0;
        foreach ($channels as $channel) {
            $videoId = data_get($channel->info, 'youtube_video_id');
            if (! $videoId) {
                continue;
            }
            $channelCount++;

            $start = data_get($channel->info, 'epg_programme_start');
            $stop = data_get($channel->info, 'epg_programme_stop');
            if ($start && $stop) {
                $programmeCount++;
            }
        }

        $epg->update([
            'channel_count' => $channelCount,
            'programme_count' => $programmeCount,
            'synced' => now(),
            'status' => Status::Completed,
            'errors' => null,
        ]);

        // Rebuild the EPG viewer cache from the freshly-written XMLTV file.
        // GenerateEpgCache reads from $epg->file_path (because url starts with
        // https://) — exactly where we just wrote — so no ProcessEpgImport is
        // needed and EpgChannel records are left untouched.
        GenerateEpgCache::dispatch($epg->uuid);
    }

    /**
     * Ensure every EPG-linked channel's programme window covers today 00:00
     * through today + N days. Called on every monitoring cycle so the window
     * rolls forward automatically each day.
     *
     * Returns the count of channels whose window was updated.
     */
    private function refreshEpgForActiveChannels(int $userId, array $settings): int
    {
        $epgDays = max(1, (int) (($settings['epg_days'] ?? 3) ?: 3));
        $window = $this->epgProgrammeWindow($epgDays);

        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->whereNotNull('epg_channel_id')
            ->get();

        $refreshed = 0;

        foreach ($channels as $channel) {
            // Skip if the window is already correct (idempotent during the same day).
            if (data_get($channel->info, 'epg_programme_start') === $window['start']
                && data_get($channel->info, 'epg_programme_stop') === $window['stop']
            ) {
                continue;
            }

            $info = $channel->info ?? [];
            $info['epg_programme_start'] = $window['start'];
            $info['epg_programme_stop'] = $window['stop'];
            $channel->info = $info;
            $channel->save();

            $refreshed++;
        }

        return $refreshed;
    }

    // -------------------------------------------------------------------------
    // Channel numbering
    // -------------------------------------------------------------------------

    private function nextChannelNumber(int $userId, array $settings, string $handle, ?int $baseNumber): int|float
    {
        $mode = $settings['channel_numbering_mode'] ?? 'sequential';
        $increment = (int) ($settings['channel_number_increment'] ?? 1);
        $starting = (int) ($settings['starting_channel_number'] ?? 2000);

        if ($mode === 'decimal' && $baseNumber !== null) {
            $sibling = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->youtube_handle', ltrim($handle, '@'))
                ->orderByDesc('channel')
                ->first();

            if ($sibling) {
                $current = (float) $sibling->channel;
                $decimal = fmod($current, 1.0);
                $sub = (int) round($decimal * 10) + 1;

                return round($baseNumber + ($sub / 10), 1);
            }

            return round($baseNumber + 0.1, 1);
        }

        $last = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->orderByDesc('channel')
            ->value('channel');

        if ($last === null) {
            return $starting;
        }

        return (int) $last + $increment;
    }

    // -------------------------------------------------------------------------
    // yt-dlp integration
    // -------------------------------------------------------------------------

    /**
     * Fetch all currently live streams for a YouTube channel handle by scanning
     * the channel's /streams playlist tab with --flat-playlist.
     *
     * Returns an empty array if the channel has no live streams or yt-dlp fails.
     *
     * @return list<array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, youtube_handle: string, logo: string, is_live: bool}>
     */
    private function fetchAllLiveStreams(string $ytdlp, string $handle, array $settings, ?string $cookiesFile): array
    {
        $handle = ltrim($handle, '@');
        $url = "https://www.youtube.com/@{$handle}/streams";

        $cmd = [$ytdlp, '--flat-playlist', '--dump-json', '--no-download', '--no-warnings'];

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = $url;

        $result = $this->runProcess($cmd, 60);

        if ($result['exit'] !== 0 || trim($result['stdout']) === '') {
            return [];
        }

        $streams = [];

        foreach (explode("\n", trim($result['stdout'])) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }

            $isLive = ($entry['live_status'] ?? '') === 'is_live' || ($entry['is_live'] ?? false);
            if (! $isLive) {
                continue;
            }

            $videoId = $entry['id'] ?? '';
            if ($videoId === '') {
                continue;
            }

            $streams[] = [
                'video_id' => $videoId,
                'title' => $entry['title'] ?? 'Untitled',
                'youtube_channel_id' => $entry['channel_id'] ?? '',
                'youtube_channel_name' => $entry['channel'] ?? $entry['uploader'] ?? $handle,
                'youtube_handle' => $handle,
                'logo' => $this->extractBestThumbnail($entry),
                'is_live' => true,
            ];
        }

        return $streams;
    }

    /**
     * Fetch metadata for a specific video ID.
     *
     * @return array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, youtube_handle: string, logo: string, is_live: bool}|null
     */
    private function fetchVideoMetadata(string $ytdlp, string $videoId, array $settings, ?string $cookiesFile): ?array
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";
        $format = $this->qualityToFormat($settings['stream_quality'] ?? 'best');

        $info = $this->runYtDlpJson($ytdlp, $url, $cookiesFile, $format, 45);

        if (! $info) {
            return null;
        }

        $isLive = ($info['is_live'] ?? false) || ($info['live_status'] ?? '') === 'is_live';
        $handle = $info['uploader_id'] ?? $info['channel_id'] ?? '';

        return [
            'video_id' => $info['id'] ?? $videoId,
            'title' => $info['title'] ?? 'Untitled',
            'youtube_channel_id' => $info['channel_id'] ?? '',
            'youtube_channel_name' => $info['channel'] ?? $info['uploader'] ?? '',
            'youtube_handle' => ltrim((string) $handle, '@'),
            'logo' => $info['thumbnail'] ?? $this->extractBestThumbnail($info),
            'is_live' => $isLive,
        ];
    }

    private function isVideoStillLive(string $ytdlp, string $videoId, ?string $cookiesFile, ?PluginExecutionContext $context = null): bool
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";

        $cmd = [$ytdlp, '--dump-json', '--no-download', '--no-warnings'];

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = $url;

        $result = $this->runProcess($cmd, 30);

        // Any non-clean exit (signal, timeout, network error, rate-limit) is treated as
        // "unable to confirm" — we assume the stream is still live to avoid false removal.
        // Only delete when yt-dlp exits cleanly (0) and JSON positively shows not-live.
        if ($result['exit'] !== 0 || empty($result['stdout'])) {
            if ($context && $result['exit'] !== 0) {
                $context->info("Could not confirm live status for {$videoId} (exit {$result['exit']}) — assuming still live.");
            }

            return true;
        }

        $info = json_decode($result['stdout'], true);

        if (! is_array($info)) {
            return true;
        }

        return ($info['is_live'] ?? false) || ($info['live_status'] ?? '') === 'is_live';
    }

    /**
     * Extract a YouTube video ID from a URL.
     * Falls back to yt-dlp if regex matching fails.
     */
    private function extractVideoId(string $ytdlp, string $url, ?string $cookiesFile): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/live\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        // Bare 11-character video ID (no URL)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($url))) {
            return trim($url);
        }

        // Fallback: ask yt-dlp to print the ID
        $cmd = [$ytdlp, '--print', 'id', '--no-download', '--no-warnings'];

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = $url;

        $result = $this->runProcess($cmd, 30);

        if ($result['exit'] === 0) {
            $id = trim($result['stdout']);
            if (strlen($id) === 11) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Run yt-dlp --dump-json on a URL and return the parsed JSON array, or null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function runYtDlpJson(string $ytdlp, string $url, ?string $cookiesFile, ?string $format = null, int $timeout = 45): ?array
    {
        $cmd = [$ytdlp, '--dump-json', '--no-download', '--no-warnings'];

        if ($format !== null) {
            $cmd[] = '--format';
            $cmd[] = $format;
        }

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = $url;

        $result = $this->runProcess($cmd, $timeout);

        if ($result['exit'] !== 0 || empty($result['stdout'])) {
            return null;
        }

        return json_decode($result['stdout'], true) ?: null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the monitored_channels textarea into structured entries.
     *
     * Supports:
     *
     *   @handle
     *
     *   @handle=BaseNumber
     *
     *   @handle=BaseNumber:TitleFilter
     *
     * @return list<array{handle: string, base_number: int|null, title_filter: string|null}>
     */
    private function parseMonitoredChannels(string $raw): array
    {
        $entries = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $handle = null;
            $baseNumber = null;
            $titleFilter = null;

            if (preg_match('/^(@[\w.-]+)(?:=(\d+)(?::(.+))?)?$/', $line, $m)) {
                $handle = ltrim($m[1], '@');
                $baseNumber = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
                $titleFilter = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
            } else {
                $handle = ltrim($line, '@');
            }

            if ($handle) {
                $entries[] = [
                    'handle' => $handle,
                    'base_number' => $baseNumber,
                    'title_filter' => $titleFilter,
                ];
            }
        }

        return $entries;
    }

    /**
     * Parse the monitored_streams textarea into a list of video IDs or URLs.
     *
     * Each non-blank, non-comment line is treated as either:
     *   - a full YouTube URL (youtube.com/watch?v=..., youtu.be/..., etc.)
     *   - a bare 11-character video ID
     *
     * @return list<string>
     */
    private function parseMonitoredStreams(string $raw): array
    {
        $entries = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = $line;
        }

        return $entries;
    }

    /**
     * Resolve the user ID and StreamProfile for this plugin run in a single DB query.
     *
     * For manual/hook triggers the context user is set directly.
     * For scheduled runs the user is null, so the owner is derived from the configured StreamProfile.
     *
     * @return array{userId: int|null, profile: StreamProfile|null}
     */
    private function resolveContext(PluginExecutionContext $context): array
    {
        $profile = $this->loadStreamProfile($context->settings);
        $userId = $context->user?->id ?? $profile?->user_id;

        return ['userId' => $userId, 'profile' => $profile];
    }

    private function loadStreamProfile(array $settings): ?StreamProfile
    {
        $profileId = (int) ($settings['stream_profile_id'] ?? 0);

        return $profileId ? StreamProfile::find($profileId) : null;
    }

    /**
     * Write cookies content from a StreamProfile to a temp file.
     * Returns the path, or null if no cookies are configured.
     */
    private function getCookiesFile(?StreamProfile $profile): ?string
    {
        if (! $profile || empty($profile->cookies)) {
            return null;
        }

        $content = trim($profile->cookies);
        if ($content === '') {
            return null;
        }

        try {
            $path = tempnam(sys_get_temp_dir(), 'youtubearr_cookies_').'.txt';
            file_put_contents($path, $content."\n");

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanupCookiesFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Extract the best-quality thumbnail URL from a yt-dlp JSON entry.
     *
     * Full metadata provides a scalar `thumbnail` key. Flat-playlist output
     * provides a `thumbnails` array of objects with `url`, `height`, `width`.
     * We sort by height (or width) descending and return the first URL.
     */
    private function extractBestThumbnail(array $entry): string
    {
        // Full metadata: scalar thumbnail key is reliable
        if (! empty($entry['thumbnail']) && is_string($entry['thumbnail'])) {
            return $entry['thumbnail'];
        }

        // Flat-playlist: thumbnails array
        $thumbnails = $entry['thumbnails'] ?? [];
        if (! is_array($thumbnails) || empty($thumbnails)) {
            return '';
        }

        usort($thumbnails, fn ($a, $b) => ($b['height'] ?? $b['width'] ?? 0) <=> ($a['height'] ?? $a['width'] ?? 0));

        return $thumbnails[0]['url'] ?? '';
    }

    private function qualityToFormat(string $quality): string
    {
        return match ($quality) {
            '1080p' => 'bestvideo[height<=1080]+bestaudio/best[height<=1080]/best',
            '720p' => 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
            '480p' => 'bestvideo[height<=480]+bestaudio/best[height<=480]/best',
            default => 'best',
        };
    }

    private function findYtDlp(): ?string
    {
        $candidates = ['yt-dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', '/opt/venv/bin/yt-dlp'];

        foreach ($candidates as $candidate) {
            $result = $this->runProcess(['which', $candidate], 5);
            if ($result['exit'] === 0 && trim($result['stdout'])) {
                return trim($result['stdout']);
            }

            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run a subprocess and return ['exit' => int, 'stdout' => string, 'stderr' => string].
     *
     * @param  list<string>  $cmd
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $cmd, int $timeoutSeconds = 30): array
    {
        try {
            $result = Process::timeout($timeoutSeconds)->run($cmd);

            return [
                'exit' => $result->exitCode() ?? -1,
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        } catch (\Throwable $e) {
            // Catches ProcessSignaledException (e.g. SIGINT from Horizon graceful shutdown),
            // ProcessTimedOutException, and any other unexpected errors.
            return ['exit' => -1, 'stdout' => '', 'stderr' => $e->getMessage()];
        }
    }
}
