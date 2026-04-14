<?php

namespace AppLocalPlugins\Youtubearr;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\StreamProfile;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

class Plugin implements ChannelProcessorPluginInterface, PluginInterface, ScheduledPluginInterface
{
    private const PLUGIN_MARKER = 'youtubearr';

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
        if (empty($channelLines)) {
            return PluginActionResult::failure('No channels configured. Add YouTube channel handles to the Monitored Channels setting.');
        }

        $added = 0;
        $skipped = 0;
        $cleaned = 0;
        $errors = [];
        $cookiesFile = $this->getCookiesFile($profile);

        foreach ($channelLines as $entry) {
            $handle = $entry['handle'];
            $baseNumber = $entry['base_number'];
            $titleFilter = $entry['title_filter'];

            $context->info("Checking @{$handle} for live streams…");

            $metadata = $this->fetchChannelLiveMetadata($ytdlp, $handle, $settings, $cookiesFile);

            if (! $metadata) {
                $context->info("@{$handle}: not currently live");

                continue;
            }

            if ($titleFilter && ! preg_match('/'.$titleFilter.'/i', $metadata['title'])) {
                $context->info("@{$handle}: live but title '{$metadata['title']}' does not match filter '{$titleFilter}'");

                continue;
            }

            $videoId = $metadata['video_id'];

            $existing = Channel::where('user_id', $userId)
                ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
                ->whereJsonContains('info->youtube_video_id', $videoId)
                ->first();

            if ($existing) {
                $context->info("@{$handle}: already tracked as channel #{$existing->channel}");
                $skipped++;

                continue;
            }

            $channelNumber = $this->nextChannelNumber($userId, $settings, $handle, $baseNumber);

            try {
                $this->createChannel($metadata, $handle, $settings, $userId, $channelNumber);
                $context->info("@{$handle}: added '{$metadata['title']}' as channel #{$channelNumber}");
                $added++;
            } catch (\Throwable $e) {
                $context->error("@{$handle}: failed to create channel — {$e->getMessage()}");
                $errors[] = "@{$handle}: {$e->getMessage()}";
            }
        }

        if ($settings['auto_cleanup'] ?? true) {
            $cleaned = $this->cleanupEndedChannels($ytdlp, $userId, $cookiesFile, $context);
        }

        $this->cleanupCookiesFile($cookiesFile);

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

            if ($existing) {
                $context->info("Video {$videoId} already tracked as channel #{$existing->channel}");
                $skipped++;

                continue;
            }

            $metadata = $this->fetchVideoMetadata($ytdlp, $videoId, $settings, $cookiesFile);

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
        ['userId' => $userId, 'profile' => $profile] = $this->resolveContext($context);

        if (! $userId) {
            return PluginActionResult::failure('Could not determine user ID.');
        }

        $ytdlp = $this->findYtDlp();
        if (! $ytdlp) {
            return PluginActionResult::failure('yt-dlp binary not found.');
        }

        $cookiesFile = $this->getCookiesFile($profile);
        $cleaned = $this->cleanupEndedChannels($ytdlp, $userId, $cookiesFile, $context);
        $this->cleanupCookiesFile($cookiesFile);

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
        $channels->each(fn (Channel $channel) => $channel->delete());

        $context->info("Deleted {$count} channel(s) created by YouTubearr.");

        return PluginActionResult::success("Reset complete — deleted {$count} channel(s).", ['deleted' => $count]);
    }

    // -------------------------------------------------------------------------
    // Channel lifecycle
    // -------------------------------------------------------------------------

    /**
     * @param  array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, is_live: bool}  $metadata
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

        $channel = Channel::create([
            'uuid' => Str::orderedUuid()->toString(),
            'title' => $metadata['title'],
            'url' => "https://www.youtube.com/watch?v={$metadata['video_id']}",
            'channel' => (int) $channelNumber,
            'sort' => (float) $channelNumber,
            'is_custom' => true,
            'is_vod' => false,
            'enabled' => true,
            'shift' => 0,
            'logo_type' => 'channel',
            'enable_proxy' => true,
            'user_id' => $userId,
            'group_id' => $group?->id,
            'stream_profile_id' => $profileId ?: null,
            'playlist_id' => $playlistId,
            'custom_playlist_id' => $playlistId ? null : $customPlaylist?->id,
            'info' => [
                'plugin' => self::PLUGIN_MARKER,
                'youtube_video_id' => $metadata['video_id'],
                'youtube_channel_id' => $metadata['youtube_channel_id'] ?? '',
                'youtube_channel_name' => $metadata['youtube_channel_name'] ?? '',
                'youtube_handle' => ltrim($handle, '@'),
            ],
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
    ): int {
        /** @var Collection<int, Channel> $channels */
        $channels = Channel::where('user_id', $userId)
            ->whereJsonContains('info->plugin', self::PLUGIN_MARKER)
            ->get();

        $cleaned = 0;

        foreach ($channels as $channel) {
            $videoId = data_get($channel->info, 'youtube_video_id');
            if (! $videoId) {
                continue;
            }

            if (! $this->isVideoStillLive($ytdlp, $videoId, $cookiesFile)) {
                $context->info("Stream ended for video {$videoId} (channel #{$channel->channel} '{$channel->title}') — removing.");
                $channel->delete();
                $cleaned++;
            }
        }

        return $cleaned;
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
     * Fetch live stream metadata for a YouTube channel's /live URL.
     *
     * @return array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, is_live: bool}|null
     */
    private function fetchChannelLiveMetadata(string $ytdlp, string $handle, array $settings, ?string $cookiesFile): ?array
    {
        $handle = ltrim($handle, '@');
        $url = "https://www.youtube.com/@{$handle}/live";
        $format = $this->qualityToFormat($settings['stream_quality'] ?? 'best');

        $info = $this->runYtDlpJson($ytdlp, $url, $cookiesFile, $format, 45);

        if (! $info) {
            return null;
        }

        $isLive = ($info['is_live'] ?? false) || ($info['live_status'] ?? '') === 'is_live';
        if (! $isLive) {
            return null;
        }

        return [
            'video_id' => $info['id'] ?? '',
            'title' => $info['title'] ?? 'Untitled',
            'youtube_channel_id' => $info['channel_id'] ?? '',
            'youtube_channel_name' => $info['channel'] ?? $info['uploader'] ?? $handle,
            'youtube_handle' => $handle,
            'is_live' => true,
        ];
    }

    /**
     * Fetch metadata for a specific video ID.
     *
     * @return array{video_id: string, title: string, youtube_channel_id: string, youtube_channel_name: string, youtube_handle: string, is_live: bool}|null
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
            'is_live' => $isLive,
        ];
    }

    private function isVideoStillLive(string $ytdlp, string $videoId, ?string $cookiesFile): bool
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";
        $info = $this->runYtDlpJson($ytdlp, $url, $cookiesFile, timeout: 30);

        if (! $info) {
            return false;
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
        $result = Process::timeout($timeoutSeconds)->run($cmd);

        return [
            'exit' => $result->exitCode() ?? -1,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    }
}
