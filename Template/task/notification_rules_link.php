<?php if ($this->projectRole->canUpdateTask($task)): ?>
<li>
    <?= $this->modal->medium('bell-slash-o', t('Notification rules'), 'TaskNotificationSettingsController', 'show', array('plugin' => 'Discord', 'task_id' => $task['id'])) ?>
</li>
<?php endif ?>
