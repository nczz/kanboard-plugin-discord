<form method="post" action="<?= $this->url->href('TaskNotificationSettingsController', 'save', array('plugin' => 'Discord', 'task_id' => $task['id'])) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <div class="page-header">
        <h2><?= t('Notification rules') ?></h2>
    </div>

    <p class="form-help">
        <?= t('These settings only mute notifications for this task. They do not enable events that are disabled at project level.') ?>
    </p>

    <?php foreach (\Kanboard\Plugin\Discord\Notification\EventRegistry::getEventGroups() as $discordEventGroup => $discordEventOptions): ?>
        <h3><?= $this->text->e($discordEventGroup) ?></h3>
        <table class="table-small">
            <tr>
                <th><?= t('Event') ?></th>
                <th><?= t('Mute Discord') ?></th>
                <th><?= t('Mute Email') ?></th>
            </tr>
            <?php foreach ($discordEventOptions as $discordEventKey => $discordEventLabel): ?>
                <?php
                    $discordMuted = \Kanboard\Plugin\Discord\Notification\EventRegistry::isTaskDiscordMuted($discordEventKey, $rules);
                    $emailMuted = \Kanboard\Plugin\Discord\Notification\EventRegistry::isTaskEmailMuted($discordEventKey, $rules);
                ?>
                <tr>
                    <td><?= $this->text->e($discordEventLabel) ?></td>
                    <td>
                        <?php if (\Kanboard\Plugin\Discord\Notification\EventRegistry::supportsDiscordProjectEvent($discordEventKey)): ?>
                            <input type="hidden" name="rules[<?= $this->text->e($discordEventKey) ?>][mute_discord]" value="0">
                            <?= $this->form->checkbox('rules['.$discordEventKey.'][mute_discord]', '', '1', $discordMuted) ?>
                        <?php else: ?>
                            <?= t('Handled by comment cards') ?>
                        <?php endif ?>
                    </td>
                    <td>
                        <input type="hidden" name="rules[<?= $this->text->e($discordEventKey) ?>][mute_email]" value="0">
                        <?= $this->form->checkbox('rules['.$discordEventKey.'][mute_email]', '', '1', $emailMuted) ?>
                    </td>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endforeach ?>

    <?= $this->modal->submitButtons() ?>
</form>
