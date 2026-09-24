<?php

namespace Kanboard\Plugin\Discord\Schema;

use PDO;

require_once __DIR__.'/Migration.php';

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec("IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.discord_project_settings') AND type = N'U')
        CREATE TABLE dbo.discord_project_settings (
            project_id INT NOT NULL,
            webhook_url NVARCHAR(MAX) NULL,
            excerpt_length INT NULL,
            changed_on INT NULL,
            changed_by INT NULL,
            PRIMARY KEY(project_id),
            FOREIGN KEY(project_id) REFERENCES dbo.projects(id) ON DELETE CASCADE
        )");

    $pdo->exec("IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.discord_project_event_rules') AND type = N'U')
        CREATE TABLE dbo.discord_project_event_rules (
            project_id INT NOT NULL,
            event_key NVARCHAR(80) NOT NULL,
            discord_enabled BIT NULL,
            email_suppressed BIT NULL,
            changed_on INT NULL,
            changed_by INT NULL,
            PRIMARY KEY(project_id, event_key),
            FOREIGN KEY(project_id) REFERENCES dbo.projects(id) ON DELETE CASCADE
        )");

    $pdo->exec("IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.discord_task_event_rules') AND type = N'U')
        CREATE TABLE dbo.discord_task_event_rules (
            task_id INT NOT NULL,
            event_key NVARCHAR(80) NOT NULL,
            mute_discord BIT NOT NULL DEFAULT 0,
            mute_email BIT NOT NULL DEFAULT 0,
            changed_on INT NULL,
            changed_by INT NULL,
            PRIMARY KEY(task_id, event_key),
            FOREIGN KEY(task_id) REFERENCES dbo.tasks(id) ON DELETE CASCADE
        )");

    $pdo->exec("IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.discord_user_settings') AND type = N'U')
        CREATE TABLE dbo.discord_user_settings (
            user_id INT NOT NULL,
            discord_user_id NVARCHAR(32) NULL,
            default_notifications_processed BIT NOT NULL DEFAULT 0,
            changed_on INT NULL,
            changed_by INT NULL,
            PRIMARY KEY(user_id),
            FOREIGN KEY(user_id) REFERENCES dbo.users(id) ON DELETE CASCADE
        )");

    Migration\migrate_legacy_metadata($pdo);
}
