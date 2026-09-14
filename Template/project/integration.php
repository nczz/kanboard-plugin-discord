<div class="panel">
    <h3><img src="<?= $this->url->dir() ?>plugins/Discord/Asset/discord-icon.png" width="16" height="16" alt="Discord" onerror="this.style.display='none'"> <?= t('Discord') ?></h3>

    <?= $this->form->label(t('Discord Webhook URL'), 'discord_webhook_url') ?>
    <?= $this->form->text('discord_webhook_url', $values, array(), array('placeholder="https://discord.com/api/webhooks/..."')) ?>

    <p class="form-help">
        <?= t('Create an Incoming Webhook in your Discord channel (Server Settings > Integrations > Webhooks) and paste the URL here. Leave empty to disable Discord notifications for this project.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
