<div class="panel">
    <h3><?= t('Discord') ?></h3>
    <p class="form-help">
        <?= t('Configure your Discord User ID used for mentions in Discord notifications.') ?>
    </p>
    <?php $discordUserId = $this->request->getIntegerParam('user_id') ?>
    <a href="<?= $this->url->href('UserIntegrationController', 'show', array('plugin' => 'Discord', 'user_id' => $discordUserId)) ?>" class="btn"><?= t('Configure Discord') ?></a>
</div>
