<?php

namespace Kanboard\Plugin\Discord\Builder;

use Kanboard\Core\Base;
use Kanboard\Model\CommentModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskFileModel;
use Kanboard\Model\TaskModel;

/**
 * Discord Embed Builder
 *
 * Converts a Kanboard event into a Discord webhook payload (embed card).
 *
 * The event -> content mapping is inspired by andreaslutsch/kanboard-plugin-discord
 * but rewritten to (a) drop the external php-discord-sdk dependency by using
 * Kanboard's built-in HTTP client, and (b) add Discord user mentions for the
 * assignee / mentioned users (a feature missing from all existing plugins).
 *
 * @package Kanboard\Plugin\Discord\Builder
 */
class EmbedBuilder extends Base
{
    /**
     * Default embed color (Kanboard yellow).
     */
    const COLOR_DEFAULT = 0xF9DF18;
    const COLOR_CREATE  = 0x2ECC71; // green
    const COLOR_CLOSE   = 0x95A5A6; // grey
    const COLOR_OPEN    = 0x3498DB; // blue
    const COLOR_MENTION = 0xE67E22; // orange
    const COLOR_DELETE  = 0xE74C3C; // red

    /**
     * Build the full Discord webhook payload for an event.
     *
     * @access public
     * @param  array  $project
     * @param  string $eventName
     * @param  array  $eventData
     * @param  string $mentionContent  Pre-rendered "<@id> <@id>" mention string
     * @return array  Discord webhook JSON payload
     */
    public function build(array $project, $eventName, array $eventData, $mentionContent = '')
    {
        $embed = array(
            'title'       => $this->getEmbedTitle($eventName, $eventData, $project),
            'type'        => 'rich',
            'description' => $this->getDescription($eventName, $eventData),
            'url'         => $this->getTaskUrl($eventData, $project),
            'color'       => $this->getColor($eventName),
            'timestamp'   => date('c'),
            'fields'      => $this->getFields($eventName, $eventData),
        );

        $author = $this->getAuthorName();
        if ($author !== '') {
            $embed['footer'] = array('text' => $author);
        }

        $payload = array(
            'username'    => 'Kanboard',
            'avatar_url'  => 'https://raw.githubusercontent.com/kanboard/kanboard/main/assets/img/favicon.png',
            'embeds'      => array($embed),
        );

        // The content field is what triggers Discord push notifications / pings.
        if ($mentionContent !== '') {
            $payload['content'] = $mentionContent;
            // Restrict pings to explicitly listed users to avoid accidental @everyone.
            $payload['allowed_mentions'] = array('parse' => array('users'));
        }

        return $payload;
    }

    /**
     * Build the embed title: "[Project] Event summary".
     *
     * @access protected
     * @return string
     */
    protected function getEmbedTitle($eventName, array $eventData, array $project)
    {
        $projectName = isset($eventData['task']['project_name']) && $eventData['task']['project_name'] !== ''
            ? $eventData['task']['project_name']
            : $project['name'];

        $summary = $this->notificationModel->getTitleWithoutAuthor($eventName, $eventData);

        return sprintf('[%s] %s', $projectName, $summary);
    }

    /**
     * Absolute URL to the related task (used as embed link).
     *
     * @access protected
     * @return string
     */
    protected function getTaskUrl(array $eventData, array $project)
    {
        if (empty($eventData['task']['id'])) {
            return '';
        }

        return $this->helper->url->to(
            'TaskViewController',
            'show',
            array('task_id' => $eventData['task']['id'], 'project_id' => $project['id']),
            '',
            true
        );
    }

    /**
     * Event-specific embed description (task description / comment / subtask line).
     *
     * @access protected
     * @return string
     */
    protected function getDescription($eventName, array $eventData)
    {
        $descriptionEvents = array(
            TaskModel::EVENT_CREATE,
            TaskModel::EVENT_UPDATE,
            TaskModel::EVENT_USER_MENTION,
        );
        $commentEvents = array(
            CommentModel::EVENT_CREATE,
            CommentModel::EVENT_UPDATE,
            CommentModel::EVENT_DELETE,
            CommentModel::EVENT_USER_MENTION,
        );
        $subtaskEvents = array(
            SubtaskModel::EVENT_CREATE,
            SubtaskModel::EVENT_UPDATE,
            SubtaskModel::EVENT_DELETE,
        );

        if (in_array($eventName, $commentEvents, true) && ! empty($eventData['comment']['comment'])) {
            return $this->truncate('💬 '.$eventData['comment']['comment']);
        }

        if (in_array($eventName, $subtaskEvents, true) && isset($eventData['subtask'])) {
            return $this->truncate('↳ '.$this->getSubtaskSymbol($eventData['subtask']).$eventData['subtask']['title']);
        }

        if (in_array($eventName, $descriptionEvents, true) && ! empty($eventData['task']['description'])) {
            return $this->truncate($eventData['task']['description']);
        }

        return '';
    }

    /**
     * Structured fields shown at the bottom of the embed.
     *
     * @access protected
     * @return array
     */
    protected function getFields($eventName, array $eventData)
    {
        $fields = array();

        if (! empty($eventData['task']['assignee_name']) || ! empty($eventData['task']['assignee_username'])) {
            $fields[] = array(
                'name'   => t('Assignee'),
                'value'  => $eventData['task']['assignee_name'] ?: $eventData['task']['assignee_username'],
                'inline' => true,
            );
        }

        if (! empty($eventData['task']['column_title'])) {
            $fields[] = array(
                'name'   => t('Column'),
                'value'  => $eventData['task']['column_title'],
                'inline' => true,
            );
        }

        return $fields;
    }

    /**
     * @access protected
     * @return string
     */
    protected function getSubtaskSymbol(array $subtask)
    {
        switch ((int) $subtask['status']) {
            case SubtaskModel::STATUS_DONE:
                return '✅ ';
            case SubtaskModel::STATUS_INPROGRESS:
                return '🕘 ';
            default:
                return '⬜ ';
        }
    }

    /**
     * @access protected
     * @return integer
     */
    protected function getColor($eventName)
    {
        switch ($eventName) {
            case TaskModel::EVENT_CREATE:
            case SubtaskModel::EVENT_CREATE:
            case CommentModel::EVENT_CREATE:
            case TaskFileModel::EVENT_CREATE:
                return self::COLOR_CREATE;
            case TaskModel::EVENT_CLOSE:
                return self::COLOR_CLOSE;
            case TaskModel::EVENT_OPEN:
                return self::COLOR_OPEN;
            case TaskModel::EVENT_USER_MENTION:
            case CommentModel::EVENT_USER_MENTION:
                return self::COLOR_MENTION;
            case CommentModel::EVENT_DELETE:
            case SubtaskModel::EVENT_DELETE:
                return self::COLOR_DELETE;
            default:
                return self::COLOR_DEFAULT;
        }
    }

    /**
     * Current author full name (empty when triggered by a cron job).
     *
     * @access protected
     * @return string
     */
    protected function getAuthorName()
    {
        if ($this->userSession->isLogged()) {
            return $this->helper->user->getFullname();
        }

        return '';
    }

    /**
     * Truncate text to stay within Discord embed description limits (4096 chars).
     *
     * @access protected
     * @param  string $text
     * @return string
     */
    protected function truncate($text)
    {
        $max = 2000;
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1).'…';
        }

        return $text;
    }
}
