<div class="panel">
    <h3><?= t('Discord') ?></h3>
    <?php
        $discordDefaultWebhookKey = \Kanboard\Plugin\Discord\Notification\DiscordNotification::CONFIG_WEBHOOK_URL;
    ?>


    <?= $this->form->label(t('Default Discord Webhook URL'), $discordDefaultWebhookKey) ?>
    <?= $this->form->text($discordDefaultWebhookKey, $values, array(), array('placeholder="https://discord.com/api/webhooks/..."')) ?>

    <p class="form-help">
        <?= t('Create an Incoming Webhook in your default Discord channel and paste the URL here. Projects without their own Discord webhook URL will send notifications to this webhook. Leave empty to require each project to configure its own webhook.') ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
