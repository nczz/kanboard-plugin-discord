<?php

namespace Kanboard\Plugin\Discord;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Discord\Notification\DiscordNotification;

/**
 * Discord Plugin
 *
 * Sends rich Discord embed notifications for Kanboard project events and
 * mentions the Discord users mapped to the assignee / mentioned Kanboard users.
 *
 * @package Kanboard\Plugin\Discord
 */
class Plugin extends Base
{
    /**
     * Initialize plugin.
     *
     * @access public
     */
    public function initialize()
    {
        // Register the Discord notification type as a project notification.
        // It is registered as "hidden" so that it is always evaluated for every
        // project (like the built-in webhook/activity_stream types). Whether a
        // message is actually sent is gated by the presence of a webhook URL in
        // the project metadata (see DiscordNotification::notifyProject()).
        $this->projectNotificationTypeModel->setType(
            DiscordNotification::TYPE,
            'Discord',
            '\Kanboard\Plugin\Discord\Notification\DiscordNotification',
            true
        );

        // Register the same class as an opt-in user notification type so
        // Kanboard's dedicated @mention path can call notifyUser(). The class
        // intentionally ignores non-mention user notifications.
        $this->userNotificationTypeModel->setType(
            DiscordNotification::TYPE,
            'Discord',
            '\Kanboard\Plugin\Discord\Notification\DiscordNotification'
        );

        // Attach the project-level settings form (webhook URL + per-event toggles)
        // to the official third-party integrations hook. Kanboard stores the
        // submitted fields into project_has_metadata automatically.
        $this->template->hook->attach('template:project:integrations', 'discord:project/integration');

        // Attach the user-level settings form (Discord User ID for mentions) to
        // the official user integrations hook. Kanboard stores the submitted
        // fields into user_has_metadata automatically.
        $this->template->hook->attach('template:user:integrations', 'discord:user/integration');

        // Note: no CSP changes required. Discord webhooks are invoked server-side
        // via the Kanboard HTTP client, and the default img-src policy ('*') already
        // permits any embed preview images.
    }

    /**
     * Load plugin translations for the current UI language.
     *
     * @access public
     */
    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }

    /**
     * Register plugin classes in the dependency injection container.
     *
     * EmbedBuilder becomes available as $this->embedBuilder throughout the app.
     *
     * @access public
     * @return array
     */
    public function getClasses()
    {
        return array(
            'Plugin\Discord\Builder' => array(
                'EmbedBuilder',
            ),
        );
    }

    /**
     * @return string
     */
    public function getPluginName()
    {
        return 'Discord';
    }

    /**
     * @return string
     */
    public function getPluginDescription()
    {
        return t('Send rich Discord notifications per project and mention mapped Discord users');
    }

    /**
     * @return string
     */
    public function getPluginAuthor()
    {
        return 'Chun Chiang';
    }

    /**
     * @return string
     */
    public function getPluginVersion()
    {
        return '1.0.0';
    }

    /**
     * @return string
     */
    public function getPluginHomepage()
    {
        return 'https://github.com/nczz/kanboard-plugin-discord';
    }

    /**
     * Minimum compatible Kanboard version.
     *
     * @return string
     */
    public function getCompatibleVersion()
    {
        return '>=1.2.0';
    }
}
