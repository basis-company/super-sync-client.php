<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests;

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Project;
use Basis\SuperSyncClient\Task;
use Basis\SuperSyncClient\Workspace;
use Firebase\JWT\JWT;

class IntegrationTest extends \PHPUnit\Framework\TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = new Client(
            accessToken: $this->buildToken(),
            host: getenv('SYNC_HOST') ?: 'http://localhost:3000',
        );
    }

    private function buildToken(): string
    {
        $secret = getenv('SYNC_JWT_SECRET') ?: '85b62616ab5ce2aceac1f9191240207bab896dd8ccd84b45804a26095d19878b';
        return JWT::encode(
            [
                'userId' => 1,
                'email' => 'test@example.com',
                'tokenVersion' => 0,
                'iat' => time(),
                'exp' => time() + 3600,
            ],
            $secret,
            'HS256'
        );
    }

    public function testSnapshotFetch(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();
        $this->assertIsArray($ws->getState());
    }

    public function testCreateAndReadTask(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $task = new Task(id: $ws->generateId(), title: 'ITask-' . $this->client->getClientId());
        $ws->add($task);
        $ws->commit();

        $found = $ws->findOne(Task::class, ['title' => 'ITask-' . $this->client->getClientId()]);
        $this->assertNotNull($found);
        $this->assertSame('ITask-' . $this->client->getClientId(), $found->title);

        // Re-fetch and verify persistence
        $ws2 = new Workspace($this->client);
        $ws2->fetch();
        $found2 = $ws2->findOne(Task::class, ['id' => $task->id]);
        $this->assertNotNull($found2);
    }

    public function testUpdateTask(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $task = new Task(id: $ws->generateId(), title: 'Original');
        $ws->add($task);
        $ws->commit();

        $found = $ws->findOne(Task::class, ['id' => $task->id]);
        $this->assertNotNull($found);
        $found->title = 'Updated';
        $found->isDone = true;
        $ws->commit();

        $ws2 = new Workspace($this->client);
        $ws2->fetch();
        $found2 = $ws2->findOne(Task::class, ['id' => $task->id]);
        $this->assertNotNull($found2);
        $this->assertSame('Updated', $found2->title);
        $this->assertTrue($found2->isDone);
    }

    public function testDeleteTask(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $task = new Task(id: $ws->generateId(), title: 'Gone');
        $ws->add($task);
        $ws->commit();

        $ws->remove(Task::class, $task->id);
        $ws->commit();

        $ws2 = new Workspace($this->client);
        $ws2->fetch();
        $this->assertNull($ws2->findOne(Task::class, ['id' => $task->id]));
    }

    public function testCreateProject(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $proj = new Project(id: $ws->generateId(), title: 'IProj-' . $this->client->getClientId());
        $ws->add($proj);
        $ws->commit();

        $found = $ws->findOne(Project::class, ['title' => 'IProj-' . $this->client->getClientId()]);
        $this->assertNotNull($found);
    }

    public function testIdentityMap(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $task = new Task(id: $ws->generateId(), title: 'Identity');
        $ws->add($task);
        $ws->commit();

        $a = $ws->findOne(Task::class, ['id' => $task->id]);
        $b = $ws->findOne(Task::class, ['id' => $task->id]);
        $this->assertSame($a, $b);
    }

    public function testFindAll(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $ws->add(new Task(id: $ws->generateId(), title: 'FA-' . $this->client->getClientId() . ' A'));
        $ws->add(new Task(id: $ws->generateId(), title: 'FA-' . $this->client->getClientId() . ' B'));
        $ws->commit();

        $all = $ws->findAll(Task::class);
        $this->assertGreaterThanOrEqual(2, count($all));
    }

    public function testServerSeqAdvances(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();
        $seqBefore = $ws->getServerSeq();

        $ws->add(new Task(id: $ws->generateId(), title: 'SeqTest'));
        $ws->commit();

        $this->assertGreaterThan($seqBefore, $ws->getServerSeq());
    }

    public function testTwoWorkspacesShareData(): void
    {
        $ws1 = new Workspace($this->client);
        $ws1->fetch();
        $ws1->add(new Task(id: $ws1->generateId(), title: 'Shared'));
        $ws1->commit();

        $ws2 = new Workspace($this->client);
        $ws2->fetch();
        $found = $ws2->findOne(Task::class, ['title' => 'Shared']);
        $this->assertNotNull($found);
    }
}
