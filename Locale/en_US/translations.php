<?php

// English (en_US) baseline translations for the Discord plugin.
// Keys are the source strings used with t(); values are the English text.
// This file documents the plugin's own translatable strings and serves as the
// reference for other languages (e.g. zh_TW). Strings already provided by
// Kanboard core (Title, Status, Priority, Due Date, ...) are intentionally not
// duplicated here.

return array(
    'Send rich Discord notifications per project and mention mapped Discord users'
        => 'Send rich Discord notifications per project and mention mapped Discord users',
    'Discord' => 'Discord',
    'Discord Webhook URL' => 'Discord Webhook URL',
    'Create an Incoming Webhook in your Discord channel (Server Settings > Integrations > Webhooks) and paste the URL here. Leave empty to disable Discord notifications for this project.'
        => 'Create an Incoming Webhook in your Discord channel (Server Settings > Integrations > Webhooks) and paste the URL here. Leave empty to disable Discord notifications for this project.',
    'Content excerpt length' => 'Content excerpt length',
    'Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.'
        => 'Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.',
    'Discord User ID' => 'Discord User ID',
    'Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.'
        => 'Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.',
    'To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".'
        => 'To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".',
    'For task description @mentions, also enable the Discord notification type in your Kanboard notification settings. Comment @mentions are pinged on the project comment card when the mentioned user has a Discord User ID mapped.'
        => 'For task description @mentions, also enable the Discord notification type in your Kanboard notification settings. Comment @mentions are pinged on the project comment card when the mentioned user has a Discord User ID mapped.',
    'Discord notification events' => 'Discord notification events',
    'Choose which Kanboard events should be sent to Discord. Existing projects default to all events until this form is saved.'
        => 'Choose which Kanboard events should be sent to Discord. Existing projects default to all events until this form is saved.',
    'Task created' => 'Task created',
    'Task updated, moved, or assigned' => 'Task updated, moved, or assigned',
    'Task closed or reopened' => 'Task closed or reopened',
    'Task overdue' => 'Task overdue',
    'Comment created' => 'Comment created',
    'Comment updated' => 'Comment updated',
    'Comment deleted' => 'Comment deleted',
    'Subtask created' => 'Subtask created',
    'Subtask updated' => 'Subtask updated',
    'Subtask deleted' => 'Subtask deleted',
    'Files and links' => 'Files and links',
    'File attached' => 'File attached',
    'File removed' => 'File removed',
    'Task link changed' => 'Task link changed',
    'Mentions' => 'Mentions',
    'Task description @mentions' => 'Task description @mentions',
    'Assignee' => 'Assignee',
    'Column' => 'Column',
    'Changed' => 'Changed',
    'Save' => 'Save',
);
