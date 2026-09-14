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
- Rich embed cards with event-specific colors, the task link, assignee and column.
- Discord mentions for the assignee (on project events) and for the mentioned
  user (on @mention events).
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
installation. The folder name is case-sensitive and **must** be `Discord`.

```
git clone https://github.com/nczz/kanboard-plugin-discord.git plugins/Discord
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

### 2. Enable Discord mentions for a user

1. In Discord: enable **Developer Mode** (User Settings > Advanced), right-click
   a user and choose **Copy User ID**.
2. In Kanboard: go to **My profile > Integrations > Discord**, paste the numeric
   Discord User ID and save.

Now that user will be pinged in Discord when assigned to a task or mentioned in
a comment.

Troubleshooting
---------------

- Enable the Kanboard debug mode; HTTP client errors are logged to
  `data/debug.log` or syslog.
- Make sure the webhook URL host is `discord.com` (or a Discord subdomain) and
  uses HTTPS, otherwise it is rejected for security reasons.

License
-------

MIT
