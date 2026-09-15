<div class="panel">
    <h3><?= t('Discord') ?></h3>
    <?php
        $discordEventGroups = \Kanboard\Plugin\Discord\Notification\DiscordNotification::getEventGroups();
    ?>


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

    <fieldset>
        <legend><?= t('Discord notification events') ?></legend>
        <p class="form-help">
            <?= t('Choose which Kanboard events should be sent to Discord. Task move notifications are off by default because drag/reorder activity is often noisy.') ?>
        </p>

        <?php foreach ($discordEventGroups as $discordEventGroup => $discordEventOptions): ?>
            <h4><?= $this->text->e($discordEventGroup) ?></h4>
            <?php foreach ($discordEventOptions as $discordEventKey => $discordEventLabel): ?>
                <?php
                    $discordEventMetadataKey = \Kanboard\Plugin\Discord\Notification\DiscordNotification::getEventMetadataKey($discordEventKey);
                    $discordEventChecked = \Kanboard\Plugin\Discord\Notification\DiscordNotification::isEventMetadataEnabled($discordEventKey, $values);
                ?>
                <input type="hidden" name="<?= $discordEventMetadataKey ?>" value="0">
                <?= $this->form->checkbox($discordEventMetadataKey, $discordEventLabel, '1', $discordEventChecked) ?><br>
            <?php endforeach ?>
        <?php endforeach ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
