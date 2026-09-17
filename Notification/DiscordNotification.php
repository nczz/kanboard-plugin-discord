<?php

namespace Kanboard\Plugin\Discord\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Core\Translator;
use Kanboard\Model\CommentModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskFileModel;
use Kanboard\Model\TaskLinkModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Discord\Builder\EmbedBuilder;

/**
 * Discord Notification
 *
 * Sends Kanboard events to a per-project Discord channel via an Incoming Webhook.
 *
 * Two delivery paths (see Kanboard\Job\NotificationJob and
 * Kanboard\Console\TaskOverdueNotificationCommand):
 *   - notifyProject(): fired for regular project events. For task lifecycle
 *     cards, mentions the assignee's mapped Discord user (if any). For new
 *     comments with @mentions, mentions the mapped project members referenced in
 *     the comment instead of the assignee.
 *   - notifyUser(): fired for task-description "@mention" events once per
 *     mentioned user, and for overdue task batches because Kanboard core sends
 *     overdue notifications only through the user-notification path. Comment
 *     @mentions are handled by notifyProject() to avoid duplicate Discord cards.
 *
 * Sending is gated by the presence of a webhook URL in the project metadata,
 * so it is inert for projects that have not configured Discord.
 *
 * @package Kanboard\Plugin\Discord\Notification
 */
class DiscordNotification extends Base implements NotificationInterface
{
    /**
     * Project metadata key holding the Discord Incoming Webhook URL.
     */
    const META_WEBHOOK_URL = 'discord_webhook_url';

    /**
     * Notification type key registered with Kanboard.
     */
    const TYPE = 'discord';

    /**
     * User metadata key holding the numeric Discord User ID (for @mentions).
     */
    const META_USER_ID = 'discord_user_id';

    /**
     * Project metadata prefix for per-event Discord delivery toggles.
     */
    const META_EVENT_PREFIX = EventRegistry::META_DISCORD_EVENT_PREFIX;

    /**
     * Project metadata prefix for per-event Email suppression toggles.
     */
    const META_SUPPRESS_EMAIL_PREFIX = EventRegistry::META_SUPPRESS_EMAIL_PREFIX;

    /**
     * Per-process overdue de-duplication. Kanboard core can notify several
     * users/managers about the same overdue task; the Discord project channel
     * must still receive only one card per overdue task.
     *
     * @var array
     */
    protected static $sentOverdueTaskKeys = array();

    /**
     * Events exposed in the project Discord settings.
     *
     * @access public
     * @return array
     */
    public static function getEventGroups()
    {
        return EventRegistry::getEventGroups();
    }

    /**
     * Build project metadata key for an event-toggle option.
     *
     * @access public
     * @param  string $key
     * @return string
     */
    public static function getEventMetadataKey($key)
    {
        return EventRegistry::getDiscordProjectMetadataKey($key);
    }

    /**
     * Build project metadata key for an Email suppression option.
     *
     * @access public
     * @param  string $key
     * @return string
     */
    public static function getSuppressEmailMetadataKey($key)
    {
        return EventRegistry::getSuppressEmailProjectMetadataKey($key);
    }

    /**
     * Whether an event-toggle option is enabled when no metadata has been saved.
     *
     * @access public
     * @param  string $key
     * @return boolean
     */
    public static function isEventDefaultEnabled($key)
    {
        return EventRegistry::isDiscordEventDefaultEnabled($key);
    }

    /**
     * Resolve a Discord toggle value from project metadata.
     *
     * @access public
     * @param  string $key
     * @param  array  $metadata
     * @return boolean
     */
    public static function isEventMetadataEnabled($key, array $metadata)
    {
        return EventRegistry::isDiscordProjectEventEnabled($key, $metadata);
    }

    /**
     * Resolve an Email suppression value from project metadata.
     *
     * @access public
     * @param  string $key
     * @param  array  $metadata
     * @return boolean
     */
    public static function isEmailSuppressedMetadataEnabled($key, array $metadata)
    {
        return EventRegistry::isProjectEmailSuppressed($key, $metadata);
    }

