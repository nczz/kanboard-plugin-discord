<?php

namespace Kanboard\Plugin\Discord\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Discord\Notification\EventRegistry;

/**
 * Discord plugin-owned settings storage.
 */
class DiscordSettingsModel extends Base
{
    const PROJECT_SETTINGS_TABLE = 'discord_project_settings';
    const PROJECT_EVENT_RULES_TABLE = 'discord_project_event_rules';
    const TASK_EVENT_RULES_TABLE = 'discord_task_event_rules';
    const USER_SETTINGS_TABLE = 'discord_user_settings';

    /**
     * @param integer $projectId
     * @return array
     */
    public function getProjectSettings($projectId)
    {
        $row = $this->db->table(self::PROJECT_SETTINGS_TABLE)
            ->eq('project_id', $projectId)
            ->findOne();

        return array(
            'webhook_url'    => empty($row['webhook_url']) ? '' : (string) $row['webhook_url'],
            'excerpt_length' => isset($row['excerpt_length']) && $row['excerpt_length'] !== null ? (string) (int) $row['excerpt_length'] : '',
        );
    }

    /**
     * @param integer $projectId
     * @param array   $values
     * @return boolean
     */
    public function saveProjectSettings($projectId, array $values)
    {
        $webhookUrl = isset($values['webhook_url']) ? trim((string) $values['webhook_url']) : '';
        $excerptLength = null;

        if (isset($values['excerpt_length']) && $values['excerpt_length'] !== '') {
            $excerptLength = max(0, (int) $values['excerpt_length']);
        }

        return $this->upsert(
            self::PROJECT_SETTINGS_TABLE,
            array('project_id' => (int) $projectId),
            array(
                'webhook_url'    => $webhookUrl,
                'excerpt_length' => $excerptLength,
            )
        );
    }

    /**
     * @param integer $projectId
     * @return string
     */
    public function getProjectWebhookUrl($projectId)
    {
        $value = $this->db->table(self::PROJECT_SETTINGS_TABLE)
            ->eq('project_id', $projectId)
            ->findOneColumn('webhook_url');

        return trim((string) $value);
    }

    /**
     * @param integer $projectId
     * @return string|null
     */
    public function getProjectExcerptLength($projectId)
    {
        $row = $this->db->table(self::PROJECT_SETTINGS_TABLE)
            ->columns('excerpt_length')
            ->eq('project_id', $projectId)
            ->findOne();

        if (empty($row) || $row['excerpt_length'] === null || $row['excerpt_length'] === '') {
            return null;
        }

        return (string) (int) $row['excerpt_length'];
    }

    /**
     * @param integer $projectId
     * @return array
     */
    public function getProjectEventRules($projectId)
    {
        $rows = $this->db->table(self::PROJECT_EVENT_RULES_TABLE)
            ->eq('project_id', $projectId)
            ->findAll();

        $rules = array();
        foreach ($rows as $row) {
            $rules[$row['event_key']] = array(
                'discord_enabled'  => $this->normalizeNullableBoolean($row['discord_enabled']),
                'email_suppressed' => $this->normalizeNullableBoolean($row['email_suppressed']),
            );
        }

        return $rules;
    }

    /**
     * @param integer $projectId
     * @param array   $rules
     * @return boolean
     */
    public function saveProjectEventRules($projectId, array $rules)
    {
        $projectId = (int) $projectId;
        $knownEventKeys = array_flip(EventRegistry::getEventKeys());
        $timestamp = time();
        $userId = $this->getCurrentUserId();
        $results = array();

        $this->db->startTransaction();
        $this->db->table(self::PROJECT_EVENT_RULES_TABLE)->eq('project_id', $projectId)->remove();

        foreach ($rules as $eventKey => $rule) {
            if (! isset($knownEventKeys[$eventKey]) || ! is_array($rule)) {
                continue;
            }

            $discordEnabled = $this->normalizeNullableBoolean(isset($rule['discord_enabled']) ? $rule['discord_enabled'] : null);
            $emailSuppressed = $this->normalizeNullableBoolean(isset($rule['email_suppressed']) ? $rule['email_suppressed'] : null);

            if ($discordEnabled === null && $emailSuppressed === null) {
                continue;
            }

            $results[] = $this->db->table(self::PROJECT_EVENT_RULES_TABLE)->insert(array(
                'project_id'        => $projectId,
                'event_key'         => $eventKey,
                'discord_enabled'   => $discordEnabled,
                'email_suppressed'  => $emailSuppressed,
                'changed_on'        => $timestamp,
                'changed_by'        => $userId,
            ));
        }

        $this->db->closeTransaction();
        return ! in_array(false, $results, true);
    }

    /**
     * @param integer $taskId
     * @return array
     */
    public function getTaskEventRules($taskId)
    {
        $rows = $this->db->table(self::TASK_EVENT_RULES_TABLE)
            ->eq('task_id', $taskId)
            ->findAll();

        $rules = array();
        foreach ($rows as $row) {
            $rules[$row['event_key']] = array(
                'mute_discord' => $this->normalizeBoolean($row['mute_discord']),
                'mute_email'   => $this->normalizeBoolean($row['mute_email']),
            );
        }

        return $rules;
    }

