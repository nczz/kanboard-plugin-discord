<form method="post" action="<?= $this->url->href('ProjectIntegrationController', 'save', array('plugin' => 'Discord', 'project_id' => $project['id'])) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <div class="page-header">
        <h2><?= t('Discord') ?></h2>
    </div>

    <?= $this->form->label(t('Discord Webhook URL'), 'webhook_url') ?>
    <?= $this->form->text('webhook_url', $values, $errors, array('placeholder="https://discord.com/api/webhooks/..."')) ?>
    <p class="form-help">
        <?= t('Create an Incoming Webhook in this project\'s Discord channel and paste the URL here. Leave empty to use the global default Discord webhook URL, when configured.') ?>
    </p>

    <?= $this->form->label(t('Content excerpt length'), 'excerpt_length') ?>
    <?= $this->form->number('excerpt_length', $values, $errors, array('min="0"', 'max="'.\Kanboard\Plugin\Discord\Builder\EmbedBuilder::LIMIT_DESCRIPTION.'"', 'placeholder="280"')) ?>
    <p class="form-help">
        <?= t('Maximum number of characters shown from task descriptions, comments and subtasks. The status line (who did what) is always shown in full. Leave empty for the default (280). Set 0 to hide content excerpts entirely.') ?>
    </p>

    <fieldset>
        <legend><?= t('Discord notification events') ?></legend>
        <p class="form-help">
            <?= t('Choose which Kanboard events should be sent to Discord and which Email notifications should be suppressed.') ?>
            <?= t('Suppressing Email does not require Discord to be enabled. If Discord is also disabled, the event is muted for both Discord and Email.') ?>
        </p>

        <?php foreach ($eventGroups as $discordEventGroup => $discordEventOptions): ?>
            <h3><?= $this->text->e($discordEventGroup) ?></h3>
            <table class="table-small">
                <tr>
                    <th><?= t('Event') ?></th>
                    <th><?= t('Discord notification') ?></th>
                    <th><?= t('Suppress Email') ?></th>
                </tr>
                <?php foreach ($discordEventOptions as $discordEventKey => $discordEventLabel): ?>
                    <?php
                        $discordEventChecked = \Kanboard\Plugin\Discord\Notification\EventRegistry::isDiscordProjectEventEnabled($discordEventKey, $rules);
                        $discordSuppressEmailChecked = \Kanboard\Plugin\Discord\Notification\EventRegistry::isProjectEmailSuppressed($discordEventKey, $rules);
                    ?>
                    <tr>
                        <td><?= $this->text->e($discordEventLabel) ?></td>
                        <td>
                            <?php if (\Kanboard\Plugin\Discord\Notification\EventRegistry::supportsDiscordProjectEvent($discordEventKey)): ?>
                                <input type="hidden" name="rules[<?= $this->text->e($discordEventKey) ?>][discord_enabled]" value="0">
                                <?= $this->form->checkbox('rules['.$discordEventKey.'][discord_enabled]', '', '1', $discordEventChecked) ?>
                            <?php else: ?>
                                <?= t('Handled by comment cards') ?>
                            <?php endif ?>
                        </td>
                        <td>
                            <input type="hidden" name="rules[<?= $this->text->e($discordEventKey) ?>][email_suppressed]" value="0">
                            <?= $this->form->checkbox('rules['.$discordEventKey.'][email_suppressed]', '', '1', $discordSuppressEmailChecked) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </table>
        <?php endforeach ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <?= $this->url->link(t('Back'), 'ProjectViewController', 'integrations', array('project_id' => $project['id'])) ?>
    </div>
</form>
