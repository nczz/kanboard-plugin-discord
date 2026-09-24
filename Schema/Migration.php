<?php

namespace Kanboard\Plugin\Discord\Schema\Migration;

use Kanboard\Plugin\Discord\Notification\EventRegistry;
use PDO;

const PROJECT_WEBHOOK_KEY = 'discord_webhook_url';
const PROJECT_EXCERPT_KEY = 'discord_excerpt_length';
const USER_DISCORD_ID_KEY = 'discord_user_id';
const USER_DEFAULT_PROCESSED_KEY = 'discord.default_user_notifications.processed';

/**
 * Migrate legacy Kanboard metadata into Discord plugin-owned tables.
 *
 * @param PDO $pdo
 */
function migrate_legacy_metadata(PDO $pdo)
{
    migrate_project_metadata($pdo);
    migrate_task_metadata($pdo);
    migrate_user_metadata($pdo);
}

/**
 * @param PDO $pdo
 */
function migrate_project_metadata(PDO $pdo)
{
    $projectNames = array_merge(
        array(PROJECT_WEBHOOK_KEY, PROJECT_EXCERPT_KEY),
        legacy_names(EventRegistry::META_DISCORD_EVENT_PREFIX),
        legacy_names(EventRegistry::META_SUPPRESS_EMAIL_PREFIX)
    );

    $rows = fetch_metadata($pdo, 'project_has_metadata', 'project_id', $projectNames);
    $byProject = group_metadata($rows, 'project_id');

    foreach ($byProject as $projectId => $metadata) {
        if (array_key_exists(PROJECT_WEBHOOK_KEY, $metadata) || array_key_exists(PROJECT_EXCERPT_KEY, $metadata)) {
            upsert(
                $pdo,
                'discord_project_settings',
                array('project_id' => $projectId),
                array(
                    'webhook_url'    => array_key_exists(PROJECT_WEBHOOK_KEY, $metadata) ? (string) $metadata[PROJECT_WEBHOOK_KEY] : '',
                    'excerpt_length' => normalize_nullable_integer(array_key_exists(PROJECT_EXCERPT_KEY, $metadata) ? $metadata[PROJECT_EXCERPT_KEY] : null),
                    'changed_on'     => time(),
                    'changed_by'     => 0,
                )
            );
        }

        foreach (event_keys() as $eventKey) {
            $discordEnabled = legacy_boolean_value($metadata, EventRegistry::META_DISCORD_EVENT_PREFIX, $eventKey);
            $emailSuppressed = legacy_boolean_value($metadata, EventRegistry::META_SUPPRESS_EMAIL_PREFIX, $eventKey);

            if ($discordEnabled === null && $emailSuppressed === null) {
                continue;
            }

            upsert(
                $pdo,
                'discord_project_event_rules',
                array('project_id' => $projectId, 'event_key' => $eventKey),
                array(
                    'discord_enabled'  => $discordEnabled,
                    'email_suppressed' => $emailSuppressed,
                    'changed_on'       => time(),
                    'changed_by'       => 0,
                )
            );
        }
    }

    delete_metadata($pdo, 'project_has_metadata', $projectNames);
}

/**
 * @param PDO $pdo
 */
function migrate_task_metadata(PDO $pdo)
{
    $taskNames = array_merge(
        legacy_names(EventRegistry::META_TASK_MUTE_DISCORD_PREFIX),
        legacy_names(EventRegistry::META_TASK_MUTE_EMAIL_PREFIX)
    );

    $rows = fetch_metadata($pdo, 'task_has_metadata', 'task_id', $taskNames);
    $byTask = group_metadata($rows, 'task_id');

    foreach ($byTask as $taskId => $metadata) {
        foreach (event_keys() as $eventKey) {
            $muteDiscord = legacy_boolean_value($metadata, EventRegistry::META_TASK_MUTE_DISCORD_PREFIX, $eventKey);
            $muteEmail = legacy_boolean_value($metadata, EventRegistry::META_TASK_MUTE_EMAIL_PREFIX, $eventKey);

            if ($muteDiscord !== 1 && $muteEmail !== 1) {
                continue;
            }

            upsert(
                $pdo,
                'discord_task_event_rules',
                array('task_id' => $taskId, 'event_key' => $eventKey),
                array(
                    'mute_discord' => $muteDiscord === 1 ? 1 : 0,
                    'mute_email'   => $muteEmail === 1 ? 1 : 0,
                    'changed_on'   => time(),
                    'changed_by'   => 0,
                )
            );
        }
    }

    delete_metadata($pdo, 'task_has_metadata', $taskNames);
}

/**
 * @param PDO $pdo
 */
function migrate_user_metadata(PDO $pdo)
{
    $userNames = array(USER_DISCORD_ID_KEY, USER_DEFAULT_PROCESSED_KEY);
    $rows = fetch_metadata($pdo, 'user_has_metadata', 'user_id', $userNames);
    $byUser = group_metadata($rows, 'user_id');

    foreach ($byUser as $userId => $metadata) {
        upsert(
            $pdo,
            'discord_user_settings',
            array('user_id' => $userId),
            array(
                'discord_user_id'                 => array_key_exists(USER_DISCORD_ID_KEY, $metadata) ? (string) $metadata[USER_DISCORD_ID_KEY] : '',
                'default_notifications_processed' => boolean_value(array_key_exists(USER_DEFAULT_PROCESSED_KEY, $metadata) ? $metadata[USER_DEFAULT_PROCESSED_KEY] : null),
                'changed_on'                      => time(),
                'changed_by'                      => 0,
            )
        );
    }

    delete_metadata($pdo, 'user_has_metadata', $userNames);
}

