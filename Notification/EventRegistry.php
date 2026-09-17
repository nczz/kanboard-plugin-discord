<?php

namespace Kanboard\Plugin\Discord\Notification;

use Kanboard\Model\CommentModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskFileModel;
use Kanboard\Model\TaskLinkModel;
use Kanboard\Model\TaskModel;

/**
 * Shared notification event registry for Discord and Email rules.
 */
class EventRegistry
{
    const META_DISCORD_EVENT_PREFIX = 'discord_event_';
    const META_SUPPRESS_EMAIL_PREFIX = 'discord_suppress_email_';
    const META_TASK_MUTE_DISCORD_PREFIX = 'discord_task_mute_discord_';
    const META_TASK_MUTE_EMAIL_PREFIX = 'discord_task_mute_email_';

    /**
     * Events exposed in project and task notification rule settings.
     *
     * @return array
     */
    public static function getEventGroups()
    {
        return array(
            t('Tasks') => array(
                'task_create' => t('Task created'),
                'task_update' => t('Task updated'),
                'task_assignee_change' => t('Task assignee changed'),
                'task_close' => t('Task closed'),
                'task_open' => t('Task reopened'),
                'task_overdue' => t('Task overdue'),
            ),
            t('Task moves') => array(
                'task_move_project' => t('Task moved to another project'),
                'task_move_column' => t('Task moved to another column'),
                'task_move_position' => t('Task reordered in a column'),
                'task_move_swimlane' => t('Task moved to another swimlane'),
            ),
            t('Comments') => array(
                'comment_create' => t('Comment created'),
                'comment_update' => t('Comment updated'),
                'comment_delete' => t('Comment deleted'),
            ),
            t('Subtasks') => array(
                'subtask_create' => t('Subtask created'),
                'subtask_update' => t('Subtask updated'),
                'subtask_delete' => t('Subtask deleted'),
            ),
            t('Files') => array(
                'file_create' => t('File attached'),
                'file_delete' => t('File removed'),
            ),
            t('Internal links') => array(
                'task_link_create_update' => t('Task internal link created or updated'),
                'task_link_delete' => t('Task internal link removed'),
            ),
            t('Mentions') => array(
                'task_mention' => t('Task description @mentions'),
                'comment_mention' => t('Comment @mentions'),
            ),
        );
    }

    /**
     * @return string[]
     */
    public static function getEventKeys()
    {
        $keys = array();

        foreach (self::getEventGroups() as $options) {
            $keys = array_merge($keys, array_keys($options));
        }

        return $keys;
    }

    /**
     * Map Kanboard event names to notification-rule keys.
     *
     * @param string $eventName
     * @return string
     */
    public static function getEventKey($eventName)
    {
        switch ($eventName) {
            case TaskModel::EVENT_CREATE:
                return 'task_create';
            case TaskModel::EVENT_UPDATE:
            case TaskModel::EVENT_CREATE_UPDATE:
                return 'task_update';
            case TaskModel::EVENT_ASSIGNEE_CHANGE:
                return 'task_assignee_change';
            case TaskModel::EVENT_MOVE_PROJECT:
                return 'task_move_project';
            case TaskModel::EVENT_MOVE_COLUMN:
                return 'task_move_column';
            case TaskModel::EVENT_MOVE_POSITION:
                return 'task_move_position';
            case TaskModel::EVENT_MOVE_SWIMLANE:
                return 'task_move_swimlane';
            case TaskModel::EVENT_CLOSE:
                return 'task_close';
            case TaskModel::EVENT_OPEN:
                return 'task_open';
            case TaskModel::EVENT_OVERDUE:
                return 'task_overdue';
            case CommentModel::EVENT_CREATE:
                return 'comment_create';
            case CommentModel::EVENT_UPDATE:
                return 'comment_update';
            case CommentModel::EVENT_DELETE:
                return 'comment_delete';
            case SubtaskModel::EVENT_CREATE:
                return 'subtask_create';
            case SubtaskModel::EVENT_UPDATE:
            case SubtaskModel::EVENT_CREATE_UPDATE:
                return 'subtask_update';
            case SubtaskModel::EVENT_DELETE:
                return 'subtask_delete';
            case TaskFileModel::EVENT_CREATE:
                return 'file_create';
            case TaskFileModel::EVENT_DESTROY:
                return 'file_delete';
            case TaskLinkModel::EVENT_CREATE_UPDATE:
                return 'task_link_create_update';
            case TaskLinkModel::EVENT_DELETE:
                return 'task_link_delete';
            case TaskModel::EVENT_USER_MENTION:
                return 'task_mention';
            case CommentModel::EVENT_USER_MENTION:
                return 'comment_mention';
            default:
                return '';
        }
    }

