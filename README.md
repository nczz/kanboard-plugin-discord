Discord plugin for Kanboard
===========================

Send rich [Discord](https://discord.com/) notification cards for Kanboard
project events, and **@mention the Discord users** mapped to the task assignee
or the person mentioned in a comment.

What makes this plugin different
--------------------------------

Existing Kanboard Discord plugins only forward events to a channel. This plugin
adds **Discord user mentions**: when a task is assigned to someone (or someone
is @mentioned in Kanboard), the matching Discord user gets a real ping — as long
as they have mapped their Discord User ID in their Kanboard profile.

Features
--------

- Per-project routing: each project posts to its own Discord channel webhook.
- Per-project event filtering: choose which Kanboard event groups should be sent
  to Discord so the channel stays focused on meaningful signals.
- Rich embed cards with event-specific colors, the task link, assignee and column.
- Discord mentions for the assignee on task lifecycle events.
- Comment @mentions ping the mapped Discord users being mentioned on the comment
  card itself, not the task assignee.
- Task description @mentions use Kanboard's dedicated user notification path.
- No external dependencies: uses Kanboard's built-in HTTP client.
- SSRF protection: only official Discord webhook hosts over HTTPS are accepted,
  and private-network URLs are blocked (unless `WEBHOOK_ALLOW_PRIVATE_NETWORKS`
  is enabled in Kanboard).

Requirements
------------

- Kanboard >= 1.2.0
- PHP >= 7.4

Installation
------------

Clone or copy this repository into the folder `plugins/Discord` of your Kanboard
installation. The folder name is case-sensitive and **must** be exactly
`Discord` (not the repository name), because Kanboard resolves the plugin
namespace and templates from the capitalized folder name.

```
git clone https://github.com/nczz/kanboard-plugin-discord.git plugins/Discord
```

Running the tests
-----------------

The tests reuse Kanboard's own PHPUnit harness, so they must run from inside a
Kanboard checkout with this plugin placed (or symlinked) at `plugins/Discord`:

```
# from the Kanboard root, with dev dependencies installed
ln -s /path/to/kanboard-plugin-discord plugins/Discord
./vendor/bin/phpunit -c plugins/Discord/Test/phpunit.xml
```

Configuration
-------------

### 1. Route a project to a Discord channel

1. In Discord: **Server Settings > Integrations > Webhooks > New Webhook**,
   pick the target channel, and copy the webhook URL.
2. In Kanboard: open the project, go to **Settings > Integrations > Discord**,
   paste the webhook URL and save.

To send several projects to the same channel, paste the same webhook URL into
each project.

### Event filtering

Under **Settings > Integrations > Discord > Discord notification events**, choose
which event groups should be sent to Discord:

- Tasks: create, update, assignee change, close, reopen, overdue.
- Task moves: project, column, column position, swimlane. These are unchecked
  by default because board drag/reorder activity is usually noisy.
- Comments: create, update, delete.
- Subtasks: create, update, delete.
- Files: attach, remove.
- Internal links: create/update, remove.
- Task description @mentions.

Most supported events are enabled by default. Task move events are disabled by
default until explicitly checked. Older coarse settings for close/open, assignee
changes, internal links and task description mentions are still honored where
they map cleanly to the split events; move events remain off unless their new
individual move toggles are checked.

### Card layout and content length

Each notification card is designed to convey the **status** completely while
keeping the **content** compact so channels don't get flooded:

- **Title** always identifies the task: `#<id> · <task title>`.
- **Description** always starts with a full action sentence (who did what,
  e.g. "Alice moved the task #42 to the column In Progress").
- Below that, an optional **content excerpt** (task description / comment /
  subtask) is shown, trimmed to a configurable length.

The excerpt length is set per project under **Settings > Integrations >
Discord > Content excerpt length**:

- Leave empty to use the default (280 characters).
- Set `0` to hide content excerpts entirely (status-only cards).
- A global default can also be set via the `discord_excerpt_length`
  application setting.

### 2. Enable Discord mentions for a user

1. In Discord: enable **Developer Mode** (User Settings > Advanced), right-click
   a user and choose **Copy User ID**.
2. In Kanboard: go to **My profile > Integrations > Discord**, paste the numeric
   Discord User ID and save.
3. In Kanboard: go to **My profile > Notifications** and enable the **Discord**
   notification type if you want task-description @mentions to use Kanboard's
   dedicated user notification path.

Now that user will be pinged in Discord when assigned to a task or mentioned in
a comment. Comment @mentions ping the mentioned mapped Discord users on the
comment card itself; when no mapped project member is mentioned, the card falls
back to pinging the task assignee.

Updating on the server (git pull)
---------------------------------

When Kanboard runs in Docker with the host `plugins/` directory bind-mounted
into the container, deploy this plugin as a git clone and update it in place:

```
# one-time: clone into the mounted plugins directory as "Discord"
cd <host-plugins-dir>
git clone https://github.com/nczz/kanboard-plugin-discord.git Discord

# to update later
cd <host-plugins-dir>/Discord
git pull
```

No container restart is required — Kanboard loads plugins on each request, and
this plugin has no database schema migrations. The clone's `.git` and `Test/`
directories are ignored by the plugin loader.

Troubleshooting
---------------
- Enable the Kanboard debug mode; HTTP client errors are logged to
  `data/debug.log` or syslog.
- Make sure the webhook URL host is `discord.com` (or a Discord subdomain) and
  uses HTTPS, otherwise it is rejected for security reasons.

License
-------

MIT
