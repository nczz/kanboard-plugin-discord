<?php

namespace KanboardTests\units\Plugin\Discord;

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Discord\Notification\DiscordNotification;
use Kanboard\Plugin\Discord\Plugin;

/**
 * End-to-end tests for the Discord plugin running against real Kanboard classes.
 */
class DiscordNotificationTest extends Base
{
    private function loadPlugin()
    {
        $plugin = new Plugin($this->container);
        \Kanboard\Core\Tool::buildDIC($this->container, $plugin->getClasses());
        $plugin->initialize();
        return $plugin;
    }

    private function mockHttp()
    {
        $this->container['httpClient'] = $this
            ->getMockBuilder('\Kanboard\Core\Http\Client')
            ->setConstructorArgs(array($this->container))
            ->onlyMethods(array('postJson', 'isPrivateURL'))
            ->getMock();
        $this->container['httpClient']->method('isPrivateURL')->willReturn(false);
        return $this->container['httpClient'];
    }

    public function testPluginRegistersDiscordType()
    {
        $this->loadPlugin();
        $projectTypes = $this->container['projectNotificationTypeModel']->getHiddenTypes();
        $this->assertContains(DiscordNotification::TYPE, $projectTypes);

        $userTypes = $this->container['userNotificationTypeModel']->getTypes();
        $this->assertArrayHasKey(DiscordNotification::TYPE, $userTypes);
    }

