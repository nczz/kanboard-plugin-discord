<?php

namespace Kanboard\Plugin\Discord;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Discord\Console\TaskOverdueNotificationCommand;
use Kanboard\Plugin\Discord\Notification\DiscordNotification;
use Kanboard\Model\UserModel;
use Kanboard\Notification\MailNotification;

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
     * per-user default notification markers moved to plugin-owned tables.
     */
    const CONFIG_DEFAULT_USER_NOTIFICATIONS_MARKED = 'discord_default_user_notifications_marked';

    /**
     * Legacy user metadata flag migrated into discord_user_settings.
     */
    const USER_META_DEFAULT_USER_NOTIFICATIONS_PROCESSED = 'discord.default_user_notifications.processed';

    /**
     * Initialize plugin.
     *
     * @access public
     */
    public function initialize()
    {
        // Plugin-owned service overrides keep clean Kanboard core compatible:
        // - TaskEventJob exposes the previous task row already passed by core.
        // - SubtaskModel (registered in getClasses) preserves previous subtask
        //   values before core dispatches subtask.update.
        $this->container['taskEventJob'] = $this->container->factory(function ($container) {
            return new \Kanboard\Plugin\Discord\Job\TaskEventJob($container);
        });

        // Register the Discord notification type as a project notification.
        // It is registered as "hidden" so that it is always evaluated for every
        // project. Whether a message is actually sent is gated by the presence
        // of either a project webhook URL or the global fallback webhook URL
        // (see DiscordNotification::notifyProject()).
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

        // Keep Kanboard's Email notification type selected exactly as users
        // configured it, but route delivery through the plugin wrapper so
        // project/task notification rules can suppress specific Email events.
        $this->userNotificationTypeModel->setType(
            MailNotification::TYPE,
            t('Email'),
            '\Kanboard\Plugin\Discord\Notification\ConditionalMailNotification'
        );

        $this->enableDefaultUserNotifications();

        // Kanboard core sends overdue tasks only from the CLI command through
        // user notification types. Replace that command with a compatible
        // subclass that also emits project-level Discord webhook cards according
        // to the per-project Discord event settings.
        if (isset($this->container['cli'])) {
            $this->container['cli']->add(new TaskOverdueNotificationCommand($this->container));
        }

        // Attach the system-level fallback webhook setting to the official
        // integrations settings page. Kanboard stores submitted fields into the
        // config table automatically.
        $this->template->hook->attach('template:config:integrations', 'discord:config/integration');

        // Attach links to plugin-owned project/user settings pages. Those pages
        // save Discord fields through plugin controllers instead of Kanboard's
        // metadata-backed core integration forms.
        $this->template->hook->attach('template:project:integrations', 'discord:project/integration');
        $this->template->hook->attach('template:user:integrations', 'discord:user/integration');

        // Add a task-level notification rule editor backed by plugin-owned
        // discord_task_event_rules rows.
        $this->template->hook->attach('template:task:sidebar:after-basic-actions', 'discord:task/notification_rules_link');
        $this->template->hook->attach('template:task:dropdown:after-basic-actions', 'discord:task/notification_rules_link');

        $this->projectAccessMap->add('ProjectIntegrationController', array('show', 'save'), Role::PROJECT_MANAGER);
        $this->projectAccessMap->add('TaskNotificationSettingsController', array('show', 'save'), Role::PROJECT_MEMBER);
        $this->applicationAccessMap->add('UserIntegrationController', array('show', 'save'), Role::APP_USER);

        $this->hook->on('model:task:duplication:aftersave', array($this, 'copyTaskEventRules'));
        $this->hook->on('model:task:project_duplication:aftersave', array($this, 'copyTaskEventRules'));

        // Note: no CSP changes required. Discord webhooks are invoked server-side
        // via the Kanboard HTTP client, and the default img-src policy ('*') already
        // permits any embed preview images.
    }

    /**
     * Existing active users are enabled once when the plugin is installed. Each
     * processed user is then marked in plugin-owned user settings so later
     * requests can enable only newly created or newly activated users without
     * overriding an existing user's deliberate opt-out.
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
        return $this->discordSettingsModel->isDefaultNotificationsProcessed($userId);
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

        $this->discordSettingsModel->markDefaultNotificationsProcessed($userId);
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
     * Copy plugin-owned task notification rules after Kanboard duplicates a task.
     *
     * @param array $values
     */
    public function copyTaskEventRules(array &$values)
    {
        if (empty($values['source_task_id']) || empty($values['destination_task_id'])) {
            return;
        }

        $this->discordSettingsModel->duplicateTaskRules($values['source_task_id'], $values['destination_task_id']);
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
            'Plugin\Discord\Model' => array(
                'SubtaskModel',
                'DiscordSettingsModel',
                'ProjectDuplicationModel',
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
        return '1.1.2';
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
