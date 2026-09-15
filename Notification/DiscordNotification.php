<?php

namespace Kanboard\Plugin\Discord\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Discord\Builder\EmbedBuilder;

/**
 * Discord Notification
 *
 * Sends Kanboard events to a per-project Discord channel via an Incoming Webhook.
 *
 * Two delivery paths (see Kanboard\Job\NotificationJob):
 *   - notifyProject(): fired for regular project events. Mentions the task
 *     assignee's mapped Discord user (if any).
 *   - notifyUser():    fired only for "@mention" events, once per mentioned user.
 *     Sends to the project webhook and pings that specific user's Discord ID.
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
        if (! in_array($eventName, array(TaskModel::EVENT_USER_MENTION, CommentModel::EVENT_USER_MENTION), true)) {
            return;
        }

        if (empty($eventData['task']['project_id'])) {
            return;
        }

        $projectId = (int) $eventData['task']['project_id'];
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
     * a project member who can receive a Discord @mention via Kanboard's user
     * notification path, Kanboard fires notifyUser() for that user on
     * comment.create, so this channel card must NOT additionally ping the task
     * assignee (which would ping the wrong person). Only when no Discord
     * @mention can be dispatched do we fall back to the task assignee.
     *
     * NOTE: this suppression applies to comment.create ONLY. Kanboard does not
     * dispatch the @mention path for comment.update (see CommentEventJob), so an
     * edited comment has no dedicated notification to hand the mentioned users
     * off to. For updates (and every other event) we therefore keep the existing
     * behaviour and ping the task assignee, so the notification always reaches
     * someone.
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
            // Kanboard never sends an @mention to the comment author (even a
            // self-mention), so exclude them here too; otherwise a self-mention
            // would suppress the assignee ping and the comment would notify nobody.
            $authorId = (int) ($eventData['comment']['user_id'] ?? 0);

            // Comment mentions another member who can receive the dedicated
            // Discord @mention notification; do not ping the assignee.
            if ($this->commentMentionsMember($eventData['comment']['comment'], $projectId, $authorId)) {
                return '';
            }
        }

        return $this->buildMention($this->getAssigneeId($eventData['task']));
    }

    /**
     * Mirrors the conditions that make this plugin's Kanboard user-notification
     * path deliver a real Discord ping: scan "@username" tokens, keep only
     * notification-enabled users, ignore the comment author, confirm project
     * membership, selected Discord notification type, and a valid Discord ID.
     *
     * @access protected
     * @param  string  $text
     * @param  integer $projectId
     * @param  integer $excludeUserId  User id to ignore (the comment author).
     * @return boolean
     */
    protected function commentMentionsMember($text, $projectId, $excludeUserId = 0)
    {
        if ($projectId <= 0 || $text === '' || ! preg_match_all('/@([^\s,!:?]+)/', $text, $matches)) {
            return false;
        }

        $usernames = array_map(function ($username) {
            return rtrim($username, '.');
        }, $matches[1]);

        $users = $this->db->table(\Kanboard\Model\UserModel::TABLE)
            ->columns('id')
            ->in('username', array_unique($usernames))
            ->eq('notifications_enabled', 1)
            ->findAll();

        foreach ($users as $user) {
            if ((int) $user['id'] === (int) $excludeUserId) {
                continue;
            }
            if ($this->projectPermissionModel->isMember($projectId, $user['id'])
                && $this->userCanReceiveDiscordMention($user['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Kanboard can route a dedicated Discord @mention notification to a user.
     *
     * @access protected
     * @param  integer $userId
     * @return boolean
     */
    protected function userCanReceiveDiscordMention($userId)
    {
        return in_array(self::TYPE, $this->userNotificationTypeModel->getSelectedTypes($userId), true)
            && $this->buildMention($userId) !== '';
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