    /**
     * Reached for Kanboard user-notification deliveries. This plugin sends
     * Discord user pings for explicit task-description @mention events and
     * bridges core overdue batches into the project webhook. Regular user
     * notifications are intentionally handled by notifyProject().
     * @access public
     * @param  array  $user
     * @param  string $eventName
     * @param  array  $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
        if ($eventName === CommentModel::EVENT_USER_MENTION) {
            // Comment mentions are pinged on the project comment card itself so
            // the channel gets one complete card: who commented, content, and
            // the directly mentioned people. Avoid a second duplicate card.
            return;
        }

        if ($eventName === TaskModel::EVENT_OVERDUE) {
            if (! empty($eventData['tasks']) && is_array($eventData['tasks'])) {
                $this->sendOverdueTaskNotifications($eventData['tasks']);
            }
            return;
        }

        if ($eventName !== TaskModel::EVENT_USER_MENTION) {
            return;
        }

        if (empty($eventData['task']['project_id'])) {
            return;
        }

        $projectId = (int) $eventData['task']['project_id'];

        if (! $this->isEventEnabled($projectId, $eventName)) {
            return;
        }

        if ($this->isTaskMuted($eventName, $eventData['task'])) {
            return;
        }

        $webhook = $this->getWebhookUrl($projectId);

        if ($webhook === '') {
            return;
        }

        $project = $this->projectModel->getById($projectId);

        if (empty($project)) {
            return;
        }

        $mention = $this->buildMention($user['id']);
        if ($mention === '') {
            return;
        }

        $this->send($webhook, $project, $eventName, $eventData, $mention);
    }

    /**
     * Bridge Kanboard core overdue task lists into project-channel Discord cards.
     *
     * Core overdue checks are command-driven, not project events. The same
     * overdue task can be delivered to several Kanboard users/managers, so this
     * method groups by project and de-duplicates by project/task id for the
     * lifetime of the current PHP process.
     *
     * @access public
     * @param  array $tasks
     */
    public function sendOverdueTaskNotifications(array $tasks)
    {
        if (empty($tasks)) {
            return;
        }

        $loadedLocales = Translator::$locales;
        Translator::unload();
        Translator::load($this->configModel->get('application_language', 'en_US'));

        try {
            $queuedOverdueTaskKeys = array();
            $tasksByProject = array();
            foreach ($tasks as $task) {
                if (empty($task['project_id']) || empty($task['id'])) {
                    continue;
                }

                $projectId = (int) $task['project_id'];
                $taskKey = $this->getOverdueTaskKey($task);
                if (isset(self::$sentOverdueTaskKeys[$taskKey]) || isset($queuedOverdueTaskKeys[$taskKey])) {
                    continue;
                }

                if ($this->isTaskMuted(TaskModel::EVENT_OVERDUE, $task)) {
                    continue;
                }

                if (! isset($tasksByProject[$projectId])) {
                    $tasksByProject[$projectId] = array();
                }
                $tasksByProject[$projectId][] = $task;
                $queuedOverdueTaskKeys[$taskKey] = true;
            }

            foreach ($tasksByProject as $projectId => $projectTasks) {
                if (! $this->isEventEnabled($projectId, TaskModel::EVENT_OVERDUE)) {
                    continue;
                }

                $webhook = $this->getWebhookUrl($projectId);
                if ($webhook === '') {
                    continue;
                }

                $project = $this->projectModel->getById($projectId);
                if (empty($project)) {
                    continue;
                }

                foreach ($projectTasks as $task) {
                    $singleEvent = array(
                        'task' => $task,
                        'tasks' => array($task),
                        'project_name' => isset($task['project_name']) ? $task['project_name'] : $project['name'],
                    );

                    $mention = $this->buildMention($this->getAssigneeId($task));
                    $this->send($webhook, $project, TaskModel::EVENT_OVERDUE, $singleEvent, $mention);
                    self::$sentOverdueTaskKeys[$this->getOverdueTaskKey($task)] = true;
                }
            }
        } finally {
            Translator::$locales = $loadedLocales;
        }
    }

    /**
     * Stable de-duplication key for one overdue task card.
     *
     * @access protected
     * @param  array $task
     * @return string
     */
    protected function getOverdueTaskKey(array $task)
    {
        return (int) $task['project_id'].':'.(int) $task['id'];
    }

