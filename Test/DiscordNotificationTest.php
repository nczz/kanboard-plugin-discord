<?php

namespace KanboardTests\units\Plugin\Discord;

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
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
        $types = $this->container['projectNotificationTypeModel']->getHiddenTypes();
        $this->assertContains('discord', $types);
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
        $this->assertStringContainsString('[My Project]', $embed['title']);
        $this->assertSame('Some description', $embed['description']);
        $this->assertIsInt($embed['color']);

        // Fields include assignee + column
        $fieldNames = array_column($embed['fields'], 'value');
        $this->assertContains('Alice', $fieldNames);
        $this->assertContains('Backlog', $fieldNames);
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

    public function testMentionEventNotifiesUser()
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

        $captured = null;
        $http->expects($this->once())->method('postJson')
            ->willReturnCallback(function ($url, $payload) use (&$captured) {
                $captured = $payload;
                return '';
            });

        $notification = new DiscordNotification($this->container);
        $notification->notifyUser(
            array('id' => $userId, 'username' => 'carol'),
            \Kanboard\Model\CommentModel::EVENT_USER_MENTION,
            array(
                'task' => array('id' => 7, 'project_id' => $projectId, 'project_name' => 'MP', 'title' => 'Task 7'),
                'comment' => array('comment' => 'Hey @carol look at this'),
            )
        );

        $this->assertStringContainsString('<@111222333444555666>', $captured['content']);
        $this->assertStringContainsString('💬', $captured['embeds'][0]['description']);
    }
}
