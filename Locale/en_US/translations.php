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
    'Create an Incoming Webhook in this project\'s Discord channel and paste the URL here. Leave empty to use the global default Discord webhook URL, when configured.'
        => 'Create an Incoming Webhook in this project\'s Discord channel and paste the URL here. Leave empty to use the global default Discord webhook URL, when configured.',
    'Default Discord Webhook URL' => 'Default Discord Webhook URL',
    'Create an Incoming Webhook in your default Discord channel and paste the URL here. Projects without their own Discord webhook URL will send notifications to this webhook. Leave empty to require each project to configure its own webhook.'
        => 'Create an Incoming Webhook in your default Discord channel and paste the URL here. Projects without their own Discord webhook URL will send notifications to this webhook. Leave empty to require each project to configure its own webhook.',
    'Content excerpt length' => 'Content excerpt length',
    'Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.'
        => 'Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.',
    'Discord User ID' => 'Discord User ID',
    'Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.'
        => 'Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.',
    'To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".'
        => 'To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".',
    'The Discord notification type is enabled for users by default when the plugin is installed and for new users after they are created. It is only needed for task-description @mentions; comment @mentions and overdue task cards use project-level Discord settings instead. Users can opt out from task-description mention cards in Kanboard notification settings.'
        => 'The Discord notification type is enabled for users by default when the plugin is installed and for new users after they are created. It is only needed for task-description @mentions; comment @mentions and overdue task cards use project-level Discord settings instead. Users can opt out from task-description mention cards in Kanboard notification settings.',
    'Discord notification events' => 'Discord notification events',
    'Choose which Kanboard events should be sent to Discord and which Email notifications should be suppressed.'
        => 'Choose which Kanboard events should be sent to Discord and which Email notifications should be suppressed.',
    'Suppressing Email does not require Discord to be enabled. If Discord is also disabled, the event is muted for both Discord and Email.'
        => 'Suppressing Email does not require Discord to be enabled. If Discord is also disabled, the event is muted for both Discord and Email.',
    'Configure the Discord webhook URL, content excerpt length and notification events for this project.'
        => 'Configure the Discord webhook URL, content excerpt length and notification events for this project.',
    'Configure your Discord User ID used for mentions in Discord notifications.'
        => 'Configure your Discord User ID used for mentions in Discord notifications.',
    'Configure Discord' => 'Configure Discord',
    'Invalid Discord webhook URL.' => 'Invalid Discord webhook URL.',
    'Private network webhook URLs are not allowed.' => 'Private network webhook URLs are not allowed.',
    'The excerpt length must be a positive integer.' => 'The excerpt length must be a positive integer.',
    'The excerpt length is too large.' => 'The excerpt length is too large.',
    'The Discord User ID must be numeric.' => 'The Discord User ID must be numeric.',
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
    'Subtask title changed' => 'Subtask title changed',
    'Subtask marked todo' => 'Subtask marked todo',
    'Subtask marked in progress' => 'Subtask marked in progress',
    'Subtask completed' => 'Subtask completed',
    'Subtask assignee changed' => 'Subtask assignee changed',
    'Subtask time tracking changed' => 'Subtask time tracking changed',
    'Other subtask update' => 'Other subtask update',
    'Subtask deleted' => 'Subtask deleted',
    'Files' => 'Files',
    'File attached' => 'File attached',
    'File removed' => 'File removed',
    'Internal links' => 'Internal links',
    'Task internal link created or updated' => 'Task internal link created or updated',
    'Task internal link removed' => 'Task internal link removed',
    'Mentions' => 'Mentions',
    'Task description @mentions' => 'Task description @mentions',
    'Comment @mentions' => 'Comment @mentions',
    'Event' => 'Event',
    'Discord notification' => 'Discord notification',
    'Suppress Email' => 'Suppress Email',
    'Handled by comment cards' => 'Handled by comment cards',
    'Notification rules' => 'Notification rules',
    'These settings only mute notifications for this task. They do not enable events that are disabled at project level.'
        => 'These settings only mute notifications for this task. They do not enable events that are disabled at project level.',
    'Mute Discord' => 'Mute Discord',
    'Mute Email' => 'Mute Email',
    'Task notification rules updated successfully.' => 'Task notification rules updated successfully.',
    'Assignee' => 'Assignee',
    'Column' => 'Column',
    'Changed' => 'Changed',
    'Added content' => 'Added content',
    'Removed content' => 'Removed content',
    'Adjusted wording' => 'Adjusted wording',
    'characters' => 'characters',
    'Save' => 'Save',
);
