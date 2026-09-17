<?php

namespace Kanboard\Plugin\Discord\Console;

use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Discord\Notification\DiscordNotification;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extends Kanboard's overdue command with project-level Discord cards.
 *
 * Kanboard core emits overdue notifications through user notification types.
 * Discord project channels are configured at project level, so this command
 * bridges the same overdue task list directly to project webhooks before core
 * fans out email/user notifications.
 */
class TaskOverdueNotificationCommand extends \Kanboard\Console\TaskOverdueNotificationCommand
{
    /**
     * Execute the core overdue workflow and add Discord project-channel cards.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('project')) {
            $tasks = $this->taskFinderModel->getOverdueTasksQuery()
                ->beginOr()
                ->eq(TaskModel::TABLE.'.project_id', $input->getOption('project'))
                ->eq(ProjectModel::TABLE.'.identifier', $input->getOption('project'))
                ->closeOr()
                ->findAll();
        } else {
            $tasks = $this->taskFinderModel->getOverdueTasks();
        }

        $this->sendDiscordOverdueTaskNotifications($tasks);

        if ($input->getOption('group')) {
            $tasks = $this->sendGroupOverdueTaskNotifications($tasks);
        } elseif ($input->getOption('manager')) {
            $tasks = $this->sendOverdueTaskNotificationsToManagers($tasks);
        } else {
            $tasks = $this->sendOverdueTaskNotifications($tasks);
        }

        if ($input->getOption('show')) {
            $this->showTable($output, $tasks);
        }

        return 0;
    }

    /**
     * Send one Discord project-channel card per overdue task.
     *
     * @param array $tasks
     */
    protected function sendDiscordOverdueTaskNotifications(array $tasks)
    {
        if (empty($tasks)) {
            return;
        }

        $notification = new DiscordNotification($this->container);
        $notification->sendOverdueTaskNotifications($tasks);
    }
}
