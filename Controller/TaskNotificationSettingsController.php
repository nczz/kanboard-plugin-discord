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
            'values' => $this->taskMetadataModel->getAll($task['id']),
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
        $metadata = array();

        foreach (EventRegistry::getEventKeys() as $eventKey) {
            $discordKey = EventRegistry::getTaskMuteDiscordMetadataKey($eventKey);
            $emailKey = EventRegistry::getTaskMuteEmailMetadataKey($eventKey);

            if (EventRegistry::supportsDiscordProjectEvent($eventKey) && isset($values[$discordKey]) && (string) $values[$discordKey] === '1') {
                $metadata[$discordKey] = '1';
            } else {
                $this->taskMetadataModel->remove($task['id'], $discordKey);
            }

            if (isset($values[$emailKey]) && (string) $values[$emailKey] === '1') {
                $metadata[$emailKey] = '1';
            } else {
                $this->taskMetadataModel->remove($task['id'], $emailKey);
            }
        }

        if (! empty($metadata)) {
            $this->taskMetadataModel->save($task['id'], $metadata);
        }

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
