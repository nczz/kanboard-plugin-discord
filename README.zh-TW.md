Kanboard Discord 外掛
====================

[English README](README.md)

為 Kanboard 專案事件發送豐富的 [Discord](https://discord.com/) 通知卡片，並可對任務負責人或留言中被提及的人發送對應的 Discord @mention。

這個外掛有什麼不同
------------------

一般 Kanboard Discord 外掛只會把事件轉送到頻道。這個外掛加入 **Discord 使用者提及**：當任務被指派給某人，或某人在 Kanboard 中被 @提及時，只要該使用者已在 Kanboard 個人設定中填入 Discord User ID，Discord 內對應的使用者就會收到真正的 ping。

功能特色
--------

- 依專案路由：每個專案可發送到自己的 Discord channel webhook。
- 專案層級通知矩陣：每個支援事件都可獨立設定是否送 Discord、是否抑制 Kanboard Email 通知。
- 任務層級通知規則：可在單一任務卡片上，針對指定事件排除 Discord 和/或 Email，不影響專案預設。
- 豐富 embed 卡片：包含事件顏色、任務連結、負責人、欄位。
- 任務生命週期事件會對負責人發送 Discord mention。
- 留言 @mention 會在該留言卡片本身 ping 被提及且已對應的 Discord 使用者，而不是 ping 任務負責人。
- 任務描述 @mention 使用 Kanboard 專用的 user notification path。
- 無外部相依套件：使用 Kanboard 內建 HTTP client。
- SSRF 防護：只允許 HTTPS 的官方 Discord webhook host，且會阻擋 private-network URL（除非 Kanboard 啟用 `WEBHOOK_ALLOW_PRIVATE_NETWORKS`）。

需求
----

- Kanboard >= 1.2.0
- PHP >= 7.4

安裝
----

將此 repository clone 或複製到 Kanboard 安裝目錄的 `plugins/Discord`。資料夾名稱區分大小寫，且**必須**正好是 `Discord`（不是 repository 名稱），因為 Kanboard 會用這個大寫資料夾名稱解析 plugin namespace 與 templates。

```
git clone https://github.com/nczz/kanboard-plugin-discord.git plugins/Discord
```

執行測試
--------

測試會重用 Kanboard 自己的 PHPUnit harness，因此必須在 Kanboard checkout 中執行，並讓此 plugin 位於（或 symlink 到）`plugins/Discord`：

```
# 從 Kanboard root 執行，且已安裝 dev dependencies
ln -s /path/to/kanboard-plugin-discord plugins/Discord
./vendor/bin/phpunit -c plugins/Discord/Test/phpunit.xml
```

設定
----

### 1. 將專案路由到 Discord 頻道

1. 在 Discord：進入 **Server Settings > Integrations > Webhooks > New Webhook**，選擇目標頻道，複製 webhook URL。
2. 在 Kanboard：開啟專案，進入 **Settings > Integrations > Discord**，貼上 webhook URL 並儲存。

若要讓多個專案送到同一個頻道，可在每個專案貼上同一個 webhook URL。

### 事件過濾與 Email 抑制

在 **Settings > Integrations > Discord > Discord notification events** 中，每個事件都有兩個獨立的專案層級控制：

| Discord notification | Suppress Email | 結果 |
| --- | --- | --- |
| Off | Off | 只送 Email |
| On | Off | Discord + Email |
| On | On | 只送 Discord |
| Off | On | Discord 與 Email 都靜音 |

#### 通知路由模型

| 層級 | Discord 決策 | Email 決策 |
| --- | --- | --- |
| 專案預設 | `Discord notification` 決定此事件是否可送到專案 webhook。 | `Suppress Email` 決定是否阻擋此事件的 Kanboard Email delivery。 |
| 任務排除 | `Mute Discord` 只能針對此任務阻擋 Discord 事件，不能啟用專案層級已關閉的事件。 | `Mute Email` 只能針對此任務阻擋 Email delivery。 |
| 使用者偏好 | Discord user notification type 只影響任務描述 @mention 卡片。專案卡片仍使用專案設定。 | 使用者原本的 Kanboard Email checkbox 仍會生效。若使用者未選 Email，就不會寄 Email。 |

實際生效規則：

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

設定會存放在 Kanboard metadata tables，不需要資料庫 schema migration：

| 設定 | Metadata table | Key 格式 |
| --- | --- | --- |
| 專案 Discord event | `project_has_metadata` | `discord_event_<event>` |
| 專案 Email suppression | `project_has_metadata` | `discord_suppress_email_<event>` |
| 任務 Discord mute | `task_has_metadata` | `discord_task_mute_discord_<event>` |
| 任務 Email mute | `task_has_metadata` | `discord_task_mute_email_<event>` |

支援的事件列：

- Tasks：create、update、assignee change、close、reopen、overdue。
- Task moves：project、column、column position、swimlane。Discord 預設不勾選，因為看板拖曳與排序事件通常較吵。
- Comments：create、update、delete。
- Subtasks：create、update、delete。
- Files：attach、remove。
- Internal links：create/update、remove。
- Mentions：task description @mentions、comment @mentions。

大多數 Discord events 預設啟用。Task move Discord events 預設停用，直到明確勾選。舊版較粗的 close/open、assignee changes、internal links、task description mentions 設定，會在能清楚對應的地方保留相容；move events 不會因舊的 `task_update` 設定而自動啟用。

Email suppression 是設定式規則，不是 Discord 成功後的 fallback。只要勾選 **Suppress Email**，符合該事件的 Kanboard Email 通知就不會寄出，即使後續 Discord delivery 失敗也一樣。這個外掛不會改動每個使用者的 Email checkbox；它只會針對符合規則的事件抑制 delivery。

留言 @mention 的 Discord ping 由 **Comment created** 卡片處理，因此頻道只會收到一張包含留言內容的完整卡片。**Comment @mentions** 這一列控制 Email mention suppression，不會建立額外的 Discord 卡片。

Overdue 卡片會在 Kanboard 的 `notification:overdue-tasks` command 執行時發送。Kanboard core 透過 command 而不是一般 project event 處理 overdue tasks；此外掛擴充該 command，依專案 Discord 設定直接送 project Discord cards，並做 de-duplicate，確保即使 Kanboard 通知多位使用者或 manager，Discord 頻道對同一張 overdue task 也只收到一張卡片。Overdue Email suppression 會逐 task 套用，因此混合批次中未被 suppress 的 overdue tasks 仍會寄 Email。

### 任務層級通知規則

開啟任務並選擇 **Notification rules**，即可針對該任務卡片上的指定事件排除 Discord 或 Email。

Modal 使用與專案設定頁相同的 event registry，並提供兩個 task-only 欄位：

| 欄位 | 效果 |
| --- | --- |
| Mute Discord | 針對此任務略過該 Discord event。對 overdue 批次，只會移除被 mute 的 tasks；其他 overdue tasks 仍會發送。 |
| Mute Email | 針對此任務略過該 Email event。對 overdue 批次，只會從 Email 內容中移除被 mute 的 tasks。 |

只有可更新該任務的使用者能編輯這些規則。儲存 modal 時，未勾選的 metadata 會被移除，而不是儲存 false value。

Task rules 只能排除通知，不會啟用專案層級已關閉的 Discord event。優先順序：

1. Task-level mute rules 優先。
2. 接著套用 project-level Discord 與 Suppress Email rules。
3. Email 仍會套用 Kanboard user notification preferences。

此外掛不會抑制 Kanboard Web notifications，也不會影響手動 **Send by email** 動作；它只改變 Kanboard notification events。

### 卡片版面與內容長度

每張通知卡片都設計成完整表達**狀態**，同時讓**內容**保持精簡，避免頻道洗版：

- **Title** 一律標示任務：`#<id> · <task title>`。
- **Description** 一律先以完整動作句開頭（誰做了什麼，例如「Alice moved the task #42 to the column In Progress」）。
- 下方可選擇顯示內容摘要（任務描述 / 留言 / 子任務），並依設定長度裁切。

摘要長度可在專案的 **Settings > Integrations > Discord > Content excerpt length** 設定：

- 留空使用預設值（280 字元）。
- 設為 `0` 可完全隱藏內容摘要（只保留狀態）。
- 也可透過 application setting `discord_excerpt_length` 設定全域預設值。

### 2. 啟用使用者的 Discord mentions

1. 在 Discord：啟用 **Developer Mode**（User Settings > Advanced），右鍵點選使用者並選擇 **Copy User ID**。
2. 在 Kanboard：進入 **My profile > Integrations > Discord**，貼上數字型 Discord User ID 並儲存。
3. 外掛安裝 / 升級時會為 active users 預設啟用 Kanboard 的 **Discord** notification type；新增使用者後也會啟用。這個 user-level notification type 只用於任務描述 @mentions。Comment @mentions 與 overdue task cards 使用 project-level Discord settings；使用者仍可在 **My profile > Notifications** 中取消接收任務描述 mention cards。

設定完成後，當該使用者被指派任務或在留言中被提及時，就會在 Discord 被 ping。Comment @mentions 會在留言卡片本身 ping 被提及且已 mapping 的 Discord 使用者。如果留言提及的專案成員沒有 mapping Discord User ID，卡片仍會送出但不會 ping；也不會 fallback 去 ping 任務負責人。只有留言中沒有真正的 project-member mention 時，才會 fallback ping 負責人。

在伺服器上更新（git pull）
--------------------------

當 Kanboard 在 Docker 中執行，且 host 的 `plugins/` 目錄 bind-mounted 到 container 時，可將此外掛以 git clone 形式部署，並直接在原地更新：

```
# 一次性：clone 到 mounted plugins 目錄，資料夾名稱必須是 "Discord"
cd <host-plugins-dir>
git clone https://github.com/nczz/kanboard-plugin-discord.git Discord

# 日後更新
cd <host-plugins-dir>/Discord
git pull
```

不需要重啟 container；Kanboard 每次 request 都會載入 plugins，且此外掛沒有 database schema migrations。Plugin loader 會忽略 clone 中的 `.git` 與 `Test/` 目錄。

疑難排解
--------

- 啟用 Kanboard debug mode；HTTP client errors 會記錄到 `data/debug.log` 或 syslog。
- 確認 webhook URL host 是 `discord.com`（或 Discord subdomain）且使用 HTTPS；否則會因安全限制被拒絕。

授權
----

MIT
