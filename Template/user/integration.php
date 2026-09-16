<div class="panel">
    <h3><?= t('Discord') ?></h3>

    <?= $this->form->label(t('Discord User ID'), 'discord_user_id') ?>
    <?= $this->form->text('discord_user_id', $values, array(), array('placeholder="123456789012345678"')) ?>

    <p class="form-help">
        <?= t('Enter your numeric Discord User ID to be mentioned (pinged) in Discord notifications when you are assigned to a task or mentioned in a comment.') ?>
        <?= t('To find it: enable Developer Mode in Discord (Settings > Advanced), then right-click your name and choose "Copy User ID".') ?>
        <?= t('For task description @mentions, also enable the Discord notification type in your Kanboard notification settings. Comment @mentions are pinged on the project comment card when the mentioned user has a Discord User ID mapped. Kanboard overdue task notifications also reach Discord through the user notification path and are de-duplicated per overdue task.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
