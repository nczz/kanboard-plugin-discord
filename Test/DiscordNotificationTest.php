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
}
