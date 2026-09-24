<?php

namespace Kanboard\Plugin\Discord\Schema;

use PDO;

require_once __DIR__.'/Migration.php';

const VERSION = 1;

function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_project_settings (
        project_id INTEGER NOT NULL,
        webhook_url TEXT NULL,
        excerpt_length INTEGER NULL,
        changed_on INTEGER NULL,
        changed_by INTEGER NULL,
        PRIMARY KEY(project_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_project_event_rules (
        project_id INTEGER NOT NULL,
        event_key TEXT NOT NULL,
        discord_enabled INTEGER NULL,
        email_suppressed INTEGER NULL,
        changed_on INTEGER NULL,
        changed_by INTEGER NULL,
        PRIMARY KEY(project_id, event_key),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_task_event_rules (
        task_id INTEGER NOT NULL,
        event_key TEXT NOT NULL,
        mute_discord INTEGER NOT NULL DEFAULT 0,
        mute_email INTEGER NOT NULL DEFAULT 0,
        changed_on INTEGER NULL,
        changed_by INTEGER NULL,
        PRIMARY KEY(task_id, event_key),
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS discord_user_settings (
        user_id INTEGER NOT NULL,
        discord_user_id TEXT NULL,
        default_notifications_processed INTEGER NOT NULL DEFAULT 0,
        changed_on INTEGER NULL,
        changed_by INTEGER NULL,
        PRIMARY KEY(user_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    Migration\migrate_legacy_metadata($pdo);
}
