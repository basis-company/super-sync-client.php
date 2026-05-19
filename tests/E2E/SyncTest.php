<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests\E2E;

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Task;
use Basis\SuperSyncClient\Workspace;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Panther\ProcessHelper;

/**
 * E2E: browser and PHP client sync via the same server.
 *
 * Uses Panther (headless Chrome) to issue sync ops via fetch(),
 * then verifies the PHP client sees the data — and vice versa.
 *
 * Requirements:
 * - sync-server running on SYNC_HOST (default: http://localhost:3000)
 * - ChromeDriver on port 9515 (or set PANTHER_CHROME_DRIVER_BINARY)
 */
class SyncTest extends PantherTestCase
{
    private string $host;
    private string $token;
    private Client $client;
    private $panther;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = getenv('SYNC_HOST') ?: 'http://localhost:3000';
        $this->token = $this->buildToken();
        $this->client = new Client($this->token);

        // Start headless browser
        ProcessHelper::createChromeProcess();
        $this->panther = static::createPantherClient();
    }

    protected function tearDown(): void
    {
        $this->panther?->quit();
        parent::tearDown();
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

    private function driver(): object
    {
        return $this->panther->getWebDriver();
    }

    private function js(string $script, ...$args): mixed
    {
        return $this->driver()->executeScript($script, ...$args);
    }

    private function createTaskInBrowser(string $title): string
    {
        $id = 'browser-' . bin2hex(random_bytes(6));
        $clientId = 'panther-' . bin2hex(random_bytes(4));

        $this->js(
            <<<'JS'
            const host = arguments[0];
            const token = arguments[1];
            const id = arguments[2];
            const title = arguments[3];
            const clientId = arguments[4];

            const op = {
                id,
                clientId,
                actionType: 'CRT_TASK',
                opType: 'CRT',
                entityType: 'TASK',
                entityId: id,
                payload: { id, title, isDone: false, projectId: null },
                vectorClock: {},
                timestamp: Date.now(),
                schemaVersion: 1,
            };

            return fetch(host + '/api/sync/ops', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
                body: JSON.stringify({ ops: [op], clientId }),
            }).then(r => r.json());
            JS,
            $this->host,
            $this->token,
            $id,
            $title,
            $clientId
        );

        return $id;
    }

    private function deleteTaskInBrowser(string $entityId): void
    {
        $clientId = 'panther-del-' . bin2hex(random_bytes(4));
        $this->js(
            <<<'JS'
            const host = arguments[0];
            const token = arguments[1];
            const entityId = arguments[2];
            const clientId = arguments[3];

            const op = {
                id: 'del-' + entityId,
                clientId,
                actionType: 'DEL_TASK',
                opType: 'DEL',
                entityType: 'TASK',
                entityId,
                payload: null,
                vectorClock: {},
                timestamp: Date.now(),
                schemaVersion: 1,
            };

            return fetch(host + '/api/sync/ops', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
                body: JSON.stringify({ ops: [op], clientId }),
            }).then(r => r.json());
            JS,
            $this->host,
            $this->token,
            $entityId,
            $clientId
        );
    }

    private function fetchEntitiesFromBrowser(): array
    {
        $result = $this->js(
            <<<'JS'
            const host = arguments[0];
            const token = arguments[1];

            return fetch(host + '/api/sync/ops?sinceSeq=0', {
                headers: { 'Authorization': 'Bearer ' + token },
            }).then(r => r.json()).then(json => {
                const entities = [];
                for (const entry of (json.ops || [])) {
                    const op = entry.op;
                    entities.push({
                        id: op.entityId,
                        entityType: op.entityType,
                        title: op.payload?.title,
                        action: op.actionType,
                    });
                }
                return entities;
            });
            JS,
            $this->host,
            $this->token
        );

        return $result ?? [];
    }

    public function testPhpCreatesAndBrowserReads(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();

        $task = new Task(id: $ws->generateId(), title: 'php-created-task');
        $ws->add($task);
        $ws->commit();

        $browserEntities = $this->fetchEntitiesFromBrowser();
        $found = false;
        foreach ($browserEntities as $e) {
            if ($e['id'] === $task->id && $e['entityType'] === 'TASK') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Browser should see task created by PHP client');
    }

    public function testBrowserCreatesAndPhpReads(): void
    {
        $taskId = $this->createTaskInBrowser('browser-created-task');

        $ws = new Workspace($this->client);
        $ws->fetch();

        $found = $ws->findOne(Task::class, ['id' => $taskId]);
        $this->assertNotNull($found, 'PHP client should see task created by browser');
        $this->assertSame('browser-created-task', $found->title);
    }

    public function testBidirectionalSyncRoundTrip(): void
    {
        $ws = new Workspace($this->client);
        $ws->fetch();
        $task = new Task(id: $ws->generateId(), title: 'round-trip');
        $ws->add($task);
        $ws->commit();

        $browserEntities = $this->fetchEntitiesFromBrowser();
        $found = false;
        foreach ($browserEntities as $e) {
            if ($e['id'] === $task->id && $e['entityType'] === 'TASK') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $this->deleteTaskInBrowser($task->id);

        $ws2 = new Workspace($this->client);
        $ws2->fetch();
        $this->assertNull($ws2->findOne(Task::class, ['id' => $task->id]));
    }
}
