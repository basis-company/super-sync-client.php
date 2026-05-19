<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests;

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Регрессионные тесты на баги Workspace::applyOp() — payload может прийти не массивом.
 *
 * Сервер иногда возвращает payload как строку (JSON string вместо объекта),
 * что ломает array_merge() в applyOp.
 */
class WorkspaceBugTest extends TestCase
{
    /**
     * @var Client&\PHPUnit\Framework\MockObject\MockObject
     */
    private Client $clientMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(Client::class);
        $this->clientMock->method('getClientId')->willReturn('test-client');
    }

    /**
     * CRT с payload-строкой должен не падать (просто игнорить или приводить к массиву).
     */
    public function testCrtWithStringPayloadDoesNotExplode(): void
    {
        $this->clientMock->method('downloadOps')
            ->willReturn([
                'ops' => [
                    [
                        'op' => [
                            'opType' => 'CRT',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => '{"id":"task-1","title":"from string"}',
                        ],
                    ],
                ],
                'latestSeq' => 1,
            ]);

        $ws = new Workspace($this->clientMock);
        $ws->fetch();

        // Не должно выбросить TypeError, данные должны сохраниться
        $this->assertArrayHasKey('TASK', $ws->getState());
        $this->assertArrayHasKey('task-1', $ws->getState()['TASK']);
    }

    /**
     * UPD с payload-строкой должен не падать.
     */
    public function testUpdateWithStringPayloadDoesNotExplode(): void
    {
        $this->clientMock->method('downloadOps')
            ->willReturn([
                'ops' => [
                    [
                        'op' => [
                            'opType' => 'CRT',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => ['id' => 'task-1', 'title' => 'initial'],
                        ],
                    ],
                    [
                        'op' => [
                            'opType' => 'UPD',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => '{"title":"updated"}',
                        ],
                    ],
                ],
                'latestSeq' => 2,
            ]);

        $ws = new Workspace($this->clientMock);
        $ws->fetch();

        $state = $ws->getState()['TASK']['task-1'] ?? null;
        $this->assertNotNull($state);
        $this->assertSame('updated', $state['title']);
    }

    /**
     * UPD с пустым payload (нет payload-ключа) должен не падать.
     */
    public function testUpdateWithMissingPayloadDoesNotExplode(): void
    {
        $this->clientMock->method('downloadOps')
            ->willReturn([
                'ops' => [
                    [
                        'op' => [
                            'opType' => 'CRT',
                            'entityType' => 'PROJECT',
                            'entityId' => 'proj-1',
                            'payload' => ['id' => 'proj-1', 'title' => 'initial'],
                        ],
                    ],
                    [
                        'op' => [
                            'opType' => 'UPD',
                            'entityType' => 'PROJECT',
                            'entityId' => 'proj-1',
                            // payload отсутствует
                        ],
                    ],
                ],
                'latestSeq' => 2,
            ]);

        $ws = new Workspace($this->clientMock);
        $ws->fetch();

        // Без payload должен сохраниться initial
        $this->assertArrayHasKey('proj-1', $ws->getState()['PROJECT']);
    }

    /**
     * Нормальный массивный payload должен продолжать работать (регрессия).
     */
    public function testArrayPayloadContinuesToWork(): void
    {
        $this->clientMock->method('downloadOps')
            ->willReturn([
                'ops' => [
                    [
                        'op' => [
                            'opType' => 'CRT',
                            'entityType' => 'PROJECT',
                            'entityId' => 'proj-1',
                            'payload' => ['id' => 'proj-1', 'title' => 'array payload'],
                        ],
                    ],
                ],
                'latestSeq' => 1,
            ]);

        $ws = new Workspace($this->clientMock);
        $ws->fetch();

        $this->assertArrayHasKey('PROJECT', $ws->getState());
        $this->assertArrayHasKey('proj-1', $ws->getState()['PROJECT']);
        $this->assertSame('array payload', $ws->getState()['PROJECT']['proj-1']['title']);
    }

    /**
     * Смешанный сценарий: CRT(массив) → UPD(строка) → UPD(массив).
     */
    public function testMixedPayloadTypesInSequence(): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('downloadOps')
            ->willReturn([
                'ops' => [
                    [
                        'op' => [
                            'opType' => 'CRT',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => ['id' => 'task-1', 'title' => 'a', 'isDone' => false],
                        ],
                    ],
                    [
                        'op' => [
                            'opType' => 'UPD',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => '{"title":"b"}',
                        ],
                    ],
                    [
                        'op' => [
                            'opType' => 'UPD',
                            'entityType' => 'TASK',
                            'entityId' => 'task-1',
                            'payload' => ['title' => 'c', 'isDone' => true],
                        ],
                    ],
                ],
                'latestSeq' => 3,
            ]);

        $ws = new Workspace($clientMock);
        $ws->fetch();

        $task = $ws->getState()['TASK']['task-1'];
        $this->assertNotNull($task);
        $this->assertSame('c', $task['title']);
        $this->assertTrue($task['isDone']);
    }
}
