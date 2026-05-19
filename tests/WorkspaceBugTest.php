<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests;

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Workspace::applyOp() bugs — payload may not be an array.
 *
 * The server sometimes returns payload as a string (JSON string instead of object),
 * which breaks array_merge() in applyOp.
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
     * CRT with string payload should not crash (just ignore or convert to array).
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

        // Should not throw TypeError, data should be preserved
        $this->assertArrayHasKey('TASK', $ws->getState());
        $this->assertArrayHasKey('task-1', $ws->getState()['TASK']);
    }

    /**
     * UPD with string payload should not crash.
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
     * UPD with missing payload (no payload key) should not crash.
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
                            // payload is missing
                        ],
                    ],
                ],
                'latestSeq' => 2,
            ]);

        $ws = new Workspace($this->clientMock);
        $ws->fetch();

        // Without payload, initial should be preserved
        $this->assertArrayHasKey('proj-1', $ws->getState()['PROJECT']);
    }

    /**
     * Normal array payload should continue to work (regression test).
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
     * Mixed scenario: CRT(array) → UPD(string) → UPD(array).
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
