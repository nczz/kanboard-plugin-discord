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
    'Choose which Kanboard events should be sent to Discord. Task move notifications are off by default because drag/reorder activity is often noisy.'
        => '選擇哪些 Kanboard 事件要送到 Discord。任務移動通知預設不勾選，因為拖曳和排序活動通常較吵。',
    'Task created' => '建立任務',
    'Task updated' => '更新任務',
    'Task assignee changed' => '變更任務負責人',
    'Task closed' => '關閉任務',
    'Task reopened' => '重新開啟任務',
    'Task overdue' => '任務逾期',
    'Task moves' => '任務移動',
    'Task moved to another project' => '移動任務到其他專案',
    'Task moved to another column' => '移動任務到其他欄位',
    'Task reordered in a column' => '調整任務在欄位中的排序',
    'Task moved to another swimlane' => '移動任務到其他泳道',
    'Comment created' => '新增留言',
    'Comment updated' => '更新留言',
    'Comment deleted' => '刪除留言',
    'Subtask created' => '建立子任務',
    'Subtask updated' => '更新子任務',
    'Subtask deleted' => '刪除子任務',
    'Files' => '檔案',
    'File attached' => '附加檔案',
    'File removed' => '移除檔案',
    'Internal links' => '內部連結',
    'Task internal link created or updated' => '建立或更新任務內部連結',
    'Task internal link removed' => '移除任務內部連結',
    'Mentions' => '提及',
    'Task description @mentions' => '任務描述 @提及',
    'Assignee' => '負責人',
    'Column' => '欄位',
    'Changed' => '變更欄位',
    'Save' => '儲存',
);