    /**
     * Map split event keys back to coarse keys used by earlier plugin versions.
     *
     * @param string $eventKey
     * @return string
     */
    public static function getLegacyEventKey($eventKey)
    {
        switch ($eventKey) {
            case 'task_assignee_change':
                return 'task_update';
            case 'task_close':
            case 'task_open':
                return 'task_close_open';
            case 'task_link_create_update':
            case 'task_link_delete':
                return 'task_link';
            case 'task_mention':
                return 'mention';
            default:
                return '';
        }
    }

    /**
     * Discord event defaults: all enabled except noisy task moves. Comment
     * mention Discord is handled by comment_create cards, not a separate card.
     *
     * @param string $eventKey
     * @return bool
     */
    public static function isDiscordEventDefaultEnabled($eventKey)
    {
        return self::supportsDiscordProjectEvent($eventKey) && strpos($eventKey, 'task_move_') !== 0;
    }

    /**
     * @param string $eventKey
     * @return bool
     */
    public static function supportsDiscordProjectEvent($eventKey)
    {
        return $eventKey !== 'comment_mention';
    }

    /**
     * @param string $eventKey
     * @return string
     */
    public static function getDiscordProjectMetadataKey($eventKey)
    {
        return self::META_DISCORD_EVENT_PREFIX.$eventKey;
    }

    /**
     * @param string $eventKey
     * @return string
     */
    public static function getSuppressEmailProjectMetadataKey($eventKey)
    {
        return self::META_SUPPRESS_EMAIL_PREFIX.$eventKey;
    }

    /**
     * @param string $eventKey
     * @return string
     */
    public static function getTaskMuteDiscordMetadataKey($eventKey)
    {
        return self::META_TASK_MUTE_DISCORD_PREFIX.$eventKey;
    }

    /**
     * @param string $eventKey
     * @return string
     */
    public static function getTaskMuteEmailMetadataKey($eventKey)
    {
        return self::META_TASK_MUTE_EMAIL_PREFIX.$eventKey;
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isDiscordProjectEventEnabled($eventKey, array $metadata)
    {
        if (! self::supportsDiscordProjectEvent($eventKey)) {
            return false;
        }

        $metadataKey = self::getDiscordProjectMetadataKey($eventKey);
        if (array_key_exists($metadataKey, $metadata)) {
            return (string) $metadata[$metadataKey] === '1';
        }

        $legacyKey = self::getLegacyEventKey($eventKey);
        if ($legacyKey !== '') {
            $legacyMetadataKey = self::getDiscordProjectMetadataKey($legacyKey);
            if (array_key_exists($legacyMetadataKey, $metadata)) {
                return (string) $metadata[$legacyMetadataKey] === '1';
            }
        }

        return self::isDiscordEventDefaultEnabled($eventKey);
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isProjectEmailSuppressed($eventKey, array $metadata)
    {
        $metadataKey = self::getSuppressEmailProjectMetadataKey($eventKey);
        return array_key_exists($metadataKey, $metadata) && (string) $metadata[$metadataKey] === '1';
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isTaskDiscordMuted($eventKey, array $metadata)
    {
        $metadataKey = self::getTaskMuteDiscordMetadataKey($eventKey);
        return array_key_exists($metadataKey, $metadata) && (string) $metadata[$metadataKey] === '1';
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isTaskEmailMuted($eventKey, array $metadata)
    {
        $metadataKey = self::getTaskMuteEmailMetadataKey($eventKey);
        return array_key_exists($metadataKey, $metadata) && (string) $metadata[$metadataKey] === '1';
    }
}
