<?php

namespace Kanboard\Plugin\Discord\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
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
 * Two delivery paths (see Kanboard\Job\NotificationJob):
 *   - notifyProject(): fired for regular project events. For task lifecycle
 *     cards, mentions the assignee's mapped Discord user (if any). For new
 *     comments with @mentions, mentions the mapped project members referenced in
 *     the comment instead of the assignee.
 *   - notifyUser(): fired for task-description "@mention" events once per
 *     mentioned user. Comment @mentions are handled by notifyProject() to avoid
 *     duplicate Discord cards.
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
    const META_EVENT_PREFIX = 'discord_event_';

    /**
     * Events exposed in the project Discord settings.
     *
     * @access public
     * @return array
     */
    public static function getEventGroups()
    {
        return array(
            t('Tasks') => array(
                'task_create' => t('Task created'),
                'task_update' => t('Task updated, moved, or assigned'),
                'task_close_open' => t('Task closed or reopened'),
                'task_overdue' => t('Task overdue'),
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
            t('Files and links') => array(
                'file_create' => t('File attached'),
                'file_delete' => t('File removed'),
                'task_link' => t('Task link changed'),
            ),
            t('Mentions') => array(
                'mention' => t('Task description @mentions'),
            ),
        );
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
        return self::META_EVENT_PREFIX.$key;
    }

    /**
     * Flat list of event-toggle keys.
     *
     * @access protected
     * @return string[]
     */
    protected static function getEventKeys()
    {
        $keys = array();

        foreach (self::getEventGroups() as $options) {
            $keys = array_merge($keys, array_keys($options));
        }

        return $keys;
    }

    /**
     * Reached for Kanboard user-notification deliveries. This plugin only sends
     * Discord user pings for explicit @mention events; regular user
     * notifications are intentionally handled by notifyProject() so the project
     * channel receives one card and, when appropriate, one assignee ping.
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
     * one or more mapped Discord users who are members of the project, those
     * people are the intended beneficiaries. Ping them on the project comment
     * card and do not also ping the assignee. Only when the comment does not
     * mention a mapped project member do we fall back to the task assignee.
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
            $mention = $this->getMentionsForComment($eventData['comment']['comment'], $projectId, $authorId);

            if ($mention !== '') {
                return $mention;
            }
        }

        return $this->buildMention($this->getAssigneeId($eventData['task']));
    }

    /**
     * Build Discord mentions for mapped project members referenced in comment text.
     *
     * The comment card should ping the person being asked for attention, not the
     * current assignee. A valid Discord ID is the user's opt-in signal for this
     * plugin-level ping; the project event filter controls whether comment cards
     * are sent at all.
     *
     * @access protected
     * @param  string  $text
     * @param  integer $projectId
     * @param  integer $excludeUserId  User id to ignore (the comment author).
     * @return string
     */
    protected function getMentionsForComment($text, $projectId, $excludeUserId = 0)
    {
        if ($projectId <= 0 || $text === '' || ! preg_match_all('/@([^\s,!:?]+)/', $text, $matches)) {
            return '';
        }

        $usernames = array_map(function ($username) {
            return rtrim($username, '.');
        }, $matches[1]);

        $users = $this->db->table(\Kanboard\Model\UserModel::TABLE)
            ->columns('id')
            ->in('username', array_unique($usernames))
            ->findAll();

        $mentions = array();

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            if ($userId === (int) $excludeUserId || ! $this->projectPermissionModel->isMember($projectId, $userId)) {
                continue;
            }

            $mention = $this->buildMention($userId);
            if ($mention !== '') {
                $mentions[$userId] = $mention;
            }
        }

        return implode(' ', array_values($mentions));
    }

    /**
     * Whether a Discord event is enabled for a project.
     *
     * Projects created before event filtering have no event metadata. That state
     * means "all events enabled" for backward compatibility. Once any event
     * toggle is saved, only explicit "1" values are delivered.
     *
     * @access protected
     * @param  integer $projectId
     * @param  string  $eventName
     * @return boolean
     */
    protected function isEventEnabled($projectId, $eventName)
    {
        $metadata = $this->projectMetadataModel->getAll($projectId);
        $eventKey = $this->getEventKey($eventName);
        $hasEventConfig = false;

        foreach (self::getEventKeys() as $key) {
            if (array_key_exists(self::getEventMetadataKey($key), $metadata)) {
                $hasEventConfig = true;
                break;
            }
        }

        if (! $hasEventConfig) {
            return true;
        }

        if ($eventKey === '') {
            return false;
        }

        return isset($metadata[self::getEventMetadataKey($eventKey)])
            && (string) $metadata[self::getEventMetadataKey($eventKey)] === '1';
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
        switch ($eventName) {
            case TaskModel::EVENT_CREATE:
                return 'task_create';
            case TaskModel::EVENT_UPDATE:
            case TaskModel::EVENT_CREATE_UPDATE:
            case TaskModel::EVENT_ASSIGNEE_CHANGE:
            case TaskModel::EVENT_MOVE_PROJECT:
            case TaskModel::EVENT_MOVE_COLUMN:
            case TaskModel::EVENT_MOVE_POSITION:
            case TaskModel::EVENT_MOVE_SWIMLANE:
                return 'task_update';
            case TaskModel::EVENT_CLOSE:
            case TaskModel::EVENT_OPEN:
                return 'task_close_open';
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
            case TaskLinkModel::EVENT_DELETE:
                return 'task_link';
            case TaskModel::EVENT_USER_MENTION:
            case CommentModel::EVENT_USER_MENTION:
                return 'mention';
            default:
                return '';
        }
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
