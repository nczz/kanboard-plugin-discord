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
                'subtask_update_title' => t('Subtask title changed'),
                'subtask_update_status_todo' => t('Subtask marked todo'),
                'subtask_update_status_inprogress' => t('Subtask marked in progress'),
                'subtask_update_status_done' => t('Subtask completed'),
                'subtask_update_assignee' => t('Subtask assignee changed'),
                'subtask_update_time_tracking' => t('Subtask time tracking changed'),
                'subtask_update_other' => t('Other subtask update'),
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
     * Map a Kanboard event instance to one or more notification-rule keys.
     *
     * Subtask updates are field-aware: a single core subtask.update event can
     * carry multiple changed fields, and each field category has its own rule.
     *
     * @param string $eventName
     * @param array  $eventData
     * @return string[]
     */
    public static function getEventKeysForEvent($eventName, array $eventData = array())
    {
        if ($eventName === SubtaskModel::EVENT_UPDATE || $eventName === SubtaskModel::EVENT_CREATE_UPDATE) {
            return self::getSubtaskUpdateEventKeys($eventData);
        }

        $eventKey = self::getEventKey($eventName);
        return $eventKey === '' ? array() : array($eventKey);
    }

    /**
     * Return the granular subtask-update rule keys matching the changed fields.
     *
     * @param array $eventData
     * @return string[]
     */
    protected static function getSubtaskUpdateEventKeys(array $eventData)
    {
        if (empty($eventData['changes']) || ! is_array($eventData['changes'])) {
            return array('subtask_update_other');
        }

        $keys = array();
        foreach (array_keys($eventData['changes']) as $field) {
            switch ($field) {
                case 'title':
                    $keys[] = 'subtask_update_title';
                    break;
                case 'status':
                    $keys[] = self::getSubtaskStatusUpdateEventKey($eventData);
                    break;
                case 'user_id':
                    $keys[] = 'subtask_update_assignee';
                    break;
                case 'time_estimated':
                case 'time_spent':
                    $keys[] = 'subtask_update_time_tracking';
                    break;
                case 'id':
                case 'task_id':
                case 'position':
                    break;
                default:
                    $keys[] = 'subtask_update_other';
            }
        }

        $keys = array_values(array_unique($keys));
        return empty($keys) ? array('subtask_update_other') : $keys;
    }

    /**
     * Return the subtask status-transition rule key for the resulting status.
     *
     * @param array $eventData
     * @return string
     */
    protected static function getSubtaskStatusUpdateEventKey(array $eventData)
    {
        if (! isset($eventData['subtask']['status'])) {
            return 'subtask_update_status';
        }

        switch ((int) $eventData['subtask']['status']) {
            case SubtaskModel::STATUS_TODO:
                return 'subtask_update_status_todo';
            case SubtaskModel::STATUS_INPROGRESS:
                return 'subtask_update_status_inprogress';
            case SubtaskModel::STATUS_DONE:
                return 'subtask_update_status_done';
            default:
                return 'subtask_update_status';
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
            case 'subtask_update_status_todo':
            case 'subtask_update_status_inprogress':
            case 'subtask_update_status_done':
                return 'subtask_update_status';
            case 'subtask_update_title':
            case 'subtask_update_status':
            case 'subtask_update_assignee':
            case 'subtask_update_time_tracking':
            case 'subtask_update_other':
                return 'subtask_update';
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
     * Map split event keys back through every older coarse key they can inherit.
     *
     * @param string $eventKey
     * @return string[]
     */
    public static function getLegacyEventKeys($eventKey)
    {
        $keys = array();

        $legacyKey = self::getLegacyEventKey($eventKey);
        while ($legacyKey !== '' && ! in_array($legacyKey, $keys, true)) {
            $keys[] = $legacyKey;
            $legacyKey = self::getLegacyEventKey($legacyKey);
        }

        return array_values(array_unique($keys));
    }

    /**
     * Discord event defaults: all enabled except noisy task moves and noisy
     * subtask updates. For subtasks, only create, completion and delete are
     * Discord-on by default.
     *
     * @param string $eventKey
     * @return bool
     */
    public static function isDiscordEventDefaultEnabled($eventKey)
    {
        if (! self::supportsDiscordProjectEvent($eventKey) || strpos($eventKey, 'task_move_') === 0) {
            return false;
        }

        if (strpos($eventKey, 'subtask_') === 0) {
            return in_array($eventKey, self::getDefaultNotifiedSubtaskEventKeys(), true);
        }

        return true;
    }

    /**
     * Project Email suppression defaults. Noisy subtask updates are suppressed
     * by default so only subtask create, completion and delete produce project
     * notifications unless a project opts in.
     *
     * @param string $eventKey
     * @return bool
     */
    public static function isProjectEmailSuppressionDefaultEnabled($eventKey)
    {
        return in_array($eventKey, self::getDefaultSuppressedSubtaskEventKeys(), true);
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

        foreach (self::getLegacyEventKeys($eventKey) as $legacyKey) {
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
        return self::isMetadataEnabled($eventKey, $metadata, self::META_SUPPRESS_EMAIL_PREFIX)
            || (! self::hasMetadataOverride($eventKey, $metadata, self::META_SUPPRESS_EMAIL_PREFIX)
                && self::isProjectEmailSuppressionDefaultEnabled($eventKey));
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isTaskDiscordMuted($eventKey, array $metadata)
    {
        return self::isMetadataEnabled($eventKey, $metadata, self::META_TASK_MUTE_DISCORD_PREFIX);
    }

    /**
     * @param string $eventKey
     * @param array  $metadata
     * @return bool
     */
    public static function isTaskEmailMuted($eventKey, array $metadata)
    {
        return self::isMetadataEnabled($eventKey, $metadata, self::META_TASK_MUTE_EMAIL_PREFIX);
    }

    /**
     * Read a boolean metadata flag, allowing new split keys to inherit an older
     * coarse setting until the project/task stores an explicit split-key value.
     *
     * @param string $eventKey
     * @param array  $metadata
     * @param string $prefix
     * @return bool
     */
    protected static function isMetadataEnabled($eventKey, array $metadata, $prefix)
    {
        $metadataKey = $prefix.$eventKey;
        if (array_key_exists($metadataKey, $metadata)) {
            return (string) $metadata[$metadataKey] === '1';
        }

        foreach (self::getLegacyEventKeys($eventKey) as $legacyKey) {
            $legacyMetadataKey = $prefix.$legacyKey;
            if (array_key_exists($legacyMetadataKey, $metadata)) {
                return (string) $metadata[$legacyMetadataKey] === '1';
            }
        }

        return false;
    }

    /**
     * Return true when metadata explicitly stores this key or any legacy key.
     *
     * @param string $eventKey
     * @param array  $metadata
     * @param string $prefix
     * @return bool
     */
    protected static function hasMetadataOverride($eventKey, array $metadata, $prefix)
    {
        if (array_key_exists($prefix.$eventKey, $metadata)) {
            return true;
        }

        foreach (self::getLegacyEventKeys($eventKey) as $legacyKey) {
            if (array_key_exists($prefix.$legacyKey, $metadata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    protected static function getDefaultNotifiedSubtaskEventKeys()
    {
        return array(
            'subtask_create',
            'subtask_update_status_done',
            'subtask_delete',
        );
    }

    /**
     * @return string[]
     */
    protected static function getDefaultSuppressedSubtaskEventKeys()
    {
        return array(
            'subtask_update_title',
            'subtask_update_status_todo',
            'subtask_update_status_inprogress',
            'subtask_update_assignee',
            'subtask_update_time_tracking',
            'subtask_update_other',
        );
    }
}
