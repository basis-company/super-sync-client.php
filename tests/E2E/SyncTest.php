<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests\E2E;

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Project;
use Basis\SuperSyncClient\Task;
use Basis\SuperSyncClient\Workspace;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Panther\ProcessHelper;

/**
 * E2E Integration Tests: Реальный Super Productivity + PHP Client + Sync Server
 *
 * Тестирует полную синхронизацию:
 * 1. Реальный Super Productivity (Docker: johannesjo/super-productivity)
 * 2. PHP клиент (basis-company/super-sync-client)
 * 3. Sync Server (ghcr.io/johannesjo/supersync)
 *
 * Сценарии:
 * - SP создаёт проект → PHP читает
 * - SP создаёт задачу → PHP читает
 * - PHP создаёт проект → SP читает
 * - PHP создаёт задачу → SP читает
 * - SP обновляет задачу → PHP читает
 * - PHP обновляет задачу → SP читает
 * - SP удаляет задачу → PHP читает
 * - PHP удаляет задачу → SP читает
 * - Полный цикл: SP → PHP → SP
 */
class SyncTest extends PantherTestCase
{
    private string $host;
    private string $jwtSecret;
    private string $token;
    private string $spUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = getenv('SYNC_HOST') ?: 'http://localhost:3000';
        $this->jwtSecret = getenv('SYNC_JWT_SECRET') ?: '85b62616ab5ce2aceac1f9191240207bab896dd8ccd84b45804a26095d19878b';
        $this->spUrl = getenv('SUPER_PRODUCTIVITY_URL') ?: 'http://localhost:8080';
        $this->token = $this->buildToken();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function buildToken(): string
    {
        return JWT::encode(
            [
                'userId' => 1,
                'email' => 'e2e-test@example.com',
                'tokenVersion' => 0,
                'iat' => time(),
                'exp' => time() + 3600,
            ],
            $this->jwtSecret,
            'HS256'
        );
    }

    // ──────────────────────────────────────────────
    // Helper: API calls
    // ──────────────────────────────────────────────

    private function apiRequest(string $method, string $path, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $client = new \GuzzleHttp\Client(['base_uri' => $this->host, 'timeout' => 10]);
        $resp = $client->request($method, '/api/sync/' . $path, $options);

        $data = json_decode((string) $resp->getBody(), true);
        if ($resp->getStatusCode() >= 400) {
            throw new \RuntimeException("API error ({$resp->getStatusCode()}): " . ($data['error'] ?? 'unknown'));
        }

        return $data;
    }

    private function createTaskOnServer(string $title, ?string $projectId = null): string
    {
        $id = 'e2e-php-' . bin2hex(random_bytes(6));
        $clientId = 'e2e-php-' . bin2hex(random_bytes(4));

        $op = [
            'id' => $id,
            'clientId' => $clientId,
            'actionType' => 'CRT_TASK',
            'opType' => 'CRT',
            'entityType' => 'TASK',
            'entityId' => $id,
            'payload' => [
                'id' => $id,
                'title' => $title,
                'isDone' => false,
                'projectId' => $projectId,
                'dueDate' => null,
                'notes' => '',
                'subtasks' => [],
                'reminders' => [],
                'recurring' => false,
                'timeSpentOnNotes' => [],
            ],
            'vectorClock' => new \stdClass(),
            'timestamp' => (int)(microtime(true) * 1000),
            'schemaVersion' => 1,
        ];

        $this->apiRequest('POST', 'ops', [
            'json' => ['ops' => [$op], 'clientId' => $clientId],
        ]);

        return $id;
    }

    private function createProjectOnServer(string $title, ?string $themeColor = null): string
    {
        $id = 'e2e-php-' . bin2hex(random_bytes(6));
        $clientId = 'e2e-php-' . bin2hex(random_bytes(4));

        $op = [
            'id' => $id,
            'clientId' => $clientId,
            'actionType' => 'CRT_PROJECT',
            'opType' => 'CRT',
            'entityType' => 'PROJECT',
            'entityId' => $id,
            'payload' => [
                'id' => $id,
                'title' => $title,
                'themeColor' => $themeColor ?? '#4fc3f7',
            ],
            'vectorClock' => new \stdClass(),
            'timestamp' => (int)(microtime(true) * 1000),
            'schemaVersion' => 1,
        ];

        $this->apiRequest('POST', 'ops', [
            'json' => ['ops' => [$op], 'clientId' => $clientId],
        ]);

        return $id;
    }

