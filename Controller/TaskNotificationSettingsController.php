<?php

namespace Kanboard\Plugin\Discord\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Discord\Notification\EventRegistry;

/**
 * Task-level notification rule editor.
 */
class TaskNotificationSettingsController extends BaseController
{
    /**
     * Show task notification rules.
     */
    public function show()
    {
        $task = $this->getEditableTask();

        $this->response->html($this->template->render('discord:task/notification_settings', array(
            'task' => $task,
            'rules' => $this->discordSettingsModel->getTaskEventRules($task['id']),
        )));
    }

    /**
     * Save task notification rules.
     */
    public function save()
    {
        $this->checkCSRFForm();
        $task = $this->getEditableTask();
        $values = $this->request->getValues();
        $submittedRules = isset($values['rules']) && is_array($values['rules']) ? $values['rules'] : array();
        $rules = array();

        foreach (EventRegistry::getEventKeys() as $eventKey) {
            $rule = isset($submittedRules[$eventKey]) && is_array($submittedRules[$eventKey]) ? $submittedRules[$eventKey] : array();
            $rules[$eventKey] = array(
                'mute_discord' => EventRegistry::supportsDiscordProjectEvent($eventKey) && isset($rule['mute_discord']) && (string) $rule['mute_discord'] === '1' ? 1 : 0,
                'mute_email'   => isset($rule['mute_email']) && (string) $rule['mute_email'] === '1' ? 1 : 0,
            );
        }

        $this->discordSettingsModel->saveTaskEventRules($task['id'], $rules);

        $this->flash->success(t('Task notification rules updated successfully.'));
        $this->response->redirect($this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id'])), true);
    }

    /**
     * Return a task the current user is allowed to edit.
     *
     * @return array
     */
    protected function getEditableTask()
    {
        $task = $this->getTask();

        if (! $this->helper->projectRole->canUpdateTask($task)) {
            throw new AccessForbiddenException(t('You are not allowed to update tasks assigned to someone else.'));
        }

        return $task;
    }
}
