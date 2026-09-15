<?php

return array(
    'Send rich Discord notifications per project and mention mapped Discord users' => '依專案發送豐富的 Discord 通知卡片，並標註對應的 Discord 使用者',
    'Discord' => 'Discord',
    'Discord Webhook URL' => 'Discord Webhook 網址',
    'Create an Incoming Webhook in your Discord channel (Server Settings > Integrations > Webhooks) and paste the URL here. Leave empty to disable Discord notifications for this project.'
        => '在你的 Discord 頻道建立 Incoming Webhook（伺服器設定 > 整合 > Webhook），並將網址貼在這裡。留空則停用此專案的 Discord 通知。',
    'Content excerpt length' => '內容摘要長度',
    'Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.'
        => '任務描述、留言、子任務在通知中最多顯示的字數。狀態列（誰對哪個任務做了什麼）一律完整顯示。留空使用預設值（280），設為 0 則完全不顯示內容摘要。',
    'Discord User ID' => 'Discord 使用者 ID',
    'Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.'
        => '輸入你的數字型 Discord 使用者 ID，當你被指派任務或在留言中被提及時，Discord 通知會標註（tag）你。',
    'To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".'
        => '取得方式：在 Discord 開啟開發者模式（設定 > 進階），然後右鍵點你的名字選擇「複製使用者 ID」。',
    'For task description @mentions, also enable the Discord notification type in your Kanboard notification settings. Comment @mentions are pinged on the project comment card when the mentioned user has a Discord User ID mapped.'
        => '若要在任務描述 @提及時收到 Discord 標註，請同時在 Kanboard 通知設定中啟用 Discord 通知類型。留言 @提及會在專案留言卡片上標註已對應 Discord 使用者 ID 的被提及者。',
    'Discord notification events' => 'Discord 通知事件',
    'Choose which Kanboard events should be sent to Discord. Existing projects default to all events until this form is saved.'
        => '選擇哪些 Kanboard 事件要送到 Discord。既有專案在儲存此表單前預設會傳送所有事件。',
    'Task created' => '建立任務',
    'Task updated, moved, or assigned' => '更新、移動或指派任務',
    'Task closed or reopened' => '關閉或重新開啟任務',
    'Task overdue' => '任務逾期',
    'Comment created' => '新增留言',
    'Comment updated' => '更新留言',
    'Comment deleted' => '刪除留言',
    'Subtask created' => '建立子任務',
    'Subtask updated' => '更新子任務',
    'Subtask deleted' => '刪除子任務',
    'Files and links' => '檔案與連結',
    'File attached' => '附加檔案',
    'File removed' => '移除檔案',
    'Task link changed' => '任務連結變更',
    'Mentions' => '提及',
    'Task description @mentions' => '任務描述 @提及',
    'Assignee' => '負責人',
    'Column' => '欄位',
    'Changed' => '變更欄位',
    'Save' => '儲存',
);