    /**
     * Send notification to a project.
     *
     * @access public
     * @param  array  $project
     * @param  string $eventName
     * @param  array  $eventData
     */
    public function notifyProject(array $project, $eventName, array $eventData)
    {
        if (! $this->isEventEnabled($project['id'], $eventName)) {
            return;
        }

        $webhook = $this->getWebhookUrl($project['id']);

        if ($webhook === '') {
            return;
        }
        // EVENT_OVERDUE carries a list of tasks instead of a single task.
        if ($eventName === TaskModel::EVENT_OVERDUE && ! empty($eventData['tasks'])) {
            foreach ($eventData['tasks'] as $task) {
                if ($this->isTaskMuted($eventName, $task)) {
                    continue;
                }

                $singleEvent = $eventData;
                $singleEvent['task'] = $task;
                // Reduce the tasks list to this single task so the core title
                // builder (which reads $eventData['tasks'] for overdue events)
                // renders THIS task instead of the aggregate count / first task.
                $singleEvent['tasks'] = array($task);
                $mention = $this->buildMention($this->getAssigneeId($task));
                $this->send($webhook, $project, $eventName, $singleEvent, $mention);
            }
            return;
        }

        if (! empty($eventData['task']) && $this->isTaskMuted($eventName, $eventData['task'])) {
            return;
        }

        $mention = '';
        if (! empty($eventData['task'])) {
            $mention = $this->getMentionForEvent($eventName, $eventData);
        }

        $this->send($webhook, $project, $eventName, $eventData, $mention);
    }

    /**
     * Decide who to ping on the project-channel card.
     *
     * Priority for a NEW comment (comment.create): if the comment text mentions
     * one or more Kanboard project members, those people are the intended
     * beneficiaries. Ping the mentioned members that have mapped Discord IDs. If
     * none of the mentioned members have a Discord ID, send the comment card
     * without a ping instead of falling back to the task assignee.
     *
     * This is intentionally different from regular task lifecycle events, where
     * the assignee is the person expected to act on the card.
     *
     * @access protected
     * @param  string $eventName
     * @param  array  $eventData
     * @return string  Discord mention string, or empty when nobody to ping.
     */
    protected function getMentionForEvent($eventName, array $eventData)
    {
        if ($eventName === CommentModel::EVENT_CREATE && ! empty($eventData['comment']['comment'])) {
            $projectId = (int) ($eventData['task']['project_id'] ?? 0);
            $authorId = (int) ($eventData['comment']['user_id'] ?? 0);
            $commentMentions = $this->getCommentMentions($eventData['comment']['comment'], $projectId, $authorId);

            if ($commentMentions['has_member_mention']) {
                return $commentMentions['mentions'];
            }
        }

        return $this->buildMention($this->getAssigneeId($eventData['task']));
    }

    /**
     * Resolve comment @mentions against Kanboard users and mapped Discord IDs.
     *
     * A real Kanboard project-member mention suppresses the assignee fallback
     * even when that user has no Discord ID mapped: in that case Discord receives
     * the comment card without any ping, because pinging the assignee would alert
     * the wrong person.
     *
     * @access protected
     * @param  string  $text
     * @param  integer $projectId
     * @param  integer $excludeUserId  User id to ignore (the comment author).
     * @return array{has_member_mention: bool, mentions: string}
     */
    protected function getCommentMentions($text, $projectId, $excludeUserId = 0)
    {
        if ($projectId <= 0 || $text === '' || ! preg_match_all('/@([^\s,!:?]+)/', $text, $matches)) {
            return array('has_member_mention' => false, 'mentions' => '');
        }

        $usernames = array_map(function ($username) {
            return rtrim($username, '.');
        }, $matches[1]);

        $users = $this->db->table(\Kanboard\Model\UserModel::TABLE)
            ->columns('id')
            ->in('username', array_unique($usernames))
            ->findAll();

        $hasMemberMention = false;
        $mentions = array();
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            if ($userId === (int) $excludeUserId || ! $this->projectPermissionModel->isMember($projectId, $userId)) {
                continue;
            }
            $hasMemberMention = true;

            $mention = $this->buildMention($userId);
            if ($mention !== '') {
                $mentions[$userId] = $mention;
            }
        }

