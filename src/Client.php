<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;

class Client
{
    private ?Guzzle $http = null;
    private string $resolvedClientId;

    /**
     * @param string $accessToken JWT access token
     * @param string|null $encryptionKey E2EE encryptKey from Sync Settings (not required for unencrypted servers)
     * @param string|null $clientId Unique client ID (auto-generated if null)
     * @param string $host Sync server URL
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly ?string $encryptionKey = null,
        private readonly ?string $clientId = null,
        private readonly string $host = 'https://sync.super-productivity.com/',
    ) {
        $this->resolvedClientId = $clientId ?? $this->generateId();
    }

    private function getHttpClient(): Guzzle
    {
        return $this->http ??= new Guzzle([
            'base_uri' => rtrim($this->host, '/') . '/api/sync/',
            'timeout' => 30.0,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function generateId(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * Upload operations (incremental sync).
     */
    public function uploadOps(array $operations): array
    {
        $resp = $this->getHttpClient()->request('POST', 'ops', [
            'json' => [
                'ops' => $operations,
                'clientId' => $this->resolvedClientId,
            ],
        ]);
        $this->assertSuccess($resp, 'uploadOps');
        return $this->decode($resp);
    }

    /**
     * Download operations from server.
     */
    public function downloadOps(int $sinceSeq, ?string $excludeClient = null): array
    {
        $params = ['sinceSeq' => $sinceSeq];
        if ($excludeClient !== null) {
            $params['excludeClient'] = $excludeClient;
        }
        $resp = $this->getHttpClient()->request('GET', 'ops', ['query' => $params]);
        $this->assertSuccess($resp, 'downloadOps');
        return $this->decode($resp);
    }

    /**
     * Get snapshot. If encryptionKey is set, automatically decrypts state.
     *
     * @return array with 'state' key (encrypted string or array, 'serverSeq', etc.)
     */
    public function getSnapshot(): array
    {
        $resp = $this->getHttpClient()->request('GET', 'snapshot', []);
        $this->assertSuccess($resp, 'getSnapshot');
        $data = $this->decode($resp);

        // Decrypt state if encryption key is available
        $state = $data['state'] ?? null;
        if ($state !== null && $this->encryptionKey !== null) {
            $data['state'] = $this->decryptState($state);
        }

        return $data;
    }

    /**
     * Upload snapshot to server. If encryptionKey is set, encrypts state.
     */
    public function uploadSnapshot(array $state, string $reason = 'initial'): array
    {
        $payload = [
            'state' => $state,
            'clientId' => $this->resolvedClientId,
            'reason' => $reason,
            'vectorClock' => new \stdClass(),
        ];

        if ($this->encryptionKey !== null) {
            $payload['state'] = $this->encryptState($state);
        }

        $resp = $this->getHttpClient()->request('POST', 'snapshot', [
            'json' => $payload,
        ]);
        $this->assertSuccess($resp, 'uploadSnapshot');
        return $this->decode($resp);
    }

    /**
     * Check server status.
     */
    public function getStatus(): array
    {
        $resp = $this->getHttpClient()->request('GET', 'status', []);
        $this->assertSuccess($resp, 'getStatus');
        return $this->decode($resp);
    }

    public function getClientId(): string
    {
        return $this->resolvedClientId;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getEncryptionKey(): ?string
    {
        return $this->encryptionKey;
    }

    /**
     * Decrypt encrypted state.
     *
     * State can be a string (encrypted JSON) or an object like {"0":"x","1":"y",...}.
     */
    private function decryptState($state): array
    {
        // State can arrive as an object {"0":"x","1":"y",...} — reconstruct the string
        if (is_array($state) || is_object($state)) {
            $parts = [];
            foreach ($state as $idx => $ch) {
                $parts[] = $ch;
            }
            $state = implode('', $parts);
        }

        if (!is_string($state)) {
            throw new \RuntimeException('Snapshot state is not a string — server may not be encrypted');
        }

        // Check if it's a base64 string (not JSON)
        if (str_starts_with(trim($state), '{')) {
            return json_decode($state, true) ?? $state;
        }

        $decrypted = Crypto::decrypt($state, $this->encryptionKey);
        $parsed = json_decode($decrypted, true);
        if ($parsed === false) {
            throw new \RuntimeException('json_decode failed on decrypted state');
        }
        return $parsed;
    }

    /**
     * Encrypt state for sending to server.
     */
    private function encryptState(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed for state');
        }

        return Crypto::encrypt($json, $this->encryptionKey);
    }

    private function assertSuccess(ResponseInterface $resp, string $method): void
    {
        if ($resp->getStatusCode() < 400) {
            return;
        }
        $body = $this->decode($resp);
        $error = $body['error'] ?? 'unknown error';
        throw new \RuntimeException("Client {$method} failed ({$resp->getStatusCode()}): {$error}");
    }

    private function decode(ResponseInterface $resp): array
    {
        return json_decode((string) $resp->getBody(), true) ?? [];
    }
}

