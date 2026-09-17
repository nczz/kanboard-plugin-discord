<div class="panel">
    <h3><?= t('Discord') ?></h3>

    <?= $this->form->label(t('Discord User ID'), 'discord_user_id') ?>
    <?= $this->form->text('discord_user_id', $values, array(), array('placeholder="123456789012345678"')) ?>

    <p class="form-help">
        <?= t('Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.') ?>
        <?= t('To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".') ?>
        <?= t('The Discord notification type is enabled for users by default when the plugin is installed and for new users after they are created. It is only needed for task-description @mentions; comment @mentions and overdue task cards use project-level Discord settings instead. Users can opt out from task-description mention cards in Kanboard notification settings.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
