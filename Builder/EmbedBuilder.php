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
     * Discord allowed_mentions.users hard limit.
     */
    const LIMIT_ALLOWED_MENTION_USERS = 100;

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
     * @param  array  $messageContext  Pre-rendered message content and explicit mention user ids.
     * @return array  Discord webhook JSON payload
     */
    public function build(array $project, $eventName, array $eventData, array $messageContext = array())
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
            $embed['footer'] = array('text' => $this->truncate($this->escapeMarkdown($projectName), 2048));
        }

        // Discord rejects the whole webhook (HTTP 400) if the embed's combined
        // text (title + description + field names/values + footer) exceeds 6000
        // characters. Enforce it as a final safety net by trimming the most
        // expendable and largest variable part — the description.
        $this->enforceTotalBudget($embed);

        $payload = array(
            'username'    => 'Kanboard',
            'avatar_url'  => 'https://raw.githubusercontent.com/kanboard/kanboard/main/assets/img/favicon.png',
            'embeds'      => array($embed),
        );

        $content = $this->getMessageContent($eventName, $eventData, $messageContext);
        if ($content !== '') {
            $payload['content'] = $content;
            $payload['allowed_mentions'] = $this->getAllowedMentions($messageContext);
        }

        return $payload;
    }

    /**
     * Enforce Discord's 6000-character total embed budget by trimming the
     * description (largest, most expendable part) if the combined text of
     * title + description + field names/values + footer would exceed it.
     *
     * @access protected
     * @param  array $embed  Passed by reference and mutated in place.
     */
    protected function enforceTotalBudget(array &$embed)
    {
        $budget = 6000;

        $fixed = mb_strlen($embed['title'] ?? '')
            + mb_strlen(isset($embed['footer']['text']) ? $embed['footer']['text'] : '');

        if (! empty($embed['fields'])) {
            foreach ($embed['fields'] as $field) {
                $fixed += mb_strlen($field['name'] ?? '') + mb_strlen($field['value'] ?? '');
            }
        }

        $descLen = mb_strlen($embed['description'] ?? '');

        if ($fixed + $descLen > $budget) {
            $allowed = max(0, $budget - $fixed);
            $embed['description'] = $this->truncate($embed['description'], $allowed);
        }
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
            return $this->truncate($this->escapeMarkdown($this->getProjectName($eventData, $project)), self::LIMIT_TITLE);
        }

        $title = sprintf('#%d', $eventData['task']['id']);

        if (! empty($eventData['task']['title'])) {
            // The embed title renders markdown; escape the user-supplied title.
            $title .= ' · '.$this->escapeMarkdown($eventData['task']['title']);
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
            $description = $sentence."\n\n> ".str_replace("\n", "\n> ", $excerpt);
        } else {
            $description = $sentence !== '' ? $sentence : $excerpt;
        }

        // Final guard: the composed description (sentence + blockquote markers +
        // excerpt) must never exceed the Discord embed description limit, or the
        // whole webhook is rejected with HTTP 400 and the notification is lost.
        return $this->truncate($description, self::LIMIT_DESCRIPTION);
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

        // Kanboard's t()/e() HTML-escape their %s arguments (task titles, column
        // names, etc.) for web output. Discord renders plaintext, so decode the
        // entities back before escaping markdown, otherwise "&amp;"/"&#039;" leak.
        return $this->escapeMarkdown($this->decodeEntities($sentence));
    }

    /**
     * Decode HTML entities produced by Kanboard's t()/e() helpers, since the
     * Discord webhook payload is plaintext, not HTML.
     *
     * @access protected
     * @param  string $text
     * @return string
     */
    protected function decodeEntities($text)
    {
        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The event's content block shown under the status sentence.
     *
     * Two kinds of content are distinguished:
     *   - Structured detail (subtask, attachment): concise status-bearing
     *     information that is ALWAYS shown, because it is part of "what changed",
     *     not free-form text.
     *   - Free text (task description, comment): trimmed to the configurable
     *     excerpt length, and fully hidden when the length is set to 0. Newly
     *     created comments are excluded here because their body is rendered in
     *     the Discord message content for better channel preview visibility.
     *
     * @access protected
     * @return string
     */
    protected function getExcerpt($eventName, array $eventData, array $project)
    {
        // Structured detail — always shown (it is status, not free text).
        if (in_array($eventName, array(SubtaskModel::EVENT_CREATE, SubtaskModel::EVENT_UPDATE, SubtaskModel::EVENT_DELETE), true)
            && ! empty($eventData['subtask'])) {
            $changes = ($eventName === SubtaskModel::EVENT_UPDATE && ! empty($eventData['changes']) && is_array($eventData['changes']))
                ? $eventData['changes']
                : array();
            return $this->formatSubtaskDetail($eventData['subtask'], $changes);
        }

        if ($eventName === TaskFileModel::EVENT_CREATE && ! empty($eventData['file']['name'])) {
            return '📎 '.$this->escapeMarkdown($eventData['file']['name']);
        }

        if ($eventName === CommentModel::EVENT_CREATE) {
            return '';
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
     * Human-readable "changed fields" line for a task update, e.g.
     * "Changed: Priority, Due Date".
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

        return $this->formatChangedFields(
            $eventData['changes'],
            $labels,
            array('date_modification', 'date_moved', 'date_creation')
        );
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
    protected function formatSubtaskDetail(array $subtask, array $changes = array())
    {
        $parts = array();

        $line = $this->getSubtaskSymbol($subtask).$this->escapeMarkdown((string) ($subtask['title'] ?? ''));

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

        $detail = implode(' · ', $parts);

        // On update, prepend which fields changed so the reader sees what was
        // modified (the line above already shows the resulting state).
        $changedLine = $this->formatSubtaskChanges($changes);
        if ($changedLine !== '') {
            $detail = $changedLine."\n".$detail;
        }

        return $detail;
    }

    /**
     * Build a "Changed: ..." line for a subtask update from the event diff.
     *
     * @access protected
     * @param  array $changes
     * @return string
     */
    protected function formatSubtaskChanges(array $changes)
    {
        $labels = array(
            'title'          => t('Title'),
            'status'         => t('Status'),
            'user_id'        => t('Assignee'),
            'time_estimated' => t('Time estimated'),
            'time_spent'     => t('Time spent'),
        );

        return $this->formatChangedFields(
            $changes,
            $labels,
            array('id', 'task_id', 'position')
        );
    }

    /**
     * Shared builder for a "Changed: <fields>" line from an event diff.
     *
     * Maps raw field keys to human labels, drops ignored keys, de-duplicates,
     * and escapes the result for the Discord plaintext path. This is the single
     * source of truth for both task and subtask change summaries.
     *
     * @access private
     * @param  array $changes  Field => new value diff.
     * @param  array $labels   Field key => human label.
     * @param  array $ignore   Field keys to omit.
     * @return string          Empty string when nothing worth showing.
     */
    private function formatChangedFields(array $changes, array $labels, array $ignore)
    {
        if (empty($changes)) {
            return '';
        }

        $names = array();

        foreach (array_keys($changes) as $field) {
            if (in_array($field, $ignore, true)) {
                continue;
            }
            $names[] = isset($labels[$field]) ? $labels[$field] : $field;
        }

        $names = array_unique($names);

        return empty($names) ? '' : t('Changed').': '.$this->escapeMarkdown(implode(', ', $names));
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
     * Empty values fall through to the next level. Non-numeric values fall back
     * to the default. Explicit "0" hides free-text excerpts.
     * @access protected
     * @param  array $project
     * @return integer
     */
    protected function getExcerptLength(array $project)
    {
        $value = '';

        if (! empty($project['id'])) {
            $metadata = $this->projectMetadataModel->getAll($project['id']);
            if (array_key_exists(self::KEY_EXCERPT_LENGTH, $metadata)) {
                $value = (string) $metadata[self::KEY_EXCERPT_LENGTH];
            }
        }

        // Empty at project level -> fall back to the global application setting.
        if ($value === '') {
            $settings = $this->configModel->getAll();
            if (array_key_exists(self::KEY_EXCERPT_LENGTH, $settings)) {
                $value = (string) $settings[self::KEY_EXCERPT_LENGTH];
            }
        }

        // Unset or non-numeric -> default. An explicit "0" means "hide excerpts".
        if ($value === '' || ! ctype_digit($value)) {
            return self::DEFAULT_EXCERPT_LENGTH;
        }

        return min((int) $value, self::LIMIT_DESCRIPTION);
    }

    /**
     * Build the top-level Discord message content.
     *
     * Comment creation is special: Discord renders top-level content in channel
     * previews and push notifications more directly than embed text, so include
     * the sanitized comment body next to the explicit mention list. The embed
     * still carries task/project context.
     *
     * @access protected
     * @param  string $eventName
     * @param  array  $eventData
     * @param  array  $messageContext
     * @return string
     */
    protected function getMessageContent($eventName, array $eventData, array $messageContext)
    {
        $mentionContent = isset($messageContext['content']) ? trim((string) $messageContext['content']) : '';

        if ($eventName !== CommentModel::EVENT_CREATE || empty($eventData['comment']['comment'])) {
            return $this->truncate($mentionContent, self::LIMIT_CONTENT);
        }

        $comment = '💬 '.$this->sanitizeContentText($eventData['comment']['comment']);
        if ($mentionContent === '') {
            return $this->truncate($comment, self::LIMIT_CONTENT);
        }

        $remaining = self::LIMIT_CONTENT - mb_strlen($mentionContent) - 1;
        if ($remaining <= 0) {
            return $this->truncate($mentionContent, self::LIMIT_CONTENT);
        }

        return $mentionContent."\n".$this->truncate($comment, $remaining);
    }

    /**
     * Build Discord allowed_mentions.
     *
     * Discord webhook defaults parse user mentions from content. This payload is
     * stricter: only plugin-resolved Discord user ids may ping. Comment text can
     * contain arbitrary user input, so content without explicit users disables
     * parsing entirely.
     *
     * @access protected
     * @param  array $messageContext
     * @return array
     */
    protected function getAllowedMentions(array $messageContext)
    {
        $users = array();
        if (! empty($messageContext['users']) && is_array($messageContext['users'])) {
            foreach ($messageContext['users'] as $userId) {
                $userId = (string) $userId;
                if (ctype_digit($userId)) {
                    $users[$userId] = $userId;
                }
            }
        }

        if (! empty($users)) {
            return array('users' => array_slice(array_values($users), 0, self::LIMIT_ALLOWED_MENTION_USERS));
        }

        return array('parse' => array());
    }

    /**
     * Sanitize user-generated text for top-level Discord message content.
     *
     * allowed_mentions is the ping guard. This method handles display hardening:
     * decode Kanboard HTML entities and escape Discord markdown so the comment is
     * readable as text instead of formatting the notification.
     *
     * @access protected
     * @param  string $text
     * @return string
     */
    protected function sanitizeContentText($text)
    {
        return $this->escapeMarkdown($this->decodeEntities($text));
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

        $assignee = ($eventData['task']['assignee_name'] ?? '') ?: ($eventData['task']['assignee_username'] ?? '');
        if ($assignee !== '') {
            $fields[] = array(
                'name'   => t('Assignee'),
                'value'  => $this->truncate($this->escapeMarkdown($assignee), self::LIMIT_FIELD_VALUE),
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
        switch ((int) ($subtask['status'] ?? 0)) {
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
