<?php

namespace Kanboard\Plugin\Discord\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Discord\Builder\EmbedBuilder;
use Kanboard\Plugin\Discord\Notification\EventRegistry;

/**
 * Discord project settings stored in plugin-owned tables.
 */
class ProjectIntegrationController extends BaseController
{
    /**
     * Show project Discord settings.
     */
    public function show()
    {
        $project = $this->getProject();

        $this->response->html($this->helper->layout->project('discord:project/settings', array(
            'project'     => $project,
            'values'      => $this->discordSettingsModel->getProjectSettings($project['id']),
            'rules'       => $this->discordSettingsModel->getProjectEventRules($project['id']),
            'eventGroups' => EventRegistry::getEventGroups(),
            'title'       => t('Discord'),
            'errors'      => array(),
        )));
    }

    /**
     * Save project Discord settings.
     */
    public function save()
    {
        $this->checkCSRFForm();
        $project = $this->getProject();
        $values = $this->request->getValues();
        $errors = $this->validate($values);

        if (! empty($errors)) {
            $this->response->html($this->helper->layout->project('discord:project/settings', array(
                'project'     => $project,
                'values'      => $values,
                'rules'       => $this->parseRules($values),
                'eventGroups' => EventRegistry::getEventGroups(),
                'title'       => t('Discord'),
                'errors'      => $errors,
            )));
            return;
        }

        $this->discordSettingsModel->saveProjectSettings($project['id'], array(
            'webhook_url'    => isset($values['webhook_url']) ? $values['webhook_url'] : '',
            'excerpt_length' => isset($values['excerpt_length']) ? $values['excerpt_length'] : '',
        ));
        $this->discordSettingsModel->saveProjectEventRules($project['id'], $this->parseRules($values));

        $this->flash->success(t('Project updated successfully.'));
        $this->response->redirect($this->helper->url->to('ProjectIntegrationController', 'show', array('plugin' => 'Discord', 'project_id' => $project['id'])), true);
    }

    /**
     * @param array $values
     * @return array
     */
    protected function validate(array $values)
    {
        $errors = array();

        $webhookUrl = isset($values['webhook_url']) ? trim((string) $values['webhook_url']) : '';
        $notification = new \Kanboard\Plugin\Discord\Notification\DiscordNotification($this->container);
        if ($webhookUrl !== '' && ! $notification->isDiscordWebhookUrl($webhookUrl)) {
            $errors['webhook_url'] = t('Invalid Discord webhook URL.');
        } elseif ($webhookUrl !== '' && ! WEBHOOK_ALLOW_PRIVATE_NETWORKS && $this->httpClient->isPrivateURL($webhookUrl)) {
            $errors['webhook_url'] = t('Private network webhook URLs are not allowed.');
        }

        if (isset($values['excerpt_length']) && $values['excerpt_length'] !== '' && ! ctype_digit((string) $values['excerpt_length'])) {
            $errors['excerpt_length'] = t('The excerpt length must be a positive integer.');
        }

        if (isset($values['excerpt_length']) && ctype_digit((string) $values['excerpt_length']) && (int) $values['excerpt_length'] > EmbedBuilder::LIMIT_DESCRIPTION) {
            $errors['excerpt_length'] = t('The excerpt length is too large.');
        }

        return $errors;
    }

    /**
     * @param array $values
     * @return array
     */
    protected function parseRules(array $values)
    {
        $submittedRules = isset($values['rules']) && is_array($values['rules']) ? $values['rules'] : array();
        $rules = array();

        foreach (EventRegistry::getEventKeys() as $eventKey) {
            $rule = isset($submittedRules[$eventKey]) && is_array($submittedRules[$eventKey]) ? $submittedRules[$eventKey] : array();
            $rules[$eventKey] = array(
                'discord_enabled'  => EventRegistry::supportsDiscordProjectEvent($eventKey) && isset($rule['discord_enabled']) && (string) $rule['discord_enabled'] === '1' ? 1 : 0,
                'email_suppressed' => isset($rule['email_suppressed']) && (string) $rule['email_suppressed'] === '1' ? 1 : 0,
            );
        }

        return $rules;
    }
}
