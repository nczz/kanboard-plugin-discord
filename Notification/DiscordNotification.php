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
 * Sends Kanboard events to Discord via a project Incoming Webhook, falling back
 * to the global default Incoming Webhook when a project does not set one.
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
 * Sending is gated by the presence of either a valid project webhook URL or a
 * valid global default webhook URL, so it is inert when neither is configured.
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
     * Application setting key holding the global fallback Discord Incoming Webhook URL.
     */
    const CONFIG_WEBHOOK_URL = 'discord_default_webhook_url';

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

        $messageContext = $this->buildMentionContext($user['id']);
        if ($messageContext['content'] === '') {
            return;
        }

        $this->send($webhook, $project, $eventName, $eventData, $messageContext);
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

                    $messageContext = $this->buildMentionContext($this->getAssigneeId($task));
                    $this->send($webhook, $project, TaskModel::EVENT_OVERDUE, $singleEvent, $messageContext);
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
                $messageContext = $this->buildMentionContext($this->getAssigneeId($task));
                $this->send($webhook, $project, $eventName, $singleEvent, $messageContext);
            }
            return;
        }

        if (! empty($eventData['task']) && $this->isTaskMuted($eventName, $eventData['task'])) {
            return;
        }

        $messageContext = array();
        if (! empty($eventData['task'])) {
            $messageContext = $this->getMessageContextForEvent($eventName, $eventData);
        }

        $this->send($webhook, $project, $eventName, $eventData, $messageContext);
    }

    /**
     * Decide what Discord message content should accompany the project card.
     *
     * For a NEW comment (comment.create), preserve the Kanboard comment text and
     * replace resolvable Kanboard @username tokens at their original positions
     * with Discord <@snowflake> mentions. Unresolved tokens stay unchanged. A
     * real project-member mention suppresses assignee fallback even when that
     * member has no Discord id, because pinging the assignee would notify the
     * wrong person.
     *
     * This is intentionally different from regular task lifecycle events, where
     * the assignee is the person expected to act on the card.
     *
     * @access protected
     * @param  string $eventName
     * @param  array  $eventData
     * @return array{content: string, users: string[]}  Message content and explicit Discord users allowed to ping.
     */
    protected function getMessageContextForEvent($eventName, array $eventData)
    {
        if ($eventName === CommentModel::EVENT_CREATE && isset($eventData['comment']['comment'])) {
            $projectId = (int) ($eventData['task']['project_id'] ?? 0);
            $authorId = (int) ($eventData['comment']['user_id'] ?? 0);
            $commentContext = $this->getCommentContentContext($eventData['comment']['comment'], $projectId, $authorId);

            if ($commentContext['has_member_mention']) {
                return array(
                    'content' => $commentContext['content'],
                    'users'   => $commentContext['users'],
                );
            }

            $assigneeContext = $this->buildMentionContext($this->getAssigneeId($eventData['task']));
            if ($assigneeContext['content'] !== '') {
                if ($commentContext['content'] === '') {
                    return $assigneeContext;
                }

                return array(
                    'content' => $assigneeContext['content'].' '.$commentContext['content'],
                    'users'   => $assigneeContext['users'],
                );
            }

            return array('content' => $commentContext['content'], 'users' => array());
        }

        return $this->buildMentionContext($this->getAssigneeId($eventData['task']));
    }

    /**
     * Preserve comment text while replacing resolvable Kanboard @username tokens
     * inline with Discord mentions.
     *
     * The parser intentionally mirrors the plugin's historical mention detection
     * pattern. A trailing "." is treated as punctuation rather than part of the
     * username, matching the previous rtrim('.') behavior.
     *
     * @access protected
     * @param  string  $text
     * @param  integer $projectId
     * @param  integer $excludeUserId  User id to ignore (the comment author).
     * @return array{has_member_mention: bool, content: string, users: string[]}
     */
    protected function getCommentContentContext($text, $projectId, $excludeUserId = 0)
    {
        $text = (string) $text;

        if ($projectId <= 0 || $text === '' || ! preg_match_all('/@([^\s,!:?]+)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return array('has_member_mention' => false, 'content' => $text, 'users' => array());
        }

        $usernames = array();
        foreach ($matches[1] as $match) {
            $username = rtrim($match[0], '.');
            if ($username !== '') {
                $usernames[$username] = $username;
            }
        }

        if (empty($usernames)) {
            return array('has_member_mention' => false, 'content' => $text, 'users' => array());
        }

        $mentionedUsers = $this->db->table(\Kanboard\Model\UserModel::TABLE)
            ->columns('id', 'username')
            ->in('username', array_values($usernames))
            ->findAll();

        $usersByUsername = array();
        foreach ($mentionedUsers as $user) {
            $usersByUsername[$user['username']] = $user;
        }

        $hasMemberMention = false;
        $discordUserIds = array();
        $content = '';
        $cursor = 0;

        foreach ($matches[0] as $index => $fullMatch) {
            $rawMention = $fullMatch[0];
            $mentionOffset = $fullMatch[1];
            $rawUsername = $matches[1][$index][0];
            $username = rtrim($rawUsername, '.');
            $punctuation = substr($rawUsername, strlen($username));
            $replacement = $rawMention;

            if ($username !== '' && isset($usersByUsername[$username])) {
                $userId = (int) $usersByUsername[$username]['id'];
                if ($userId !== (int) $excludeUserId && $this->projectPermissionModel->isMember($projectId, $userId)) {
                    $hasMemberMention = true;
                    $discordId = $this->getDiscordUserId($userId);
                    if ($discordId !== '' && (isset($discordUserIds[$discordId]) || count($discordUserIds) < EmbedBuilder::LIMIT_ALLOWED_MENTION_USERS)) {
                        $discordUserIds[$discordId] = $discordId;
                        $replacement = '<@'.$discordId.'>'.$punctuation;
                    }
                }
            }

            $content .= substr($text, $cursor, $mentionOffset - $cursor).$replacement;
            $cursor = $mentionOffset + strlen($rawMention);
        }

        $content .= substr($text, $cursor);

        return array(
            'has_member_mention' => $hasMemberMention,
            'content'            => $content,
            'users'              => array_values($discordUserIds),
        );
    }

    /**
     * Build a message mention context for a Kanboard user id.
     *
     * @access protected
     * @param  integer $userId
     * @return array{content: string, users: string[]}
     */
    protected function buildMentionContext($userId)
    {
        $discordId = $this->getDiscordUserId($userId);
        if ($discordId === '') {
            return array('content' => '', 'users' => array());
        }

        return array('content' => '<@'.$discordId.'>', 'users' => array($discordId));
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
     * Resolve and validate the project's webhook URL, falling back to the global
     * default webhook only when the project has no webhook configured.
     * @access protected
     * @param  integer $projectId
     * @return string  Empty string when unset or blocked.
     */
    protected function getWebhookUrl($projectId)
    {
        $url = trim($this->projectMetadataModel->get($projectId, self::META_WEBHOOK_URL, ''));
        $source = 'project '.$projectId;

        if ($url === '') {
            $url = trim($this->configModel->getOption(self::CONFIG_WEBHOOK_URL, ''));
            $source = 'global default';
        }

        if ($url === '') {
            return '';
        }

        // Only allow official Discord webhook endpoints.
        if (! $this->isDiscordWebhookUrl($url)) {
            $this->logger->error('Discord plugin: invalid webhook URL for '.$source);
            return '';
        }

        // SSRF protection: reuse Kanboard's private-network guard unless the admin
        // explicitly allows private networks for webhooks.
        if (! WEBHOOK_ALLOW_PRIVATE_NETWORKS && $this->httpClient->isPrivateURL($url)) {
            $this->logger->info('Discord plugin: blocked webhook to private network URL for '.$source);
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
     * Return a mapped Discord user snowflake for a Kanboard user id.
     *
     * @access protected
     * @param  integer $userId
     * @return string  Empty when the user has no valid Discord ID mapped.
     */
    protected function getDiscordUserId($userId)
    {
        if (empty($userId)) {
            return '';
        }

        $discordId = trim($this->userMetadataModel->get($userId, self::META_USER_ID, ''));

        // Discord user IDs (snowflakes) are numeric strings.
        return ctype_digit($discordId) ? $discordId : '';
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
     * @param  array  $messageContext
     */
    protected function send($webhook, array $project, $eventName, array $eventData, array $messageContext = array())
    {
        $payload = $this->embedBuilder->build($project, $eventName, $eventData, $messageContext);

        // Fire-and-forget: never block or surface errors to the triggering request.
        $this->httpClient->postJson($webhook, $payload, array(), false, false);
    }
}
