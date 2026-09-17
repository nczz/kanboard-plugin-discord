Discord plugin for Kanboard
===========================

[繁體中文 README](README.zh-TW.md)

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
- Per-project notification matrix: for every supported event, choose whether to
  send Discord and whether to suppress Kanboard Email notifications.
- Per-task notification rules: mute Discord and/or Email for selected event
  types on a single task card without changing project defaults.
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

### Event filtering and Email suppression

Under **Settings > Integrations > Discord > Discord notification events**, each
event has two independent project-level controls:

| Discord notification | Suppress Email | Result |
| --- | --- | --- |
| Off | Off | Email only |
| On | Off | Discord + Email |
| On | On | Discord only |
| Off | On | Muted for both Discord and Email |


#### Routing model

| Layer | Discord decision | Email decision |
| --- | --- | --- |
| Project defaults | `Discord notification` decides whether this event can post to the project's webhook. | `Suppress Email` decides whether Kanboard Email delivery is blocked for this event. |
| Task exclusions | `Mute Discord` can only block a Discord event for this task. It cannot enable a project-disabled event. | `Mute Email` can only block Email delivery for this task. |
| User preferences | The Discord user notification type only affects task-description @mention cards. Project cards still use project settings. | The user's Kanboard Email checkbox still applies. If Email is not selected, no Email is sent. |

The effective rules are:

```text
Discord sends when:
project Discord event enabled
AND task does not mute Discord for this event
AND project webhook URL is valid
```

```text
Email sends when:
user has Email notification type selected
AND Kanboard's notification filter allows the event
AND project does not suppress Email for this event
AND task does not mute Email for this event
```

Settings are stored in Kanboard metadata tables. No database schema migration is
required:

| Setting | Metadata table | Key format |
| --- | --- | --- |
| Project Discord event | `project_has_metadata` | `discord_event_<event>` |
| Project Email suppression | `project_has_metadata` | `discord_suppress_email_<event>` |
| Task Discord mute | `task_has_metadata` | `discord_task_mute_discord_<event>` |
| Task Email mute | `task_has_metadata` | `discord_task_mute_email_<event>` |

Supported event rows:

- Tasks: create, update, assignee change, close, reopen, overdue.
- Task moves: project, column, column position, swimlane. Discord is unchecked
  by default because board drag/reorder activity is usually noisy.
- Comments: create, update, delete.
- Subtasks: create, update, delete.
- Files: attach, remove.
- Internal links: create/update, remove.
- Mentions: task description @mentions and comment @mentions.

Most Discord events are enabled by default. Task move Discord events are disabled
until explicitly checked. Older coarse settings for close/open, assignee changes,
internal links and task description mentions are still honored where they map
cleanly to the split events; move events remain off unless their new individual
move toggles are checked.

Email suppression is a setting-based rule, not a Discord-success fallback. If
**Suppress Email** is checked, Kanboard Email notifications for that event are
not sent even if Discord delivery later fails. This plugin does not change each
user's Email checkbox; it only suppresses delivery for matching events.

Comment @mention Discord pings are handled by the **Comment created** card so the
channel receives one complete card with the comment body. The **Comment
@mentions** row controls Email mention suppression and does not create a separate
Discord card.

Overdue cards are emitted when Kanboard's `notification:overdue-tasks` command
runs. Kanboard core routes overdue tasks through a command instead of regular
project events; this plugin extends that command to send project Discord cards
directly and de-duplicates them so the channel receives one card per overdue
task even when several users or managers are notified by Kanboard. Email
suppression for overdue tasks is applied per task, so mixed batches still send
Email for the overdue tasks that are not suppressed.

### Task-level notification rules

Open a task and choose **Notification rules** to mute Discord or Email for
specific event types on that one task card.

The modal uses the same event registry as the project settings page, with two
task-only columns:

| Column | Effect |
| --- | --- |
| Mute Discord | Skip this task for that Discord event. For overdue batches, only muted tasks are removed; other overdue tasks still post. |
| Mute Email | Skip this task for that Email event. For overdue batches, only muted tasks are removed from the Email body. |

Only users who can update the task can edit these rules. Saving the modal removes
unchecked metadata instead of storing false values.

Task rules only exclude notifications. They do not enable a Discord event that is
disabled at the project level. Precedence is:

1. Task-level mute rules win.
2. Project-level Discord and Suppress Email rules apply next.
3. Kanboard user notification preferences still apply for Email.

The plugin does not suppress Kanboard Web notifications and does not affect
manual **Send by email** actions; it only changes Kanboard notification events.

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
3. The plugin enables Kanboard's **Discord** notification type for active users
   when installed/upgraded and for new users after they are created. That
   user-level notification type is only used for task-description @mentions.
   Comment @mentions and overdue task cards use project-level Discord settings
   instead, and users can opt out from task-description mention cards in
   **My profile > Notifications**.

Now that user will be pinged in Discord when assigned to a task or mentioned in
a comment. Comment @mentions ping the mentioned mapped Discord users on the
comment card itself. If a comment mentions a project member who has no Discord
User ID mapped, the card is still sent without a ping; it does not fall back to
the task assignee. Only comments with no real project-member mention fall back
to pinging the assignee.

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
