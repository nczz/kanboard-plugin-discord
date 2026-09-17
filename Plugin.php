<?php

namespace Kanboard\Plugin\Discord;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Discord\Console\TaskOverdueNotificationCommand;
use Kanboard\Plugin\Discord\Notification\DiscordNotification;
use Kanboard\Model\UserModel;

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
     * Config flag recording the first default user notification activation.
     */
    const CONFIG_DEFAULT_USER_NOTIFICATIONS_ENABLED = 'discord_default_user_notifications_enabled';

    /**
     * Config flag recording the metadata backfill for users processed before
     * per-user default notification markers existed.
     */
    const CONFIG_DEFAULT_USER_NOTIFICATIONS_MARKED = 'discord_default_user_notifications_marked';

    /**
     * User metadata flag showing this user already received the default
     * Discord notification decision. It preserves later opt-outs while allowing
     * new users to be enabled on the next plugin initialization.
     */
    const USER_META_DEFAULT_USER_NOTIFICATIONS_PROCESSED = 'discord.default_user_notifications.processed';

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

        $this->enableDefaultUserNotifications();

        // Kanboard core sends overdue tasks only from the CLI command through
        // user notification types. Replace that command with a compatible
        // subclass that also emits project-level Discord webhook cards according
        // to the per-project Discord event settings.
        if (isset($this->container['cli'])) {
            $this->container['cli']->add(new TaskOverdueNotificationCommand($this->container));
        }

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
     * Enable the Discord user-notification type by default for active users.
     *
     * Existing active users are enabled once when the plugin is installed. Each
     * processed user is then marked in user metadata so later requests can enable
     * only newly created or newly activated users without overriding an existing
     * user's deliberate opt-out.
     *
     * @access protected
     */
    protected function enableDefaultUserNotifications()
    {
        $defaultsApplied = $this->configModel->getOption(self::CONFIG_DEFAULT_USER_NOTIFICATIONS_ENABLED, '') === '1';
        $markersBackfilled = $this->configModel->getOption(self::CONFIG_DEFAULT_USER_NOTIFICATIONS_MARKED, '') === '1';

        $users = $this->db->table(UserModel::TABLE)
            ->columns('id')
            ->eq('is_active', 1)
            ->findAll();

        $this->db->startTransaction();
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            if ($userId <= 0) {
                continue;
            }

            if ($defaultsApplied && ! $markersBackfilled) {
                $this->markDefaultUserNotificationProcessed($userId);
                continue;
            }

            if ($this->isDefaultUserNotificationProcessed($userId)) {
                continue;
            }

            $this->enableDiscordUserNotificationType($userId);
            $this->markDefaultUserNotificationProcessed($userId);
        }
        $this->db->closeTransaction();

        $options = array();
        if (! $defaultsApplied) {
            $options[self::CONFIG_DEFAULT_USER_NOTIFICATIONS_ENABLED] = '1';
        }
        if (! $markersBackfilled) {
            $options[self::CONFIG_DEFAULT_USER_NOTIFICATIONS_MARKED] = '1';
        }
        if (! empty($options)) {
            $this->configModel->save($options);
        }
    }

    /**
     * Return true when the default notification decision was already recorded
     * for this user.
     *
     * @access protected
     * @param integer $userId
     * @return boolean
     */
    protected function isDefaultUserNotificationProcessed($userId)
    {
        return $this->db->table('user_has_metadata')
            ->eq('user_id', $userId)
            ->eq('name', self::USER_META_DEFAULT_USER_NOTIFICATIONS_PROCESSED)
            ->exists();
    }

    /**
     * Mark a user as processed by the default Discord notification bootstrap.
     *
     * @access protected
     * @param integer $userId
     */
    protected function markDefaultUserNotificationProcessed($userId)
    {
        if ($this->isDefaultUserNotificationProcessed($userId)) {
            return;
        }
        $this->db->table('user_has_metadata')->insert(array(
            'user_id' => $userId,
            'name' => self::USER_META_DEFAULT_USER_NOTIFICATIONS_PROCESSED,
            'value' => '1',
        ));
    }

    /**
     * Enable Discord as a selected user notification type if it is missing.
     *
     * @access protected
     * @param integer $userId
     */
    protected function enableDiscordUserNotificationType($userId)
    {
        $exists = $this->db->table('user_has_notification_types')
            ->eq('user_id', $userId)
            ->eq('notification_type', DiscordNotification::TYPE)
            ->exists();

        if (! $exists) {
            $this->db->table('user_has_notification_types')->insert(array(
                'user_id' => $userId,
                'notification_type' => DiscordNotification::TYPE,
            ));
        }
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
