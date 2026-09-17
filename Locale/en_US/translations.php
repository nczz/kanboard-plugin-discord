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
    'The Discord notification type is enabled for users by default when the plugin is installed. It is only needed for task-description @mentions; comment @mentions and overdue task cards use project-level Discord settings instead. Users can opt out from task-description mention cards in Kanboard notification settings.'
        => 'The Discord notification type is enabled for users by default when the plugin is installed. It is only needed for task-description @mentions; comment @mentions and overdue task cards use project-level Discord settings instead. Users can opt out from task-description mention cards in Kanboard notification settings.',
    'Discord notification events' => 'Discord notification events',
    'Choose which Kanboard events should be sent to Discord. Task move notifications are off by default because drag/reorder activity is often noisy.'
        => 'Choose which Kanboard events should be sent to Discord. Task move notifications are off by default because drag/reorder activity is often noisy.',
    'Task created' => 'Task created',
    'Task updated' => 'Task updated',
    'Task assignee changed' => 'Task assignee changed',
    'Task closed' => 'Task closed',
    'Task reopened' => 'Task reopened',
    'Task overdue' => 'Task overdue',
    'Task moves' => 'Task moves',
    'Task moved to another project' => 'Task moved to another project',
    'Task moved to another column' => 'Task moved to another column',
    'Task reordered in a column' => 'Task reordered in a column',
    'Task moved to another swimlane' => 'Task moved to another swimlane',
    'Comment created' => 'Comment created',
    'Comment updated' => 'Comment updated',
    'Comment deleted' => 'Comment deleted',
    'Subtask created' => 'Subtask created',
    'Subtask updated' => 'Subtask updated',
    'Subtask deleted' => 'Subtask deleted',
    'Files' => 'Files',
    'File attached' => 'File attached',
    'File removed' => 'File removed',
    'Internal links' => 'Internal links',
    'Task internal link created or updated' => 'Task internal link created or updated',
    'Task internal link removed' => 'Task internal link removed',
    'Mentions' => 'Mentions',
    'Task description @mentions' => 'Task description @mentions',
    'Assignee' => 'Assignee',
    'Column' => 'Column',
    'Changed' => 'Changed',
    'Save' => 'Save',
);
