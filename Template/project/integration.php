<div class="panel">
    <h3><?= t('Discord') ?></h3>
    <p class="form-help">
        <?= t('Configure the Discord webhook URL, content excerpt length and notification events for this project.') ?>
    </p>
    <a href="<?= $this->url->href('ProjectIntegrationController', 'show', array('plugin' => 'Discord', 'project_id' => $project['id'])) ?>" class="btn"><?= t('Configure Discord') ?></a>
</div>