        return array('has_member_mention' => $hasMemberMention, 'mentions' => implode(' ', array_values($mentions)));
    }

    /**
     * Whether a Discord event is enabled for a project.
     *
     * Events default to enabled except task move events, which are deliberately
     * quiet by default because board drag/reorder activity is usually noisy.
     * Split keys can inherit old coarse metadata where doing so preserves user
     * intent without re-enabling moves.
     *
     * @access protected
     * @param  integer $projectId
     * @param  string  $eventName
     * @return boolean
     */
    protected function isEventEnabled($projectId, $eventName)
    {
        $eventKey = EventRegistry::getEventKey($eventName);

        if ($eventKey === '') {
            return false;
        }

        return EventRegistry::isDiscordProjectEventEnabled($eventKey, $this->projectMetadataModel->getAll($projectId));
    }

    /**
     * Map Kanboard event names to project-level Discord event-toggle keys.
     *
     * @access protected
     * @param  string $eventName
     * @return string
     */
    protected function getEventKey($eventName)
    {
        return EventRegistry::getEventKey($eventName);
    }

    /**
     * Whether Discord is muted for this task and event.
     *
     * @access protected
     * @param  string $eventName
     * @param  array  $task
     * @return boolean
     */
    protected function isTaskMuted($eventName, array $task)
    {
        if (empty($task['id'])) {
            return false;
        }

        $eventKey = EventRegistry::getEventKey($eventName);
        if ($eventKey === '') {
            return false;
        }

        return EventRegistry::isTaskDiscordMuted($eventKey, $this->taskMetadataModel->getAll((int) $task['id']));
    }

    /**
     * Resolve and validate the project's webhook URL.
     *
     * @access protected
     * @param  integer $projectId
     * @return string  Empty string when unset or blocked.
     */
    protected function getWebhookUrl($projectId)
    {
        $url = trim($this->projectMetadataModel->get($projectId, self::META_WEBHOOK_URL, ''));

        if ($url === '') {
            return '';
        }

        // Only allow official Discord webhook endpoints.
        if (! $this->isDiscordWebhookUrl($url)) {
            $this->logger->error('Discord plugin: invalid webhook URL for project '.$projectId);
            return '';
        }

        // SSRF protection: reuse Kanboard's private-network guard unless the admin
        // explicitly allows private networks for webhooks.
        if (! WEBHOOK_ALLOW_PRIVATE_NETWORKS && $this->httpClient->isPrivateURL($url)) {
            $this->logger->info('Discord plugin: blocked webhook to private network URL for project '.$projectId);
            return '';
        }

        return $url;
    }

    /**
     * Validate that a URL is a Discord (or compatible) webhook endpoint over HTTPS.
     *
     * @access protected
     * @param  string $url
     * @return boolean
     */
    protected function isDiscordWebhookUrl($url)
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        $allowedHosts = array('discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com');

        $hostAllowed = false;
        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || substr($host, -strlen('.'.$allowed)) === '.'.$allowed) {
                $hostAllowed = true;
                break;
            }
        }

        if (! $hostAllowed) {
            return false;
        }

        // Discord webhook execution endpoints are exactly:
        //   /api/webhooks/{id}/{token}
        //   /api/v<N>/webhooks/{id}/{token}
        // Reject incomplete URLs and webhook subresources (/messages, /slack,
        // /github, ...), because this plugin posts native Discord webhook JSON.
        $path = isset($parts['path']) ? $parts['path'] : '';

        return preg_match('#^/api/(?:v[0-9]+/)?webhooks/[0-9]+/[A-Za-z0-9._-]+/?$#', $path) === 1;
    }

    /**
     * Build a Discord mention string ("<@id>") for a Kanboard user id.
     *
     * @access protected
     * @param  integer $userId
     * @return string  Empty when the user has no valid Discord ID mapped.
     */
    protected function buildMention($userId)
    {
        if (empty($userId)) {
            return '';
        }

        $discordId = trim($this->userMetadataModel->get($userId, self::META_USER_ID, ''));

        // Discord user IDs (snowflakes) are numeric strings.
        if ($discordId === '' || ! ctype_digit($discordId)) {
            return '';
        }

        return '<@'.$discordId.'>';
    }

    /**
     * Extract the assignee (owner) id from a task row.
     *
     * @access protected
     * @param  array $task
     * @return integer
     */
    protected function getAssigneeId(array $task)
    {
        return isset($task['owner_id']) ? (int) $task['owner_id'] : 0;
    }

    /**
     * Build the payload and POST it to the Discord webhook.
     *
     * @access protected
     * @param  string $webhook
     * @param  array  $project
     * @param  string $eventName
     * @param  array  $eventData
     * @param  string $mention
     */
    protected function send($webhook, array $project, $eventName, array $eventData, $mention = '')
    {
        $payload = $this->embedBuilder->build($project, $eventName, $eventData, $mention);

        // Fire-and-forget: never block or surface errors to the triggering request.
        $this->httpClient->postJson($webhook, $payload, array(), false, false);
    }
}
