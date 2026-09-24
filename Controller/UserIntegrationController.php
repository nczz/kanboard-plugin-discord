<?php

namespace Kanboard\Plugin\Discord\Controller;

use Kanboard\Controller\BaseController;

/**
 * Discord user settings stored in plugin-owned tables.
 */
class UserIntegrationController extends BaseController
{
    /**
     * Show user Discord settings.
     */
    public function show()
    {
        $user = $this->getUser();

        $this->response->html($this->helper->layout->user('discord:user/settings', array(
            'user'   => $user,
            'values' => $this->discordSettingsModel->getUserSettings($user['id']),
            'errors' => array(),
        )));
    }

    /**
     * Save user Discord settings.
     */
    public function save()
    {
        $this->checkCSRFForm();
        $user = $this->getUser();
        $values = $this->request->getValues();
        $discordUserId = isset($values['discord_user_id']) ? trim((string) $values['discord_user_id']) : '';
        $errors = array();

        if ($discordUserId !== '' && ! ctype_digit($discordUserId)) {
            $errors['discord_user_id'] = t('The Discord User ID must be numeric.');
        }

        if (! empty($errors)) {
            $this->response->html($this->helper->layout->user('discord:user/settings', array(
                'user'   => $user,
                'values' => array('discord_user_id' => $discordUserId),
                'errors' => $errors,
            )));
            return;
        }

        $this->discordSettingsModel->saveDiscordUserId($user['id'], $discordUserId);
        $this->flash->success(t('User updated successfully.'));
        $this->response->redirect($this->helper->url->to('UserIntegrationController', 'show', array('plugin' => 'Discord', 'user_id' => $user['id'])), true);
    }
}
