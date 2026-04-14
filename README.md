# YouTubearr Plugin

An official plugin for [m3u-editor](https://github.com/m3ue/m3u-editor) that monitors YouTube channels for active livestreams and automatically creates or removes custom channels — zero API quota required.

Inspired by the [Dispatcharr YouTubearr plugin](https://github.com/Dispatcharr/Dispatcharr).

## How it works

- Uses `yt-dlp` (already bundled in the m3u-editor Docker image) to check each configured channel's `/live` URL for an active stream
- When a stream goes live, a custom channel is created pointing to the permanent YouTube watch URL (`youtube.com/watch?v=ID`)
- The m3u-proxy re-resolves the stream fresh on each connection via the yt-dlp backend — no expiring HLS URLs to refresh
- When a stream ends (detected on the next check), the channel is deleted automatically (if `auto_cleanup` is enabled)
- Channels are tracked implicitly via the `info` JSON field — no custom database tables required

## Requirements

- m3u-editor with plugin support enabled
- `yt-dlp` installed (included in the official Docker image)
- A **yt-dlp Stream Profile** configured in m3u-editor (used both for stream detection and proxy playback)

## Installation

Install via the m3u-editor Plugins page using the latest GitHub release, or via Artisan:

```bash
php artisan plugins:stage-github-release \
  https://github.com/m3ue/youtubearr-plugin/releases/download/v1.0.0/youtubearr-v1.0.0.zip \
  --sha256=<checksum>
```

Once staged, approve the install review in the UI and enable the plugin.

## Settings

| Setting | Default | Description |
|---|---|---|
| `monitored_channels` | — | One channel per line. Formats: `@handle`, `@handle=BaseNumber`, `@handle=BaseNumber:TitleFilter` |
| `stream_profile_id` | — | **Required.** A yt-dlp Stream Profile. Cookies on this profile are used for both stream detection and proxy playback. |
| `target_playlist_id` | — | Standard playlist to associate created channels with. Leave empty to skip. |
| `target_custom_playlist_id` | — | Custom playlist to add created channels to. Leave empty to skip. |
| `channel_group` | `YouTube Live` | Group name assigned to created channels. |
| `stream_quality` | `best` | Quality hint passed to yt-dlp during stream detection (`best`, `1080p`, `720p`, `480p`). |
| `auto_cleanup` | `true` | Delete channels automatically when their livestream ends. |
| `starting_channel_number` | `2000` | First channel number assigned. Each new stream increments from here. |
| `channel_number_increment` | `1` | Amount to increment for each new sequential channel. |
| `channel_numbering_mode` | `sequential` | `sequential` (2000, 2001, …) or `decimal` (90.1, 90.2, …). Decimal groups streams from the same channel together when `@handle=BaseNumber` is set. |
| `schedule_enabled` | `false` | Run monitoring automatically on the cron schedule below. |
| `schedule_cron` | `*/15 * * * *` | Cron expression for automatic monitoring (default: every 15 minutes). |

### Monitored channels format

```
# Simple handle
@NASA

# With base channel number (used in decimal numbering mode)
@RyanHallYall=90

# With base number and title filter (case-insensitive regex)
@RyanHallYall=90:Storm|Weather

# Multiple channels
@NASA=92
@SpaceX=93
@NWS=94:Warning|Watch
```

## Actions

| Action | Description |
|---|---|
| `check_now` | Check all monitored channels for active livestreams. Adds new channels; optionally cleans up ended ones. |
| `add_manual` | Add a specific YouTube livestream URL (or comma/newline-separated list) as a channel immediately. |
| `cleanup` | Check all plugin-created channels and remove any whose stream has ended. |
| `reset_all` | Delete **all** channels created by this plugin for your account. |

## Cookies for authenticated streams

Some YouTube streams (members-only, age-gated content) require authentication cookies. Configure cookies once on your yt-dlp Stream Profile — they are automatically used for both stream detection metadata calls and proxy playback.

To export cookies, use a browser extension like **"Get cookies.txt LOCALLY"** and paste the Netscape-format content into the Stream Profile's **Cookies** field.

## Automatic scheduling

Enable `schedule_enabled` and set a cron expression in `schedule_cron`. The `plugins:run-scheduled` artisan command (runs every minute via the scheduler) will trigger `check_now` when the cron expression is due.

## Releasing

```bash
bash scripts/package-plugin.sh
```

Update the SHA-256 checksum in the release notes whenever the zip changes.

## License

MIT