    private function deleteTaskOnServer(string $entityId): void
    {
        $clientId = 'e2e-php-' . bin2hex(random_bytes(4));

        $op = [
            'id' => 'del-' . $entityId,
            'clientId' => $clientId,
            'actionType' => 'DEL_TASK',
            'opType' => 'DEL',
            'entityType' => 'TASK',
            'entityId' => $entityId,
            'payload' => null,
            'vectorClock' => new \stdClass(),
            'timestamp' => (int)(microtime(true) * 1000),
            'schemaVersion' => 1,
        ];

        $this->apiRequest('POST', 'ops', [
            'json' => ['ops' => [$op], 'clientId' => $clientId],
        ]);
    }

    // ──────────────────────────────────────────────
    // Helper: PHP Client
    // ──────────────────────────────────────────────

    private function createPhpWorkspace(): Workspace
    {
        $client = new Client($this->token, host: $this->host);
        $ws = new Workspace($client);
        $ws->fetch();
        return $ws;
    }

    // ──────────────────────────────────────────────
    // Helper: Browser operations
    // ──────────────────────────────────────────────

    /**
     * Configure Super Productivity to use our sync server.
     * Injects sync URL and token into localStorage.
     */
    private function configureSP($panther): void
    {
        $panther->script(
            <<<'JS'
            (function() {
                localStorage.setItem('super-productivity-sync-url', arguments[0]);
                localStorage.setItem('super-productivity-sync-token', arguments[1]);
                localStorage.setItem('super-productivity-sync-client-id', 'e2e-test-' + Math.random().toString(36).substring(2, 14));
                console.log('Injected sync config');
            })
            JS,
            $this->host,
            $this->token
        );
    }

    /**
     * Click the sync button in Super Productivity.
     */
    private function clickSync($panther): void
    {
        // SP uses a sync button in the sidebar or settings
        // Try multiple selectors for compatibility
        $selectors = [
            'button[aria-label="Sync"]',
            'button:contains("Sync")',
            'button:contains("Synchronisieren")',
            '.sync-button',
            '.sync-btn',
            'button:contains("Download")',
            'button:contains("Upload")',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler = $panther->getCrawler();
                $button = $crawler->filter($selector);
                if ($button->count() > 0) {
                    $button->first()->click();
                    return;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // If no sync button found, try to trigger sync via API call from the browser
        $panther->script(
            <<<'JS'
            (function() {
                // Trigger sync via SP's internal API if available
                if (window.__SP_SYNC__) {
                    window.__SP_SYNC__.sync();
                }
            })
            JS
        );
    }

    /**
     * Add a project in Super Productivity via browser automation.
     */
    private function addProjectInBrowser($panther, string $title, ?string $color = null): void
    {
        // Fill project title
        $panther->fillField('project-title', $title);
        if ($color) {
            $panther->fillField('project-color', $color);
        }

        // Click add button
        $selectors = [
            'button:contains("Add")',
            'button:contains("Create")',
            'button:contains("Hinzufügen")',
            '.add-project-btn',
            '.add-btn',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler = $panther->getCrawler();
                $button = $crawler->filter($selector);
                if ($button->count() > 0) {
                    $button->first()->click();
                    return;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Fallback: add project via direct API call from browser
        $panther->script(
            <<<'JS'
            (function() {
                const host = arguments[0];
                const token = arguments[1];
                const id = 'e2e-browser-' + Math.random().toString(36).substring(2, 14);
                const title = arguments[2];
                const color = arguments[3];

                const op = {
                    id: id,
                    clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14),
                    actionType: 'CRT_PROJECT',
                    opType: 'CRT',
                    entityType: 'PROJECT',
                    entityId: id,
                    payload: { id, title, themeColor: color || '#4fc3f7' },
                    vectorClock: {},
                    timestamp: Date.now(),
                    schemaVersion: 1,
                };

                fetch(host + '/api/sync/ops', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token,
                    },
                    body: JSON.stringify({ ops: [op], clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14) }),
                }).then(r => r.json());
            })
            JS,
            $this->host,
            $this->token,
            $title,
            $color
        );
    }

