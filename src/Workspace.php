<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

class Workspace
{
    private array $state = [];
    private array $map = [];
    private int $serverSeq = 0;
    private array $pendingAdds = [];
    private array $pendingRemoves = [];

    public function __construct(
        private readonly Client $api,
    ) {
    }

    /**
     * Fetch state from the server.
     * Always uses the ops endpoint (high rate limit).
     * On first call, downloads all ops since seq 0 to rebuild state.
     * On subsequent calls, downloads only new ops since last sync.
     *
     * Pass $forceSnapshot = true to use the snapshot endpoint instead
     * (slower, rate-limited to 10 requests per 5 minutes).
     */
    public function fetch(bool $forceSnapshot = false): void
    {
        if ($forceSnapshot) {
            $this->fetchBySnapshot();
        } else {
            $this->fetchByOps();
        }
    }

    private function fetchBySnapshot(): void
    {
        $data = $this->api->getSnapshot();
        $state = $data['state'] ?? [];

        foreach ($state as $type => $entities) {
            if (!is_array($entities)) continue;

            // Server may send {'ids': [...], 'entities': {id: data}} structure
            if (isset($entities['entities']) && is_array($entities['entities'])) {
                $entities = $entities['entities'];
            }

            foreach ($entities as $id => $entity) {
                if (!is_array($entity)) continue;
                $this->state[$type][$id] = $entity;
            }
        }

        $this->serverSeq = $data['serverSeq'] ?? 0;
        $this->map = [];
    }

    private function fetchByOps(): void
    {
        // On first fetch (serverSeq == 0), download ALL ops including from our client
        // to rebuild full state. On subsequent fetches, exclude our own ops to avoid duplicates.
        $exclude = $this->serverSeq === 0 ? null : $this->api->getClientId();
        $result = $this->api->downloadOps($this->serverSeq, $exclude);
        $ops = $result['ops'] ?? [];
        // Don't reset serverSeq backwards — server may return 0 on empty response
        $newSeq = $result['latestSeq'] ?? $this->serverSeq;
        if ($newSeq > $this->serverSeq) {
            $this->serverSeq = $newSeq;
        }

        foreach ($ops as $entry) {
            $this->applyOp($entry['op']);
        }
    }

    /**
     * Find an entity by criteria. Uses identity map for caching.
     *
     * @template T of Task|Project
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    public function findOne(string $class, array $criteria): ?object
    {
        $type = $class::getSectionType();
        if (!isset($this->state[$type])) return null;

        foreach ($this->state[$type] as $id => $data) {
            $match = true;
            foreach ($criteria as $k => $v) {
                if (($data[$k] ?? null) !== $v) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                if (!isset($this->map[$id])) {
                    $this->map[$id] = $class::fromArray($data);
                }
                return $this->map[$id];
            }
        }

        return null;
    }

    /**
     * Find all entities matching criteria.
     *
     * @template T of Task|Project
     * @param class-string<T> $class
     * @param array<string, mixed>|null $criteria
     * @return list<T>
     */
    public function findAll(string $class, ?array $criteria = null): array
    {
        $type = $class::getSectionType();
        $results = [];

        if (!isset($this->state[$type])) return [];

        foreach ($this->state[$type] as $id => $data) {
            if ($criteria !== null) {
                $match = true;
                foreach ($criteria as $k => $v) {
                    if (($data[$k] ?? null) !== $v) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) continue;
            }

            if (!isset($this->map[$id])) {
                $this->map[$id] = $class::fromArray($data);
            }
            $results[] = $this->map[$id];
        }

        return $results;
    }

    public function add(object $entity): void
    {
        $this->map[$entity->id] = $entity;
        $this->pendingAdds[] = $entity;
    }

    public function remove(string $class, string $id): void
    {
        $type = $class::getSectionType();
        if (isset($this->state[$type][$id])) {
            $this->pendingRemoves[$type][$id] = true;
            unset($this->state[$type][$id]);
        }
        unset($this->map[$id]);
    }

    public function commit(): void
    {
        $ops = [];

        // Adds
        foreach ($this->pendingAdds as $entity) {
            $type = $entity::getSectionType();
            $data = $entity->toArray();
            $this->state[$type][$entity->id] = $data;
            $ops[] = $this->buildOp('CRT', $type, $entity->id, $data);
        }

        // Deletes
        foreach ($this->pendingRemoves as $type => $ids) {
            foreach (array_keys($ids) as $id) {
                $ops[] = $this->buildOp('DEL', $type, $id, null);
            }
        }

        // Updates (dirty check)
        foreach ($this->map as $id => $entity) {
            if (in_array($entity, $this->pendingAdds, true)) continue;

            $type = $entity::getSectionType();
            if (isset($this->pendingRemoves[$type][$id])) continue;

            $current = $entity->toArray();
            $original = $this->state[$type][$id] ?? null;

            if ($original !== null && $current !== $original) {
                $this->state[$type][$id] = $current;
                $ops[] = $this->buildOp('UPD', $type, $id, $current);
            }
        }

        if (empty($ops)) return;

        $result = $this->api->uploadOps($ops);
        foreach ($result['results'] ?? [] as $r) {
            if (($r['accepted'] ?? false) && isset($r['serverSeq'])) {
                $this->serverSeq = max($this->serverSeq, $r['serverSeq']);
            }
        }

        $this->pendingAdds = [];
        $this->pendingRemoves = [];
    }

    public function getServerSeq(): int { return $this->serverSeq; }

    public function reset(): void
    {
        $this->state = [];
        $this->map = [];
        $this->pendingAdds = [];
        $this->pendingRemoves = [];
        $this->serverSeq = 0;
    }

    public function getState(): array { return $this->state; }

    private function applyOp(array $op): void
    {
        $type = $op['opType'];
        $entityType = $op['entityType'];
        $entityId = $op['entityId'] ?? null;
        $payload = $op['payload'] ?? [];

        if ($entityId === null) return;

        // Server may return payload as JSON string — convert to array
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }

        if ($type === 'CRT') {
            $this->state[$entityType][$entityId] = $payload;
        } elseif ($type === 'UPD') {
            $this->state[$entityType][$entityId] = array_merge(
                $this->state[$entityType][$entityId] ?? [],
                $payload
            );
        } elseif ($type === 'DEL') {
            unset($this->state[$entityType][$entityId]);
        }

        unset($this->map[$entityId]);
    }

    private function buildOp(string $opType, string $entityType, string $entityId, array|null $payload): array
    {
        return [
            'id' => $this->generateId(),
            'clientId' => $this->api->getClientId(),
            'actionType' => $opType . '_' . $entityType,
            'opType' => $opType,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'payload' => $payload,
            'vectorClock' => new \stdClass(),
            'timestamp' => (int)(microtime(true) * 1000),
            'schemaVersion' => 1,
        ];
    }

    public function generateId(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