    /**
     * @param integer $taskId
     * @param array   $rules
     * @return boolean
     */
    public function saveTaskEventRules($taskId, array $rules)
    {
        $taskId = (int) $taskId;
        $knownEventKeys = array_flip(EventRegistry::getEventKeys());
        $timestamp = time();
        $userId = $this->getCurrentUserId();
        $results = array();

        $this->db->startTransaction();
        $this->db->table(self::TASK_EVENT_RULES_TABLE)->eq('task_id', $taskId)->remove();

        foreach ($rules as $eventKey => $rule) {
            if (! isset($knownEventKeys[$eventKey]) || ! is_array($rule)) {
                continue;
            }

            $muteDiscord = EventRegistry::supportsDiscordProjectEvent($eventKey)
                ? $this->normalizeBoolean(isset($rule['mute_discord']) ? $rule['mute_discord'] : 0)
                : 0;
            $muteEmail = $this->normalizeBoolean(isset($rule['mute_email']) ? $rule['mute_email'] : 0);

            if ($muteDiscord === 0 && $muteEmail === 0) {
                continue;
            }

            $results[] = $this->db->table(self::TASK_EVENT_RULES_TABLE)->insert(array(
                'task_id'      => $taskId,
                'event_key'    => $eventKey,
                'mute_discord' => $muteDiscord,
                'mute_email'   => $muteEmail,
                'changed_on'   => $timestamp,
                'changed_by'   => $userId,
            ));
        }

        $this->db->closeTransaction();
        return ! in_array(false, $results, true);
    }

    /**
     * @param integer $userId
     * @return array
     */
    public function getUserSettings($userId)
    {
        $row = $this->db->table(self::USER_SETTINGS_TABLE)
            ->eq('user_id', $userId)
            ->findOne();

        return array(
            'discord_user_id'                 => empty($row['discord_user_id']) ? '' : (string) $row['discord_user_id'],
            'default_notifications_processed' => empty($row['default_notifications_processed']) ? 0 : 1,
        );
    }

    /**
     * @param integer $userId
     * @return string
     */
    public function getDiscordUserId($userId)
    {
        if (empty($userId)) {
            return '';
        }

        $settings = $this->getUserSettings($userId);
        $discordId = trim($settings['discord_user_id']);

        return ctype_digit($discordId) ? $discordId : '';
    }

    /**
     * @param integer $userId
     * @param string  $discordUserId
     * @return boolean
     */
    public function saveDiscordUserId($userId, $discordUserId)
    {
        return $this->upsert(
            self::USER_SETTINGS_TABLE,
            array('user_id' => (int) $userId),
            array('discord_user_id' => trim((string) $discordUserId))
        );
    }

    /**
     * @param integer $userId
     * @return boolean
     */
    public function isDefaultNotificationsProcessed($userId)
    {
        $settings = $this->getUserSettings($userId);
        return (int) $settings['default_notifications_processed'] === 1;
    }

    /**
     * @param integer $userId
     * @return boolean
     */
    public function markDefaultNotificationsProcessed($userId)
    {
        return $this->upsert(
            self::USER_SETTINGS_TABLE,
            array('user_id' => (int) $userId),
            array('default_notifications_processed' => 1)
        );
    }

    /**
     * @param integer $srcProjectId
     * @param integer $dstProjectId
     * @return boolean
     */
    public function duplicate($srcProjectId, $dstProjectId)
    {
        $settings = $this->getProjectSettings($srcProjectId);
        $rules = $this->getProjectEventRules($srcProjectId);

        return $this->saveProjectSettings($dstProjectId, $settings)
            && $this->saveProjectEventRules($dstProjectId, $rules);
    }

    /**
     * @param integer $srcTaskId
     * @param integer $dstTaskId
     * @return boolean
     */
    public function duplicateTaskRules($srcTaskId, $dstTaskId)
    {
        return $this->saveTaskEventRules($dstTaskId, $this->getTaskEventRules($srcTaskId));
    }

    /**
     * @param string $table
     * @param array  $keys
     * @param array  $values
     * @return boolean
     */
    protected function upsert($table, array $keys, array $values)
    {
        $timestamp = time();
        $userId = $this->getCurrentUserId();
        $values += array('changed_on' => $timestamp, 'changed_by' => $userId);

        $query = $this->db->table($table);
        foreach ($keys as $column => $value) {
            $query->eq($column, $value);
        }

        if ($query->exists()) {
            $query = $this->db->table($table);
            foreach ($keys as $column => $value) {
                $query->eq($column, $value);
            }
            return $query->update($values);
        }

        return $this->db->table($table)->insert($keys + $values);
    }

    /**
     * @param mixed $value
     * @return integer
     */
    protected function normalizeBoolean($value)
    {
        return (string) $value === '1' || $value === true || $value === 1 ? 1 : 0;
    }

    /**
     * @param mixed $value
     * @return integer|null
     */
    protected function normalizeNullableBoolean($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeBoolean($value);
    }

    /**
     * @return integer
     */
    protected function getCurrentUserId()
    {
        return (int) $this->userSession->getId();
    }
}