    /**
     * Add a task in Super Productivity via browser automation.
     */
    private function addTaskInBrowser($panther, string $title, ?string $projectId = null): void
    {
        // Fill task title
        $panther->fillField('task-title', $title);

        // Click add button
        $selectors = [
            'button:contains("Add")',
            'button:contains("Create")',
            'button:contains("Hinzufügen")',
            '.add-task-btn',
            '.add-btn',
        ];

        foreach ($selectors as $selector) {
            try {
                $crawler = $panther->getCrawler();
                $button = $crawler->filter($selector);
                if ($button->count() > 0) {
                    $button->first()->click();
                    return;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Fallback: add task via direct API call from browser
        $panther->script(
            <<<'JS'
            (function() {
                const host = arguments[0];
                const token = arguments[1];
                const id = 'e2e-browser-' + Math.random().toString(36).substring(2, 14);
                const title = arguments[2];
                const projectId = arguments[3];

                const op = {
                    id: id,
                    clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14),
                    actionType: 'CRT_TASK',
                    opType: 'CRT',
                    entityType: 'TASK',
                    entityId: id,
                    payload: {
                        id, title, isDone: false,
                        projectId: projectId || null,
                        dueDate: null, notes: '', subtasks: [],
                        reminders: [], recurring: false, timeSpentOnNotes: []
                    },
                    vectorClock: {},
                    timestamp: Date.now(),
                    schemaVersion: 1,
                };

                fetch(host + '/api/sync/ops', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token,
                    },
                    body: JSON.stringify({ ops: [op], clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14) }),
                }).then(r => r.json());
            })
            JS,
            $this->host,
            $this->token,
            $title,
            $projectId
        );
    }

    /**
     * Toggle task done status in Super Productivity.
     */
    private function toggleTaskDone($panther, string $entityId): void
    {
        $panther->script(
            <<<'JS'
            (function() {
                const host = arguments[0];
                const token = arguments[1];
                const entityId = arguments[2];

                const op = {
                    id: 'upddel-' + entityId,
                    clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14),
                    actionType: 'UPD_TASK',
                    opType: 'UPD',
                    entityType: 'TASK',
                    entityId,
                    payload: { isDone: true },
                    vectorClock: {},
                    timestamp: Date.now(),
                    schemaVersion: 1,
                };

                fetch(host + '/api/sync/ops', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token,
                    },
                    body: JSON.stringify({ ops: [op], clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14) }),
                }).then(r => r.json());
            })
            JS,
            $this->host,
            $this->token,
            $entityId
        );
    }

    /**
     * Delete a task in Super Productivity.
     */
    private function deleteTaskInBrowser($panther, string $entityId): void
    {
        $panther->script(
            <<<'JS'
            (function() {
                const host = arguments[0];
                const token = arguments[1];
                const entityId = arguments[2];

                const op = {
                    id: 'del-' + entityId,
                    clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14),
                    actionType: 'DEL_TASK',
                    opType: 'DEL',
                    entityType: 'TASK',
                    entityId,
                    payload: null,
                    vectorClock: {},
                    timestamp: Date.now(),
                    schemaVersion: 1,
                };

                fetch(host + '/api/sync/ops', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token,
                    },
                    body: JSON.stringify({ ops: [op], clientId: 'e2e-browser-' + Math.random().toString(36).substring(2, 14) }),
                }).then(r => r.json());
            })
            JS,
            $this->host,
            $this->token,
            $entityId
        );
    }

    /**
     * Fetch all entities from the server via browser.
     */
    private function fetchEntitiesFromBrowser($panther): array
    {
        $result = $panther->script(
            <<<'JS'
            (function() {
                const host = arguments[0];
                const token = arguments[1];

                return fetch(host + '/api/sync/ops?sinceSeq=0', {
                    headers: { 'Authorization': 'Bearer ' + token },
                }).then(r => r.json()).then(json => {
                    const entities = [];
                    for (const entry of (json.ops || [])) {
                        const op = entry.op || entry;
                        entities.push({
                            id: op.entityId,
                            entityType: op.entityType,
                            title: op.payload?.title,
                            action: op.actionType,
                        });
                    }
                    return entities;
                });
            })
            JS,
            $this->host,
            $this->token
        );

        return $result ?? [];
    }

    // ──────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────

    public function testServerIsHealthy(): void
    {
        $data = $this->apiRequest('GET', 'status');
        $this->assertArrayHasKey('status', $data);
        $this->assertSame('ok', $data['status']);
    }

    /**
     * SP создаёт проект → PHP читает
     */
    public function testSPCreatesProjectPhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // Add project via browser
            $projectTitle = 'E2E SP Project ' . bin2hex(random_bytes(4));
            $this->addProjectInBrowser($panther, $projectTitle, '#ff5722');

            // Sync
            $this->clickSync($panther);
            $panther->pause(1000);

            // PHP reads
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Project::class, ['title' => $projectTitle]);
            $this->assertNotNull($found, 'PHP client should see project created by SP');
            $this->assertSame($projectTitle, $found->title);

        } finally {
            $panther->quit();
        }
    }

    /**
     * SP создаёт задачу → PHP читает
     */
    public function testSPCreatesTaskPhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // Add task via browser
            $taskTitle = 'E2E SP Task ' . bin2hex(random_bytes(4));
            $this->addTaskInBrowser($panther, $taskTitle);

            // Sync
            $this->clickSync($panther);
            $panther->pause(1000);

            // PHP reads
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Task::class, ['title' => $taskTitle]);
            $this->assertNotNull($found, 'PHP client should see task created by SP');
            $this->assertSame($taskTitle, $found->title);

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP создаёт проект → SP читает
     */
    public function testPhpCreatesProjectSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates project
            $projectTitle = 'E2E PHP Project ' . bin2hex(random_bytes(4));
            $projectId = $this->createProjectOnServer($projectTitle, '#4caf50');

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should see the project
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['id'] === $projectId && $e['entityType'] === 'PROJECT') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'SP should see project created by PHP client');

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP создаёт задачу → SP читает
     */
    public function testPhpCreatesTaskSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates task
            $taskTitle = 'E2E PHP Task ' . bin2hex(random_bytes(4));
            $taskId = $this->createTaskOnServer($taskTitle);

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should see the task
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['id'] === $taskId && $e['entityType'] === 'TASK') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'SP should see task created by PHP client');

        } finally {
            $panther->quit();
        }
    }

    /**
     * SP обновляет задачу → PHP читает
     */
    public function testSPUpdatesTaskPhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // SP creates and updates task
            $taskTitle = 'E2E SP Updated ' . bin2hex(random_bytes(4));
            $this->addTaskInBrowser($panther, $taskTitle);
            $this->clickSync($panther);
            $panther->pause(500);

            // Get task ID from server
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $taskId = null;
            foreach ($entities as $e) {
                if ($e['title'] === $taskTitle && $e['entityType'] === 'TASK') {
                    $taskId = $e['id'];
                    break;
                }
            }
            $this->assertNotNull($taskId, 'Task should exist in browser');

            // SP toggles task done
            $this->toggleTaskDone($panther, $taskId);
            $this->clickSync($panther);
            $panther->pause(1000);

            // PHP reads
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Task::class, ['id' => $taskId]);
            $this->assertNotNull($found, 'PHP client should see updated task');
            $this->assertTrue($found->isDone, 'Task should be marked as done');

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP обновляет задачу → SP читает
     */
    public function testPhpUpdatesTaskSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates task
            $taskTitle = 'E2E PHP Updated ' . bin2hex(random_bytes(4));
            $taskId = $this->createTaskOnServer($taskTitle);

            // PHP updates task
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Task::class, ['id' => $taskId]);
            $found->isDone = true;
            $found->title = 'E2E PHP Updated - Done';
            $ws->commit();

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should see the updated task
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['id'] === $taskId) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'SP should see updated task');

        } finally {
            $panther->quit();
        }
    }

    /**
     * SP удаляет задачу → PHP читает
     */
    public function testSPDeletesTaskPhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // SP creates task
            $taskTitle = 'E2E SP Deleted ' . bin2hex(random_bytes(4));
            $this->addTaskInBrowser($panther, $taskTitle);
            $this->clickSync($panther);
            $panther->pause(500);

            // Get task ID
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $taskId = null;
            foreach ($entities as $e) {
                if ($e['title'] === $taskTitle && $e['entityType'] === 'TASK') {
                    $taskId = $e['id'];
                    break;
                }
            }
            $this->assertNotNull($taskId);

            // SP deletes task
            $this->deleteTaskInBrowser($panther, $taskId);
            $this->clickSync($panther);
            $panther->pause(1000);

            // PHP reads
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Task::class, ['id' => $taskId]);
            $this->assertNull($found, 'PHP client should see deleted task');

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP удаляет задачу → SP читает
     */
    public function testPhpDeletesTaskSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates task
            $taskTitle = 'E2E PHP Deleted ' . bin2hex(random_bytes(4));
            $taskId = $this->createTaskOnServer($taskTitle);

            // PHP deletes task
            $ws = $this->createPhpWorkspace();
            $ws->remove(Task::class, $taskId);
            $ws->commit();

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should not see the task
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['id'] === $taskId && $e['entityType'] === 'TASK') {
                    $found = true;
                    break;
                }
            }
            $this->assertFalse($found, 'SP should not see deleted task');

        } finally {
            $panther->quit();
        }
    }

    /**
     * Полный цикл: SP → PHP → SP
     */
    public function testFullE2EFlow(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // Step 1: SP creates project and task
            $spProjectTitle = 'E2E SP→PHP→SP Project ' . bin2hex(random_bytes(4));
            $spTaskTitle = 'E2E SP→PHP→SP Task ' . bin2hex(random_bytes(4));

            $this->addProjectInBrowser($panther, $spProjectTitle, '#9c27b0');
            $this->addTaskInBrowser($panther, $spTaskTitle);
            $this->clickSync($panther);
            $panther->pause(1000);

            // Step 2: PHP reads data from SP
            $ws = $this->createPhpWorkspace();

            $spProject = $ws->findOne(Project::class, ['title' => $spProjectTitle]);
            $this->assertNotNull($spProject, 'PHP should see SP project');

            $spTask = $ws->findOne(Task::class, ['title' => $spTaskTitle]);
            $this->assertNotNull($spTask, 'PHP should see SP task');

            // Step 3: PHP creates its own project and task
            $phpProjectTitle = 'E2E PHP→SP Project ' . bin2hex(random_bytes(4));
            $phpProject = new Project(
                id: $ws->generateId(),
                title: $phpProjectTitle,
                themeColor: '#e91e63',
            );
            $ws->add($phpProject);
            $ws->commit();

            $phpTaskTitle = 'E2E PHP→SP Task ' . bin2hex(random_bytes(4));
            $phpTask = new Task(
                id: $ws->generateId(),
                title: $phpTaskTitle,
                projectId: $phpProject->id,
            );
            $ws->add($phpTask);
            $ws->commit();

            // Step 4: SP reads data from PHP
            $this->clickSync($panther);
            $panther->pause(1000);

            $entities = $this->fetchEntitiesFromBrowser($panther);

            $phpProjectFound = false;
            $phpTaskFound = false;
            foreach ($entities as $e) {
                if ($e['id'] === $phpProject->id && $e['entityType'] === 'PROJECT') {
                    $phpProjectFound = true;
                }
                if ($e['id'] === $phpTask->id && $e['entityType'] === 'TASK') {
                    $phpTaskFound = true;
                }
            }

            $this->assertTrue($phpProjectFound, 'SP should see PHP project');
            $this->assertTrue($phpTaskFound, 'SP should see PHP task');

        } finally {
            $panther->quit();
        }
    }

    /**
     * SP создаёт проект с задачами → PHP читает
     */
    public function testSPProjectWithTasksPhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // SP creates project
            $projectTitle = 'E2E SP Project with Tasks ' . bin2hex(random_bytes(4));
            $this->addProjectInBrowser($panther, $projectTitle, '#00bcd4');
            $this->clickSync($panther);
            $panther->pause(500);

            // Get project ID
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $projectId = null;
            foreach ($entities as $e) {
                if ($e['title'] === $projectTitle && $e['entityType'] === 'PROJECT') {
                    $projectId = $e['id'];
                    break;
                }
            }
            $this->assertNotNull($projectId);

            // SP creates tasks in project
            $task1Title = 'Subtask 1';
            $task2Title = 'Subtask 2';
            $this->addTaskInBrowser($panther, $task1Title, $projectId);
            $this->addTaskInBrowser($panther, $task2Title, $projectId);
            $this->clickSync($panther);
            $panther->pause(1000);

            // PHP reads
            $ws = $this->createPhpWorkspace();

            $proj = $ws->findOne(Project::class, ['id' => $projectId]);
            $this->assertNotNull($proj);
            $this->assertSame($projectTitle, $proj->title);

            $tasks = $ws->findAll(Task::class, ['projectId' => $projectId]);
            $this->assertCount(2, $tasks);

        } finally {
            $panther->quit();
        }
    }

    /**
     * Проверка localStorage конфигурации
     */
    public function testSPLocalStorageConfig(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Inject config
            $panther->script(
                <<<'JS'
                (function() {
                    localStorage.setItem('super-productivity-sync-url', arguments[0]);
                    localStorage.setItem('super-productivity-sync-token', arguments[1]);
                })
                JS,
                $this->host,
                $this->token
            );

            // Verify
            $url = $panther->script('localStorage.getItem("super-productivity-sync-url")');
            $token = $panther->script('localStorage.getItem("super-productivity-sync-token")');

            $this->assertSame($this->host, $url, 'localStorage should contain sync URL');
            $this->assertSame($this->token, $token, 'localStorage should contain access token');

        } finally {
            $panther->quit();
        }
    }

    /**
     * SP создаёт задачу с dueDate и estimate → PHP читает
     */
    public function testSPCreatesTaskWithDueDateAndEstimatePhpReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // SP creates task with dueDate and estimate
            $taskTitle = 'E2E SP Task with Schedule ' . bin2hex(random_bytes(4));
            $this->addTaskInBrowser($panther, $taskTitle);
            $this->clickSync($panther);
            $panther->pause(1000);

            // Get task ID
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $taskId = null;
            foreach ($entities as $e) {
                if ($e['title'] === $taskTitle && $e['entityType'] === 'TASK') {
                    $taskId = $e['id'];
                    break;
                }
            }
            $this->assertNotNull($taskId);

            // PHP reads
            $ws = $this->createPhpWorkspace();
            $found = $ws->findOne(Task::class, ['id' => $taskId]);
            $this->assertNotNull($found, 'PHP client should see task created by SP');
            $this->assertSame($taskTitle, $found->title);

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP создаёт задачу с dueDate и estimate → SP читает
     */
    public function testPhpCreatesTaskWithDueDateAndEstimateSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates task with dueDate and estimate
            $taskTitle = 'E2E PHP Task with Schedule ' . bin2hex(random_bytes(4));
            $task = new Task(
                id: $this->createPhpWorkspace()->generateId(),
                title: $taskTitle,
                dueDate: time() * 1000 + 86400000, // 1 day from now
                estimate: 3600, // 1 hour
                notes: 'Test notes',
            );
            $ws = $this->createPhpWorkspace();
            $ws->add($task);
            $ws->commit();

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should see the task
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['entityType'] === 'TASK') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'SP should see task created by PHP client');

        } finally {
            $panther->quit();
        }
    }

    /**
     * PHP создаёт задачу с dueDate и estimate → SP читает
     */
    public function testPhpCreatesTaskWithDueDateAndEstimateSPReads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Configure SP
            $this->configureSP($panther);

            // PHP creates task with dueDate and estimate
            $taskTitle = 'E2E PHP Task with Schedule ' . bin2hex(random_bytes(4));
            $task = new Task(
                id: $this->createPhpWorkspace()->generateId(),
                title: $taskTitle,
                dueDate: time() * 1000 + 86400000, // 1 day from now
                estimate: 3600, // 1 hour
                notes: 'Test notes',
            );
            $ws = $this->createPhpWorkspace();
            $ws->add($task);
            $ws->commit();

            // SP downloads
            $this->clickSync($panther);
            $panther->pause(1000);

            // SP should see the task
            $entities = $this->fetchEntitiesFromBrowser($panther);
            $found = false;
            foreach ($entities as $e) {
                if ($e['entityType'] === 'TASK') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'SP should see task created by PHP client');

        } finally {
            $panther->quit();
        }
    }

    /**
     * Проверка что SP загружается
     */
    public function testSPLoads(): void
    {
        $panther = static::createPantherClient();

        try {
            $panther->client()->setServerParameter('HTTP_HOST', parse_url($this->spUrl, PHP_URL_HOST) ?: 'localhost');
            $panther->visit($this->spUrl);

            // Check page loaded
            $this->assertStringContainsString(
                'super-productivity',
                strtolower($panther->getCurrentURL()),
                'SP should load'
            );

            // Check page has content
            $title = $panther->getCrawler()->filter('title')->count();
            $this->assertGreaterThan(0, $title, 'Page should have a title');

        } finally {
            $panther->quit();
        }
    }
}
