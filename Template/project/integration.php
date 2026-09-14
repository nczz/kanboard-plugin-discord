<div class="panel">
    <h3><?= t('Discord') ?></h3>

    <?= $this->form->label(t('Discord Webhook URL'), 'discord_webhook_url') ?>
    <?= $this->form->text('discord_webhook_url', $values, array(), array('placeholder="https://discord.com/api/webhooks/..."')) ?>

    <p class="form-help">
        <?= t('Create an Incoming Webhook in your Discord channel (Server Settings > Integrations > Webhooks) and paste the URL here. Leave empty to disable Discord notifications for this project.') ?>
    </p>

    <?= $this->form->label(t('Content excerpt length'), 'discord_excerpt_length') ?>
    <?= $this->form->number('discord_excerpt_length', $values, array(), array('min="0"', 'placeholder="280"')) ?>
    <p class="form-help">
        <?= t('Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
