<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient\Tests\E2E;

/**
 * Shared test utilities for E2E tests.
 * Provides helper methods for direct API calls and common operations.
 */
class E2EHelper
{
    private string $host;
    private string $token;

    public function __construct(?string $host = null, ?string $token = null)
    {
        $this->host = $host ?: getenv('SYNC_HOST') ?: 'http://localhost:3000';
        $this->token = $token ?: getenv('SYNC_TOKEN') ?: $this->buildToken();
    }

    /**
     * Generate a JWT token for testing.
     */
    private function buildToken(): string
    {
        $secret = getenv('SYNC_JWT_SECRET') ?: '85b62616ab5ce2aceac1f9191240207bab896dd8ccd84b45804a26095d19878b';
        return \Firebase\JWT\JWT::encode(
            [
                'userId' => 1,
                'email' => 'e2e-test@example.com',
                'tokenVersion' => 0,
                'iat' => time(),
                'exp' => time() + 3600,
            ],
            $secret,
            'HS256'
        );
    }

    /**
     * Create a task on the server via direct API call.
     */
    public function createTaskOnServer(string $title, ?string $projectId = null): string
    {
        $id = 'helper-' . bin2hex(random_bytes(6));
        $clientId = 'helper-' . bin2hex(random_bytes(4));

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

    /**
     * Create a project on the server via direct API call.
     */
    public function createProjectOnServer(string $title, ?string $themeColor = null): string
    {
        $id = 'helper-' . bin2hex(random_bytes(6));
        $clientId = 'helper-' . bin2hex(random_bytes(4));

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

    /**
     * Delete a task on the server via direct API call.
     */
    public function deleteTaskOnServer(string $entityId): void
    {
        $clientId = 'e2e-helper-' . bin2hex(random_bytes(4));

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

    /**
     * Delete a project on the server via direct API call.
     */
    public function deleteProjectOnServer(string $entityId): void
    {
        $clientId = 'e2e-helper-' . bin2hex(random_bytes(4));

        $op = [
            'id' => 'del-' . $entityId,
            'clientId' => $clientId,
            'actionType' => 'DEL_PROJECT',
            'opType' => 'DEL',
            'entityType' => 'PROJECT',
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

    /**
     * Fetch all operations from the server.
     */
    public function fetchAllOps(int $sinceSeq = 0): array
    {
        return $this->apiRequest('GET', "ops?sinceSeq={$sinceSeq}");
    }

    /**
     * Check server health.
     */
    public function checkServerHealth(): array
    {
        return $this->apiRequest('GET', 'status');
    }

    /**
     * Get the sync server URL.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the JWT token.
     */
    public function getToken(): string
    {
        return $this->token;
    }

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
}