    public function testNoWebhookMeansNoSend()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'no-webhook'));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'no-webhook', 'title' => 'x', 'owner_id' => 0))
        );
    }

    public function testEventFilterBlocksDisabledProjectEvent()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'filtered'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_create') => '0',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 2, 'project_id' => $projectId, 'project_name' => 'filtered', 'title' => 'x', 'owner_id' => 0))
        );
    }

    public function testEventFilterAllowsEnabledProjectEvent()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'allowed'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_create') => '1',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 3, 'project_id' => $projectId, 'project_name' => 'allowed', 'title' => 'x', 'owner_id' => 0))
        );

        $this->assertArrayHasKey('embeds', $captured);
    }

    public function testMoveEventsAreDisabledByDefault()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'move-default-off'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_MOVE_COLUMN,
            array('task' => array(
                'id' => 4,
                'project_id' => $projectId,
                'project_name' => 'move-default-off',
                'title' => 'x',
                'column_title' => 'Done',
                'owner_id' => 0,
            ))
        );
    }

    public function testMoveEventFilterAllowsExplicitlyEnabledMove()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'move-enabled'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_move_column') => '1',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_MOVE_COLUMN,
            array('task' => array(
                'id' => 5,
                'project_id' => $projectId,
                'project_name' => 'move-enabled',
                'title' => 'x',
                'column_title' => 'Done',
                'owner_id' => 0,
            ))
        );

        $this->assertArrayHasKey('embeds', $captured);
    }

    public function testLegacyTaskUpdateSettingDoesNotEnableSplitMoveEvents()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'legacy-move-off'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_update') => '1',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_MOVE_COLUMN,
            array('task' => array(
                'id' => 6,
                'project_id' => $projectId,
                'project_name' => 'legacy-move-off',
                'title' => 'x',
                'column_title' => 'Done',
                'owner_id' => 0,
            ))
        );
    }

    public function testLegacyCloseOpenSettingStillControlsSplitClose()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'legacy-close-off'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_close_open') => '0',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CLOSE,
            array('task' => array('id' => 7, 'project_id' => $projectId, 'project_name' => 'legacy-close-off', 'title' => 'x', 'owner_id' => 0))
        );
    }

    public function testEventFilterBlocksOverdueFanout()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'overdue-off'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_overdue') => '0',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_OVERDUE,
            array('tasks' => array(
                array('id' => 101, 'project_id' => $projectId, 'project_name' => 'overdue-off', 'title' => 'First overdue', 'owner_id' => 0),
            ))
        );
    }

    public function testProjectNotificationBuildsEmbedAndMentionsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'My Project'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/123/abc',
        ));

        $userId = $userModel->create(array('username' => 'alice', 'name' => 'Alice'));
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '987654321012345678',
        ));

        $captured = null;
        $http->expects($this->once())
            ->method('postJson')
            ->with(
                $this->identicalTo('https://discord.com/api/webhooks/123/abc'),
                $this->callback(function ($payload) use (&$captured) {
                    $captured = $payload;
                    return true;
                }),
                $this->identicalTo(array()),
                $this->identicalTo(false),
                $this->identicalTo(false)
            );

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array(
                'id' => 42,
                'project_id' => $projectId,
                'project_name' => 'My Project',
                'title' => 'Fix the bug',
                'description' => 'Some description',
                'owner_id' => $userId,
                'assignee_name' => 'Alice',
                'assignee_username' => 'alice',
                'column_title' => 'Backlog',
            ))
        );

        // Payload shape
        $this->assertArrayHasKey('embeds', $captured);
        $this->assertArrayHasKey('content', $captured);
        $this->assertSame('Kanboard', $captured['username']);
        $this->assertStringContainsString('<@987654321012345678>', $captured['content']);
        $this->assertSame(array('parse' => array('users')), $captured['allowed_mentions']);

        $embed = $captured['embeds'][0];
        // Title always identifies the task: "#42 · <title>"
        $this->assertStringContainsString('#42', $embed['title']);
        $this->assertStringContainsString('Fix the bug', $embed['title']);
        // Description leads with a complete action sentence, then the excerpt.
        $this->assertStringContainsString('#42', $embed['description']);
        $this->assertStringContainsString('Some description', $embed['description']);
        // Footer carries the project name.
        $this->assertSame('My Project', $embed['footer']['text']);
        $this->assertIsInt($embed['color']);

        // Fields include assignee + column
        $fieldNames = array_column($embed['fields'], 'value');
        $this->assertContains('Alice', $fieldNames);
        $this->assertContains('Backlog', $fieldNames);
    }

    public function testExcerptLengthDefaultTruncatesContent()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'EX'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $longDesc = str_repeat('B', 1000);
        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 5, 'project_id' => $projectId, 'project_name' => 'EX', 'title' => 't', 'description' => $longDesc, 'owner_id' => 0))
        );

        // The excerpt portion must be capped near the default (280), not 1000.
        // The description also holds the (short) action sentence, so allow headroom.
        $this->assertLessThan(500, mb_strlen($captured['embeds'][0]['description']));
    }

    public function testEmptyProjectExcerptLengthFallsBackToDefaultAndShowsComment()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'EXEMPTY'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            \Kanboard\Plugin\Discord\Builder\EmbedBuilder::KEY_EXCERPT_LENGTH => '',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 6, 'project_id' => $projectId, 'project_name' => 'EXEMPTY', 'title' => 't', 'owner_id' => 0),
                'comment' => array('comment' => 'comment body must be visible', 'user_id' => 0, 'task_id' => 6),
            )
        );

        $this->assertStringContainsString('💬', $captured['embeds'][0]['description']);
        $this->assertStringContainsString('comment body must be visible', $captured['embeds'][0]['description']);
    }

    public function testExcerptLengthConfigurablePerProject()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'EX2'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            \Kanboard\Plugin\Discord\Builder\EmbedBuilder::KEY_EXCERPT_LENGTH => '10',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 5, 'project_id' => $projectId, 'project_name' => 'EX2', 'title' => 't', 'description' => str_repeat('C', 200), 'owner_id' => 0))
        );

        // With a 10-char excerpt cap, the long run of "C" must be trimmed hard.
        $this->assertLessThanOrEqual(11, substr_count($captured['embeds'][0]['description'], 'C'));
    }

    public function testExcerptLengthZeroHidesContent()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'EX0'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            \Kanboard\Plugin\Discord\Builder\EmbedBuilder::KEY_EXCERPT_LENGTH => '0',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 5, 'project_id' => $projectId, 'project_name' => 'EX0', 'title' => 't', 'description' => 'this should be hidden', 'owner_id' => 0))
        );

        // Excerpt hidden -> the task description text must not appear.
        $this->assertStringNotContainsString('this should be hidden', $captured['embeds'][0]['description']);
        // But the action sentence (status) is still present.
        $this->assertNotSame('', $captured['embeds'][0]['description']);
    }

    public function testAssigneeWithoutDiscordIdProducesNoMention()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'P2'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));
        $userId = $userModel->create(array('username' => 'bob', 'name' => 'Bob'));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'P2', 'title' => 't', 'owner_id' => $userId))
        );

        $this->assertArrayNotHasKey('content', $captured);
        $this->assertArrayNotHasKey('allowed_mentions', $captured);
    }

    public function testNonDiscordWebhookRejected()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'evil'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://evil.example.com/api/webhooks/1/x',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'evil', 'title' => 't', 'owner_id' => 0))
        );
    }

    public function testTaskMentionEventNotifiesUser()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'MP'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/9/z',
        ));
        $userId = $userModel->create(array('username' => 'carol', 'name' => 'Carol'));
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '111222333444555666',
        ));

        $this->container['userNotificationTypeModel']->saveSelectedTypes($userId, array(DiscordNotification::TYPE));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $this->container['userNotificationModel']->sendUserNotification(
            $userModel->getById($userId),
            \Kanboard\Model\TaskModel::EVENT_USER_MENTION,
            array(
                'task' => array('id' => 7, 'project_id' => $projectId, 'project_name' => 'MP', 'title' => 'Task 7', 'description' => 'Hey @carol look at this'),
            )
        );

        $this->assertStringContainsString('<@111222333444555666>', $captured['content']);
        $this->assertStringContainsString('Hey @carol', $captured['embeds'][0]['description']);
    }

    public function testCommentMentionUserNotificationDoesNotSendDuplicateCard()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CMDUP'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/9/z',
        ));
        $userId = $userModel->create(array('username' => 'dupe', 'name' => 'Dupe'));
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '111222333444555669',
        ));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($userId, array(DiscordNotification::TYPE));

        $this->container['userNotificationModel']->sendUserNotification(
            $userModel->getById($userId),
            \Kanboard\Model\CommentModel::EVENT_USER_MENTION,
            array(
                'task' => array('id' => 11, 'project_id' => $projectId, 'project_name' => 'CMDUP', 'title' => 'Task 11'),
                'comment' => array('comment' => 'Hey @dupe'),
            )
        );
    }

    public function testTaskMentionEventWithoutDiscordIdDoesNotSendUserCard()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'MNOID'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/9/z',
        ));
        $userId = $userModel->create(array('username' => 'nodc', 'name' => 'No Discord'));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($userId, array(DiscordNotification::TYPE));

        $this->container['userNotificationModel']->sendUserNotification(
            $userModel->getById($userId),
            \Kanboard\Model\TaskModel::EVENT_USER_MENTION,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'MNOID', 'title' => 'Task 9', 'description' => 'Hey @nodc look at this'),
            )
        );
    }

    public function testEventFilterBlocksTaskMentionUserNotification()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'MOFF'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/9/z',
            DiscordNotification::getEventMetadataKey('mention') => '0',
        ));
        $userId = $userModel->create(array('username' => 'moff', 'name' => 'Mention Off'));
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '111222333444555668',
        ));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($userId, array(DiscordNotification::TYPE));

        $this->container['userNotificationModel']->sendUserNotification(
            $userModel->getById($userId),
            \Kanboard\Model\TaskModel::EVENT_USER_MENTION,
            array('task' => array('id' => 10, 'project_id' => $projectId, 'project_name' => 'MOFF', 'title' => 'Task 10', 'description' => 'Hey @moff'))
        );
    }

    public function testDiscordUserNotificationIgnoresNonMentionEvents()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'NM'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/9/z',
        ));
        $userId = $userModel->create(array('username' => 'nina', 'name' => 'Nina'));
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '111222333444555667',
        ));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($userId, array(DiscordNotification::TYPE));

        $this->container['userNotificationModel']->sendUserNotification(
            $userModel->getById($userId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 8, 'project_id' => $projectId, 'project_name' => 'NM', 'title' => 'Task 8'))
        );
    }

    public function testOverdueFansOutPerTaskWithDistinctTitles()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'OD'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $titles = array();
        $http->expects($this->exactly(2))->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$titles) {
                $titles[] = $payload['embeds'][0]['title'];
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_OVERDUE,
            array('tasks' => array(
                array('id' => 101, 'project_id' => $projectId, 'project_name' => 'OD', 'title' => 'First overdue', 'owner_id' => 0),
                array('id' => 202, 'project_id' => $projectId, 'project_name' => 'OD', 'title' => 'Second overdue', 'owner_id' => 0),
            ))
        );

        // Each embed must reference its own task id (C1 regression).
        $this->assertStringContainsString('101', $titles[0]);
        $this->assertStringContainsString('202', $titles[1]);
        $this->assertNotSame($titles[0], $titles[1]);
    }

    public function testInjectionUserIdProducesNoMention()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectId = $projectModel->create(array('name' => 'INJ'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));
        $userId = $userModel->create(array('username' => 'dan', 'name' => 'Dan'));
        // Malicious / malformed values must be rejected by ctype_digit.
        $this->container['userMetadataModel']->save($userId, array(
            DiscordNotification::META_USER_ID => '@everyone <@&12345>',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'INJ', 'title' => 't', 'owner_id' => $userId))
        );

        $this->assertArrayNotHasKey('content', $captured);
    }

    public function testMarkdownInDescriptionIsEscaped()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'MD'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array(
                'id' => 1, 'project_id' => $projectId, 'project_name' => 'MD', 'title' => 't',
                'description' => 'click [here](http://evil.example) **now**', 'owner_id' => 0,
            ))
        );

        // Markdown control chars must be backslash-escaped (no raw masked link).
        $this->assertStringNotContainsString('[here](http://evil.example)', $captured['embeds'][0]['description']);
        $this->assertStringContainsString('\\[here\\]', $captured['embeds'][0]['description']);
    }

    public function testNonWebhookDiscordPathRejected()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'P'));
        // Valid host but not a webhook path.
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/login',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'P', 'title' => 't', 'owner_id' => 0))
        );
    }

    public function testNonWebhookDiscordApiPathRejected()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'PAPI'));
        // Valid host and API prefix, but not a webhook execution path.
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/v10/users/@me',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'PAPI', 'title' => 't', 'owner_id' => 0))
        );
    }

    public function testIncompleteDiscordWebhookPathRejected()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'PINC'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/123',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'PINC', 'title' => 't', 'owner_id' => 0))
        );
    }

    public function testDiscordWebhookSubresourceRejected()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();
        $http->expects($this->never())->method('postJson');

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'PSUB'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/123/token/messages/456',
        ));

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'PSUB', 'title' => 't', 'owner_id' => 0))
        );
    }

    public function testVersionedDiscordWebhookPathAccepted()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'PV'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/v10/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'PV', 'title' => 't', 'owner_id' => 0))
        );

        $this->assertArrayHasKey('embeds', $captured);
    }

    public function testLongTitleTruncatedToDiscordLimit()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'LT'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $longTitle = str_repeat('A', 500);
        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'LT', 'title' => $longTitle, 'owner_id' => 0))
        );

        $this->assertLessThanOrEqual(256, mb_strlen($captured['embeds'][0]['title']));
    }

    public function testSubtaskDetailIncludesStatusAssigneeAndTime()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'ST'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\SubtaskModel::EVENT_UPDATE,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'ST', 'title' => 'Parent task'),
                'subtask' => array(
                    'id' => 3, 'task_id' => 9, 'title' => 'Write the docs',
                    'status' => 1, 'status_name' => 'In progress',
                    'name' => 'Erin', 'username' => 'erin',
                    'time_estimated' => 4, 'time_spent' => 1.5,
                ),
                'changes' => array('status' => 1, 'time_spent' => 1.5),
            )
        );

        $desc = $captured['embeds'][0]['description'];
        // Status sentence present.
        $this->assertStringContainsString('#9', $desc);
        // Subtask detail: title, status, assignee, time.
        $this->assertStringContainsString('Write the docs', $desc);
        $this->assertStringContainsString('Erin', $desc);
        $this->assertStringContainsString('1.5/4h', $desc);
        // Status symbol for "in progress".
        $this->assertStringContainsString('🕘', $desc);
        // Update event lists what changed (Status, Time spent), not internal keys.
        $this->assertStringContainsString('Changed', $desc);
        $this->assertStringContainsString('Status', $desc);
    }

    public function testSubtaskDetailShownEvenWhenExcerptDisabled()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'ST0'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            \Kanboard\Plugin\Discord\Builder\EmbedBuilder::KEY_EXCERPT_LENGTH => '0',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\SubtaskModel::EVENT_CREATE,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'ST0', 'title' => 'Parent'),
                'subtask' => array('id' => 3, 'task_id' => 9, 'title' => 'A subtask', 'status' => 0, 'status_name' => 'Todo'),
            )
        );

        // Subtask detail is structured status, so it must appear even with excerpt=0.
        $this->assertStringContainsString('A subtask', $captured['embeds'][0]['description']);
    }

    public function testAttachmentShowsFilename()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'AT'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskFileModel::EVENT_CREATE,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'AT', 'title' => 'T'),
                'file' => array('name' => 'design-spec.pdf', 'task_id' => 9),
            )
        );

        $this->assertStringContainsString('design-spec.pdf', $captured['embeds'][0]['description']);
    }

    public function testTaskUpdateShowsChangedFields()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'UP'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_UPDATE,
            array(
                'task' => array('id' => 12, 'project_id' => $projectId, 'project_name' => 'UP', 'title' => 'T', 'owner_id' => 0),
                'changes' => array('priority' => 2, 'due_date' => 1710000000, 'date_modification' => 123),
            )
        );

        $desc = $captured['embeds'][0]['description'];
        // "Changed" line lists human labels, ignores internal timestamps.
        $this->assertStringContainsString('Priority', $desc);
        $this->assertStringContainsString('Due Date', $desc);
        $this->assertStringNotContainsString('date_modification', $desc);
    }

    public function testDescriptionNeverExceedsDiscordLimit()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'BIG'));
        // Huge excerpt cap to try to overflow the description field.
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            \Kanboard\Plugin\Discord\Builder\EmbedBuilder::KEY_EXCERPT_LENGTH => '4096',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array(
                'id' => 1, 'project_id' => $projectId, 'project_name' => 'BIG', 'title' => 'T',
                'description' => str_repeat("line\n", 2000), 'owner_id' => 0,
            ))
        );

        $embed = $captured['embeds'][0];
        // description within Discord limit, and total embed within 6000.
        $this->assertLessThanOrEqual(4096, mb_strlen($embed['description']));
        $total = mb_strlen($embed['title']) + mb_strlen($embed['description'])
            + mb_strlen($embed['footer']['text'] ?? '');
        foreach ($embed['fields'] as $f) {
            $total += mb_strlen($f['name']) + mb_strlen($f['value']);
        }
        $this->assertLessThanOrEqual(6000, $total);
    }

    public function testHtmlEntitiesFromCoreTitlesAreDecoded()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'ENT'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
            DiscordNotification::getEventMetadataKey('task_move_column') => '1',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        // Column title with characters that Kanboard's e() would HTML-escape.
        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_MOVE_COLUMN,
            array('task' => array(
                'id' => 1, 'project_id' => $projectId, 'project_name' => 'ENT', 'title' => 'T',
                'column_title' => 'R&D "urgent"', 'owner_id' => 0,
            ))
        );

        $desc = $captured['embeds'][0]['description'];
        // Entities must be decoded for the Discord plaintext path.
        $this->assertStringNotContainsString('&amp;', $desc);
        $this->assertStringNotContainsString('&#039;', $desc);
        $this->assertStringNotContainsString('&quot;', $desc);
    }

    public function testEmbedTitleEscapesMarkdown()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'MD2'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\TaskModel::EVENT_CREATE,
            array('task' => array('id' => 1, 'project_id' => $projectId, 'project_name' => 'MD2', 'title' => 'Fix **login**', 'owner_id' => 0))
        );

        // Markdown control chars in the title must be escaped so it renders literally.
        $this->assertStringContainsString('\\*\\*login\\*\\*', $captured['embeds'][0]['title']);
    }

    public function testSubtaskMissingStatusAndTitleDoesNotFatal()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $projectId = $projectModel->create(array('name' => 'MISS'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        // Subtask array missing 'status' and 'title' must not raise a warning/fatal.
        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\SubtaskModel::EVENT_CREATE,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'MISS', 'title' => 'Parent'),
                'subtask' => array('id' => 3, 'task_id' => 9),
            )
        );

        $this->assertArrayHasKey('embeds', $captured);
    }

    /**
     * Comment mentioning a mapped project member pings that mentioned member on
     * the comment card, not the assignee. The comment text remains visible on
     * the same card so the recipient sees why they were pinged.
     */
    public function testCommentMentioningMemberPingsMentionedUser()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CM'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        // Assignee (owner) has a Discord ID.
        $assigneeId = $userModel->create(array('username' => 'boss', 'name' => 'Boss'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '100000000000000001',
        ));

        // Mentioned member of the project.
        $memberId = $userModel->create(array('username' => 'zoe', 'name' => 'Zoe', 'notifications_enabled' => 1));
        $projectUserRoleModel->addUser($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->container['userMetadataModel']->save($memberId, array(
            DiscordNotification::META_USER_ID => '700000000000000007',
        ));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($memberId, array(DiscordNotification::TYPE));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 7, 'project_id' => $projectId, 'project_name' => 'CM', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'Please review @zoe', 'user_id' => 999, 'task_id' => 7),
            )
        );

        // Comment mentions a mapped member -> ping the mentioned user, not the assignee.
        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@700000000000000007>', $captured['content']);
        $this->assertStringNotContainsString('<@100000000000000001>', $captured['content']);
        // The comment content is still visible on the card.
        $this->assertStringContainsString('Please review', $captured['embeds'][0]['description']);
        $this->assertStringContainsString('💬', $captured['embeds'][0]['description']);
    }

    /**
     * A project member without a valid Discord ID cannot receive a Discord
     * @mention, so the project card must keep the assignee fallback.
     */
    public function testCommentMentioningMemberWithoutDiscordIdStillPingsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CND'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'anchor', 'name' => 'Anchor'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '800000000000000008',
        ));
        $memberId = $userModel->create(array('username' => 'nomap', 'name' => 'No Map', 'notifications_enabled' => 1));
        $projectUserRoleModel->addUser($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->container['userNotificationTypeModel']->saveSelectedTypes($memberId, array(DiscordNotification::TYPE));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 13, 'project_id' => $projectId, 'project_name' => 'CND', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'please check @nomap', 'user_id' => 999, 'task_id' => 13),
            )
        );

        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@800000000000000008>', $captured['content']);
    }

    /**
     * Comment mentions do not depend on the mentioned user's Kanboard
     * notification-type selection: a mapped Discord ID plus project membership
     * is enough for the project comment card to ping the intended recipient.
     */
    public function testCommentMentioningMemberWithoutDiscordNotificationTypePingsMentionedUser()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CNT'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'reserve', 'name' => 'Reserve'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '900000000000000009',
        ));
        $memberId = $userModel->create(array('username' => 'unselected', 'name' => 'Unselected', 'notifications_enabled' => 1));
        $projectUserRoleModel->addUser($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->container['userMetadataModel']->save($memberId, array(
            DiscordNotification::META_USER_ID => '900000000000000010',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 14, 'project_id' => $projectId, 'project_name' => 'CNT', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'please check @unselected', 'user_id' => 999, 'task_id' => 14),
            )
        );

        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@900000000000000010>', $captured['content']);
        $this->assertStringNotContainsString('<@900000000000000009>', $captured['content']);
    }

    /**
     * Comment mentioning nobody (or nobody who is a project member): fall back
     * to pinging the task assignee.
     */
    public function testCommentWithoutMentionPingsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CN'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'chief', 'name' => 'Chief'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '200000000000000002',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 8, 'project_id' => $projectId, 'project_name' => 'CN', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'No mention here', 'user_id' => 999, 'task_id' => 8),
            )
        );

        // No mention in the comment -> ping the assignee.
        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@200000000000000002>', $captured['content']);
    }

    /**
     * A comment that mentions a username that is NOT a project member must not
     * suppress the assignee ping (only real project members count).
     */
    public function testCommentMentioningNonMemberStillPingsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CNM'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'lead', 'name' => 'Lead'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '300000000000000003',
        ));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 9, 'project_id' => $projectId, 'project_name' => 'CNM', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'ping @ghost who is not a member', 'user_id' => 999, 'task_id' => 9),
            )
        );

        // Mentioned user is not a project member -> assignee still pinged.
        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@300000000000000003>', $captured['content']);
    }

    /**
     * Comment mentions are based on the plugin's Discord mapping, not Kanboard's
     * regular notification toggle. If a project member has a Discord ID mapped,
     * the comment card pings that mentioned member instead of the assignee.
     */
    public function testCommentMentioningNotificationDisabledMemberPingsMentionedUser()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CD'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'fallback', 'name' => 'Fallback'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '600000000000000006',
        ));
        $memberId = $userModel->create(array(
            'username' => 'muted',
            'name' => 'Muted',
            'notifications_enabled' => 0,
        ));
        $projectUserRoleModel->addUser($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->container['userMetadataModel']->save($memberId, array(
            DiscordNotification::META_USER_ID => '600000000000000007',
        ));
        $this->container['userNotificationTypeModel']->saveSelectedTypes($memberId, array(DiscordNotification::TYPE));

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 12, 'project_id' => $projectId, 'project_name' => 'CD', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'please check @muted', 'user_id' => 999, 'task_id' => 12),
            )
        );

        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@600000000000000007>', $captured['content']);
        $this->assertStringNotContainsString('<@600000000000000006>', $captured['content']);
    }

    /**
     * comment.update has no dedicated @mention path in Kanboard core, so even a
     * comment that mentions a member must still ping the assignee on update (no
     * suppression), otherwise the edit would notify nobody.
     */
    public function testCommentUpdateMentioningMemberStillPingsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CU'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'mgr', 'name' => 'Mgr'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '400000000000000004',
        ));
        $memberId = $userModel->create(array('username' => 'yan', 'name' => 'Yan'));
        $projectUserRoleModel->addUser($projectId, $memberId, Role::PROJECT_MEMBER);

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_UPDATE,
            array(
                'task' => array('id' => 10, 'project_id' => $projectId, 'project_name' => 'CU', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'edited @yan', 'user_id' => 999, 'task_id' => 10),
            )
        );

        // Update event -> assignee is pinged regardless of the mention.
        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@400000000000000004>', $captured['content']);
    }

    /**
     * A comment whose ONLY mention is the author mentioning themselves must not
     * suppress the assignee ping (core sends no self @mention notification).
     */
    public function testCommentSelfMentionStillPingsAssignee()
    {
        $this->loadPlugin();
        $http = $this->mockHttp();

        $projectModel = new ProjectModel($this->container);
        $userModel = new UserModel($this->container);
        $projectUserRoleModel = new ProjectUserRoleModel($this->container);

        $projectId = $projectModel->create(array('name' => 'CS'));
        $this->container['projectMetadataModel']->save($projectId, array(
            DiscordNotification::META_WEBHOOK_URL => 'https://discord.com/api/webhooks/1/x',
        ));

        $assigneeId = $userModel->create(array('username' => 'own', 'name' => 'Own'));
        $this->container['userMetadataModel']->save($assigneeId, array(
            DiscordNotification::META_USER_ID => '500000000000000005',
        ));
        // The author is also a project member and mentions themselves.
        $authorId = $userModel->create(array('username' => 'self', 'name' => 'Self'));
        $projectUserRoleModel->addUser($projectId, $authorId, Role::PROJECT_MEMBER);

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyProject(
            $projectModel->getById($projectId),
            \Kanboard\Model\CommentModel::EVENT_CREATE,
            array(
                'task' => array('id' => 11, 'project_id' => $projectId, 'project_name' => 'CS', 'title' => 'T', 'owner_id' => $assigneeId),
                'comment' => array('comment' => 'note to @self', 'user_id' => $authorId, 'task_id' => 11),
            )
        );

        // Only a self-mention -> not treated as "mentions a member" -> assignee pinged.
        $this->assertArrayHasKey('content', $captured);
        $this->assertStringContainsString('<@500000000000000005>', $captured['content']);
    }
}
