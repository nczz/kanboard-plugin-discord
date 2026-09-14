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
     * Discord API hard limits (characters).
     * @see https://discord.com/developers/docs/resources/channel#embed-object-embed-limits
     */
    const LIMIT_TITLE       = 256;
    const LIMIT_DESCRIPTION = 4096;
    const LIMIT_FIELD_VALUE = 1024;
    const LIMIT_CONTENT     = 2000;

    /**
     * Default length (characters) for the task content excerpt shown in the
     * card (task description / comment / subtask title). Kept short on purpose
     * so notifications convey status without flooding the channel with content.
     *
     * Overridable per-project via project metadata "discord_excerpt_length",
     * or globally via the "discord_excerpt_length" application setting.
     */
    const DEFAULT_EXCERPT_LENGTH = 280;

    /**
     * Metadata / setting key for the configurable excerpt length.
     */
    const KEY_EXCERPT_LENGTH = 'discord_excerpt_length';

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
            'title'       => $this->getEmbedTitle($eventData, $project),
            'type'        => 'rich',
            'description' => $this->getDescription($eventName, $eventData, $project),
            'url'         => $this->getTaskUrl($eventData, $project),
            'color'       => $this->getColor($eventName),
            'timestamp'   => date('c'),
            'fields'      => $this->getFields($eventName, $eventData),
        );

        // Footer carries the project name so the channel reader always knows the
        // source project at a glance (the title is reserved for the task).
        $projectName = $this->getProjectName($eventData, $project);
        if ($projectName !== '') {
            $embed['footer'] = array('text' => $projectName);
        }

        $payload = array(
            'username'    => 'Kanboard',
            'avatar_url'  => 'https://raw.githubusercontent.com/kanboard/kanboard/main/assets/img/favicon.png',
            'embeds'      => array($embed),
        );

        // The content field is what triggers Discord push notifications / pings.
        if ($mentionContent !== '') {
            $payload['content'] = $this->truncate($mentionContent, self::LIMIT_CONTENT);
            // Restrict pings to explicitly listed users to avoid accidental @everyone.
            $payload['allowed_mentions'] = array('parse' => array('users'));
        }

        return $payload;
    }

    /**
     * Embed title: always "#<id> · <task title>" so the reader immediately knows
     * WHICH task the notification is about, regardless of the event type.
     *
     * @access protected
     * @return string
     */
    protected function getEmbedTitle(array $eventData, array $project)
    {
        if (empty($eventData['task']['id'])) {
            return $this->truncate($this->getProjectName($eventData, $project), self::LIMIT_TITLE);
        }

        $title = sprintf('#%d', $eventData['task']['id']);

        if (! empty($eventData['task']['title'])) {
            $title .= ' · '.$eventData['task']['title'];
        }

        return $this->truncate($title, self::LIMIT_TITLE);
    }

    /**
     * Resolve the project name from the event or the project row.
     *
     * @access protected
     * @return string
     */
    protected function getProjectName(array $eventData, array $project)
    {
        if (! empty($eventData['task']['project_name'])) {
            return $eventData['task']['project_name'];
        }

        return isset($project['name']) ? $project['name'] : '';
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
     * Embed description = a complete action sentence ("Alice closed the task #42")
     * so the status is always fully conveyed, followed by an OPTIONAL short
     * excerpt of the related content (task description / comment / subtask),
     * capped at a configurable length to avoid flooding the channel.
     *
     * @access protected
     * @param  string $eventName
     * @param  array  $eventData
     * @param  array  $project
     * @return string
     */
    protected function getDescription($eventName, array $eventData, array $project)
    {
        $sentence = $this->getActionSentence($eventName, $eventData);
        $excerpt = $this->getExcerpt($eventName, $eventData, $project);

        if ($sentence !== '' && $excerpt !== '') {
            // Content excerpt rendered as a Discord blockquote for visual separation.
            return $sentence."\n\n> ".str_replace("\n", "\n> ", $excerpt);
        }

        return $sentence !== '' ? $sentence : $excerpt;
    }

    /**
     * Build a complete "who did what" sentence, reusing Kanboard's translated
     * event titles. Falls back to the author-less variant for cron-triggered
     * events (e.g. overdue) where there is no acting user.
     *
     * @access protected
     * @return string
     */
    protected function getActionSentence($eventName, array $eventData)
    {
        $author = $this->getAuthorName();

        if ($author !== '') {
            $sentence = $this->notificationModel->getTitleWithAuthor($author, $eventName, $eventData);
        } else {
            $sentence = $this->notificationModel->getTitleWithoutAuthor($eventName, $eventData);
        }

        return $this->truncate($this->escapeMarkdown($sentence), self::LIMIT_TITLE);
    }

    /**
     * The event's content block shown under the status sentence.
     *
     * Two kinds of content are distinguished:
     *   - Structured detail (subtask, attachment): concise status-bearing
     *     information that is ALWAYS shown, because it is part of "what changed",
     *     not free-form text.
     *   - Free text (task description, comment): trimmed to the configurable
     *     excerpt length, and fully hidden when the length is set to 0.
     *
     * @access protected
     * @return string
     */
    protected function getExcerpt($eventName, array $eventData, array $project)
    {
        // Structured detail — always shown (it is status, not free text).
        if (in_array($eventName, array(SubtaskModel::EVENT_CREATE, SubtaskModel::EVENT_UPDATE, SubtaskModel::EVENT_DELETE), true)
            && ! empty($eventData['subtask'])) {
            return $this->formatSubtaskDetail($eventData['subtask']);
        }

        if ($eventName === TaskFileModel::EVENT_CREATE && ! empty($eventData['file']['name'])) {
            return '📎 '.$this->escapeMarkdown($eventData['file']['name']);
        }

        // Free-text content — trimmed to the configurable length (0 hides it).
        $max = $this->getExcerptLength($project);
        if ($max <= 0) {
            return '';
        }

        $commentEvents = array(
            CommentModel::EVENT_CREATE,
            CommentModel::EVENT_UPDATE,
            CommentModel::EVENT_DELETE,
            CommentModel::EVENT_USER_MENTION,
        );
        $descriptionEvents = array(
            TaskModel::EVENT_CREATE,
            TaskModel::EVENT_UPDATE,
            TaskModel::EVENT_USER_MENTION,
        );

        if (in_array($eventName, $commentEvents, true) && ! empty($eventData['comment']['comment'])) {
            return '💬 '.$this->truncate($this->escapeMarkdown($eventData['comment']['comment']), $max);
        }

        // task.update: prefer showing WHICH fields changed (more informative than
        // repeating the static description), falling back to the description.
        if ($eventName === TaskModel::EVENT_UPDATE) {
            $changed = $this->getChangedFieldsLine($eventData);
            if ($changed !== '') {
                return $changed;
            }
        }

        if (in_array($eventName, $descriptionEvents, true) && ! empty($eventData['task']['description'])) {
            return $this->truncate($this->escapeMarkdown($eventData['task']['description']), $max);
        }

        return '';
    }

    /**
     * Human-readable "changed fields" line built from the event's diff, e.g.
     * "Changed: Priority, Due date". Field keys not worth surfacing (internal
     * timestamps) are ignored.
     *
     * @access protected
     * @param  array $eventData
     * @return string
     */
    protected function getChangedFieldsLine(array $eventData)
    {
        if (empty($eventData['changes']) || ! is_array($eventData['changes'])) {
            return '';
        }

        $labels = array(
            'title'          => t('Title'),
            'description'    => t('Description'),
            'owner_id'       => t('Assignee'),
            'color_id'       => t('Color'),
            'due_date'       => t('Due Date'),
            'date_due'       => t('Due Date'),
            'priority'       => t('Priority'),
            'category_id'    => t('Category'),
            'score'          => t('Complexity'),
            'time_estimated' => t('Time estimated'),
            'time_spent'     => t('Time spent'),
            'column_id'      => t('Column'),
            'swimlane_id'    => t('Swimlane'),
        );

        $ignore = array('date_modification', 'date_moved', 'date_creation');
        $names = array();

        foreach (array_keys($eventData['changes']) as $field) {
            if (in_array($field, $ignore, true)) {
                continue;
            }
            $names[] = isset($labels[$field]) ? $labels[$field] : $field;
        }

        $names = array_unique($names);

        return empty($names) ? '' : t('Changed').': '.$this->escapeMarkdown(implode(', ', $names));
    }

    /**
     * Format subtask detail: status symbol, title, status name, assignee and
     * time tracking (when present). Concise and always shown so the reader sees
     * exactly which subtask changed and its current state.
     *
     * @access protected
     * @param  array $subtask
     * @return string
     */
    protected function formatSubtaskDetail(array $subtask)
    {
        $parts = array();

        $line = $this->getSubtaskSymbol($subtask).$this->escapeMarkdown((string) $subtask['title']);

        if (! empty($subtask['status_name'])) {
            $line .= ' ('.t($subtask['status_name']).')';
        }

        $parts[] = $line;

        $assignee = '';
        if (! empty($subtask['name'])) {
            $assignee = $subtask['name'];
        } elseif (! empty($subtask['username'])) {
            $assignee = $subtask['username'];
        }
        if ($assignee !== '') {
            $parts[] = t('Assignee').': '.$this->escapeMarkdown($assignee);
        }

        $estimated = isset($subtask['time_estimated']) ? (float) $subtask['time_estimated'] : 0;
        $spent = isset($subtask['time_spent']) ? (float) $subtask['time_spent'] : 0;
        if ($estimated > 0 || $spent > 0) {
            $parts[] = sprintf('%s: %s/%sh', t('Time spent'), $this->formatHours($spent), $this->formatHours($estimated));
        }

        return implode(' · ', $parts);
    }

    /**
     * Format an hours value without trailing ".0".
     *
     * @access protected
     * @param  float $hours
     * @return string
     */
    protected function formatHours($hours)
    {
        if ($hours == (int) $hours) {
            return (string) (int) $hours;
        }

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    /**
     * Resolve the configurable content-excerpt length.
     *
     * Precedence: per-project metadata > global application setting > default.
     * A non-positive / non-numeric value falls back to the default.
     *
     * @access protected
     * @param  array $project
     * @return integer
     */
    protected function getExcerptLength(array $project)
    {
        $value = '';

        // NOTE: MetadataModel::get() uses "?: default", so a stored "0" comes back
        // as the (empty) default. We therefore detect an explicit "0" (= hide) via
        // exists() before falling back.
        if (! empty($project['id'])) {
            if ($this->projectMetadataModel->exists($project['id'], self::KEY_EXCERPT_LENGTH)) {
                $raw = $this->projectMetadataModel->get($project['id'], self::KEY_EXCERPT_LENGTH, '');
                // Empty string returned for a value that exists means it was "0".
                $value = ($raw === '') ? '0' : (string) $raw;
            }
        }

        // Empty at project level -> fall back to the global application setting.
        if ($value === '') {
            $value = (string) $this->configModel->get(self::KEY_EXCERPT_LENGTH, '');
        }

        // Unset or non-numeric -> default. An explicit "0" means "hide excerpts".
        if ($value === '' || ! ctype_digit($value)) {
            return self::DEFAULT_EXCERPT_LENGTH;
        }

        return min((int) $value, self::LIMIT_DESCRIPTION);
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
                'value'  => $this->truncate($this->escapeMarkdown($eventData['task']['assignee_name'] ?: $eventData['task']['assignee_username']), self::LIMIT_FIELD_VALUE),
                'inline' => true,
            );
        }

        if (! empty($eventData['task']['column_title'])) {
            $fields[] = array(
                'name'   => t('Column'),
                'value'  => $this->truncate($this->escapeMarkdown($eventData['task']['column_title']), self::LIMIT_FIELD_VALUE),
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
     * Truncate text to stay within a Discord field character limit.
     *
     * @access protected
     * @param  string  $text
     * @param  integer $max
     * @return string
     */
    protected function truncate($text, $max)
    {
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1).'…';
        }

        return $text;
    }

    /**
     * Escape Discord markdown control characters in user-supplied text.
     *
     * Kanboard passes task titles / descriptions / comments verbatim. Discord
     * renders markdown (bold, links, spoilers, code) inside embeds, so raw user
     * input could garble the card or embed a masked phishing link. Prefixing the
     * markdown control characters with a backslash renders them literally.
     *
     * Note: embeds never trigger pings regardless (only the content field with
     * allowed_mentions does), so this is display hardening, not a ping guard.
     *
     * @access protected
     * @param  string $text
     * @return string
     */
    protected function escapeMarkdown($text)
    {
        // Escape the characters Discord treats as markdown control tokens.
        // '#' and '-' are intentionally excluded: inline they are harmless and
        // escaping them would garble task ids (#42) and hyphenated words.
        return preg_replace('/([\\\\`*_~|>\[\]()])/', '\\\\$1', (string) $text);
    }
}
