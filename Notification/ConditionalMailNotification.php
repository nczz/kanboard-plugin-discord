<?php

namespace Kanboard\Plugin\Discord\Notification;

use Kanboard\Core\Base;
use Kanboard\Core\Notification\NotificationInterface;
use Kanboard\Model\TaskModel;

/**
 * Email notification wrapper that applies Discord plugin suppression rules.
 */
class ConditionalMailNotification extends Base implements NotificationInterface
{
    /**
     * Send notification to a user unless project/task rules suppress Email.
     *
     * @param array  $user
     * @param string $eventName
     * @param array  $eventData
     */
    public function notifyUser(array $user, $eventName, array $eventData)
    {
        $filteredEventData = $this->filterEventData($eventName, $eventData);

        if ($filteredEventData === null) {
            return;
        }

        $this->getMailNotification()->notifyUser($user, $eventName, $filteredEventData);
    }

    /**
     * Email is a user notification type only.
     *
     * @param array  $project
     * @param string $eventName
     * @param array  $eventData
     */
    public function notifyProject(array $project, $eventName, array $eventData)
    {
    }

    /**
     * Return filtered event data or null when Email should be suppressed.
     *
     * @param string $eventName
     * @param array  $eventData
     * @return array|null
     */
    protected function filterEventData($eventName, array $eventData)
    {
        $eventKey = EventRegistry::getEventKey($eventName);

        if ($eventKey === '') {
            return $eventData;
        }

        if ($eventName === TaskModel::EVENT_OVERDUE && ! empty($eventData['tasks']) && is_array($eventData['tasks'])) {
            return $this->filterOverdueEventData($eventKey, $eventData);
        }

        $task = isset($eventData['task']) && is_array($eventData['task']) ? $eventData['task'] : array();

        if (! empty($task) && $this->isEmailSuppressedForTask($task, $eventKey)) {
            return null;
        }

        return $eventData;
    }

    /**
     * Filter suppressed tasks from an overdue batch.
     *
     * @param string $eventKey
     * @param array  $eventData
     * @return array|null
     */
    protected function filterOverdueEventData($eventKey, array $eventData)
    {
        $tasks = array();
        $projectNames = array();

        foreach ($eventData['tasks'] as $task) {
            if (! is_array($task) || $this->isEmailSuppressedForTask($task, $eventKey)) {
                continue;
            }

            $tasks[] = $task;
            if (! empty($task['project_id']) && ! empty($task['project_name'])) {
                $projectNames[(int) $task['project_id']] = $task['project_name'];
            }
        }

        if (empty($tasks)) {
            return null;
        }

        $eventData['tasks'] = $tasks;
        if (! empty($projectNames)) {
            $eventData['project_name'] = implode(', ', $projectNames);
        }

        return $eventData;
    }

    /**
     * @param array  $task
     * @param string $eventKey
     * @return bool
     */
    protected function isEmailSuppressedForTask(array $task, $eventKey)
    {
        if (empty($task['project_id'])) {
            return false;
        }

        $projectMetadata = $this->projectMetadataModel->getAll((int) $task['project_id']);
        if (EventRegistry::isProjectEmailSuppressed($eventKey, $projectMetadata)) {
            return true;
        }

        if (! empty($task['id'])) {
            $taskMetadata = $this->taskMetadataModel->getAll((int) $task['id']);
            if (EventRegistry::isTaskEmailMuted($eventKey, $taskMetadata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Kanboard\Notification\MailNotification
     */
    protected function getMailNotification()
    {
        return new \Kanboard\Notification\MailNotification($this->container);
    }
}
