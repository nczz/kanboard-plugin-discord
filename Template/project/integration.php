<div class="panel">
    <h3><?= t('Discord') ?></h3>
    <?php
        $discordEventGroups = \Kanboard\Plugin\Discord\Notification\EventRegistry::getEventGroups();
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
            <?= t('Choose which Kanboard events should be sent to Discord and which Email notifications should be suppressed.') ?>
            <?= t('Suppressing Email does not require Discord to be enabled. If Discord is also disabled, the event is muted for both Discord and Email.') ?>
        </p>

        <?php foreach ($discordEventGroups as $discordEventGroup => $discordEventOptions): ?>
            <h4><?= $this->text->e($discordEventGroup) ?></h4>
            <table class="table-small">
                <tr>
                    <th><?= t('Event') ?></th>
                    <th><?= t('Discord notification') ?></th>
                    <th><?= t('Suppress Email') ?></th>
                </tr>
                <?php foreach ($discordEventOptions as $discordEventKey => $discordEventLabel): ?>
                    <?php
                        $discordEventMetadataKey = \Kanboard\Plugin\Discord\Notification\EventRegistry::getDiscordProjectMetadataKey($discordEventKey);
                        $discordEventChecked = \Kanboard\Plugin\Discord\Notification\EventRegistry::isDiscordProjectEventEnabled($discordEventKey, $values);
                        $discordSuppressEmailMetadataKey = \Kanboard\Plugin\Discord\Notification\EventRegistry::getSuppressEmailProjectMetadataKey($discordEventKey);
                        $discordSuppressEmailChecked = \Kanboard\Plugin\Discord\Notification\EventRegistry::isProjectEmailSuppressed($discordEventKey, $values);
                    ?>
                    <tr>
                        <td><?= $this->text->e($discordEventLabel) ?></td>
                        <td>
                            <?php if (\Kanboard\Plugin\Discord\Notification\EventRegistry::supportsDiscordProjectEvent($discordEventKey)): ?>
                                <input type="hidden" name="<?= $discordEventMetadataKey ?>" value="0">
                                <?= $this->form->checkbox($discordEventMetadataKey, '', '1', $discordEventChecked) ?>
                            <?php else: ?>
                                <?= t('Handled by comment cards') ?>
                            <?php endif ?>
                        </td>
                        <td>
                            <input type="hidden" name="<?= $discordSuppressEmailMetadataKey ?>" value="0">
                            <?= $this->form->checkbox($discordSuppressEmailMetadataKey, '', '1', $discordSuppressEmailChecked) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </table>
        <?php endforeach ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</div>