/**
 * @return string[]
 */
function event_keys()
{
    if (class_exists('Kanboard\\Plugin\\Discord\\Notification\\EventRegistry')) {
        return EventRegistry::getEventKeys();
    }

    return array(
        'task_create',
        'task_update',
        'task_assignee_change',
        'task_close',
        'task_open',
        'task_overdue',
        'task_move_project',
        'task_move_column',
        'task_move_position',
        'task_move_swimlane',
        'comment_create',
        'comment_update',
        'comment_delete',
        'subtask_create',
        'subtask_update_title',
        'subtask_update_status_todo',
        'subtask_update_status_inprogress',
        'subtask_update_status_done',
        'subtask_update_assignee',
        'subtask_update_time_tracking',
        'subtask_update_other',
        'subtask_delete',
        'file_create',
        'file_delete',
        'task_link_create_update',
        'task_link_delete',
        'task_mention',
        'comment_mention',
    );
}

/**
 * @param string $prefix
 * @return string[]
 */
function legacy_names($prefix)
{
    $names = array();
    foreach (event_keys() as $eventKey) {
        $names[] = $prefix.$eventKey;
        if (class_exists('Kanboard\\Plugin\\Discord\\Notification\\EventRegistry')) {
            foreach (EventRegistry::getLegacyEventKeys($eventKey) as $legacyEventKey) {
                $names[] = $prefix.$legacyEventKey;
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * @param array  $metadata
 * @param string $prefix
 * @param string $eventKey
 * @return integer|null
 */
function legacy_boolean_value(array $metadata, $prefix, $eventKey)
{
    $metadataKey = $prefix.$eventKey;
    if (array_key_exists($metadataKey, $metadata)) {
        return boolean_value($metadata[$metadataKey]);
    }

    if (class_exists('Kanboard\\Plugin\\Discord\\Notification\\EventRegistry')) {
        foreach (EventRegistry::getLegacyEventKeys($eventKey) as $legacyEventKey) {
            $legacyMetadataKey = $prefix.$legacyEventKey;
            if (array_key_exists($legacyMetadataKey, $metadata)) {
                return boolean_value($metadata[$legacyMetadataKey]);
            }
        }
    }

    return null;
}

/**
 * @param PDO      $pdo
 * @param string   $table
 * @param string   $entityColumn
 * @param string[] $names
 * @return array
 */
function fetch_metadata(PDO $pdo, $table, $entityColumn, array $names)
{
    if (empty($names)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare('SELECT '.$entityColumn.', name, value FROM '.$table.' WHERE name IN ('.$placeholders.')');
    $statement->execute(array_values($names));

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array  $rows
 * @param string $entityColumn
 * @return array
 */
function group_metadata(array $rows, $entityColumn)
{
    $grouped = array();
    foreach ($rows as $row) {
        $entityId = (int) $row[$entityColumn];
        if (! isset($grouped[$entityId])) {
            $grouped[$entityId] = array();
        }
        $grouped[$entityId][$row['name']] = $row['value'];
    }

    return $grouped;
}

/**
 * @param PDO      $pdo
 * @param string   $table
 * @param string[] $names
 */
function delete_metadata(PDO $pdo, $table, array $names)
{
    if (empty($names)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare('DELETE FROM '.$table.' WHERE name IN ('.$placeholders.')');
    $statement->execute(array_values($names));
}

/**
 * @param PDO    $pdo
 * @param string $table
 * @param array  $keys
 * @param array  $values
 */
function upsert(PDO $pdo, $table, array $keys, array $values)
{
    $where = array();
    $params = array();
    foreach ($keys as $column => $value) {
        $where[] = $column.' = ?';
        $params[] = $value;
    }

    $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.implode(' AND ', $where));
    $statement->execute($params);
    $exists = (int) $statement->fetchColumn() > 0;

    if ($exists) {
        $sets = array();
        $updateParams = array();
        foreach ($values as $column => $value) {
            $sets[] = $column.' = ?';
            $updateParams[] = $value;
        }

        $statement = $pdo->prepare('UPDATE '.$table.' SET '.implode(', ', $sets).' WHERE '.implode(' AND ', $where));
        $statement->execute(array_merge($updateParams, $params));
        return;
    }

    $data = $keys + $values;
    $columns = array_keys($data);
    $statement = $pdo->prepare('INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
    $statement->execute(array_values($data));
}

/**
 * @param mixed $value
 * @return integer
 */
function boolean_value($value)
{
    return (string) $value === '1' || $value === true || $value === 1 ? 1 : 0;
}

/**
 * @param mixed $value
 * @return integer|null
 */
function normalize_nullable_integer($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    return max(0, (int) $value);
}
