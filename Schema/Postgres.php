<?php

namespace Kanboard\Plugin\Discord\Schema;

use PDO;

require_once __DIR__.'/Migration.php';

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_project_settings (
        project_id INT NOT NULL,
        webhook_url TEXT NULL,
        excerpt_length INT NULL,
        changed_on INT NULL,
        changed_by INT NULL,
        PRIMARY KEY(project_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_project_event_rules (
        project_id INT NOT NULL,
        event_key VARCHAR(80) NOT NULL,
        discord_enabled SMALLINT NULL,
        email_suppressed SMALLINT NULL,
        changed_on INT NULL,
        changed_by INT NULL,
        PRIMARY KEY(project_id, event_key),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_task_event_rules (
        task_id INT NOT NULL,
        event_key VARCHAR(80) NOT NULL,
        mute_discord SMALLINT NOT NULL DEFAULT 0,
        mute_email SMALLINT NOT NULL DEFAULT 0,
        changed_on INT NULL,
        changed_by INT NULL,
        PRIMARY KEY(task_id, event_key),
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_user_settings (
        user_id INT NOT NULL,
        discord_user_id VARCHAR(32) NULL,
        default_notifications_processed SMALLINT NOT NULL DEFAULT 0,
        changed_on INT NULL,
        changed_by INT NULL,
        PRIMARY KEY(user_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    Migration\migrate_legacy_metadata($pdo);
}
