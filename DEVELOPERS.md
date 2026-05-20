# Developer Guide

## Coding Standards

PSR-12, PHP 8.2+. Explicit nullable types (`?string`), no implicit nullable.

## Architecture

```
src/
  Client.php      — HTTP transport, JWT, transparent snapshot encryption
  Workspace.php   — Data Mapper + Identity Map
  Crypto.php      — E2EE: Argon2id + AES-256-GCM
  Task.php        — Task DTO
  Project.php     — Project DTO
```

### Components

| Component | Role |
|---|---|
| **Client** | HTTP transport, JWT auth. Automatically encrypts/decrypts snapshot when `encryptionKey !== null` |
| **Crypto** | Static class for E2EE encryption. Wire format: `[SALT:16][IV:12][ciphertext][tag:16]` → base64 |
| **Workspace** | Data Mapper, sync orchestration, Identity Map |
| **Task / Project** | DTO with `fromArray()` / `toArray()` / `getSectionType()` |

### E2EE Encryption

### Task DTO

| Field | Type | Description |
|---|---|---|
| `id` | `string` | Unique task ID |
| `title` | `string` | Task title |
| `isDone` | `bool` | Whether the task is completed |
| `projectId` | `?string` | Associated project ID |
| `dueDate` | `?int` | Due date in milliseconds (Unix timestamp * 1000) |
| `start` | `?int` | Start time in milliseconds (Unix timestamp * 1000) |
| `startMode` | `?int` | Start mode (0=none, 1=remind, 2=auto) |
| `estimate` | `?int` | Estimated time in seconds |
| `estimatedTimeSpent` | `?int` | Estimated time spent in seconds |
| `timeSpent` | `?int` | Actual time spent in seconds |
| `notes` | `string` | Task notes/description |
| `subtasks` | `array` | Subtasks |
| `reminders` | `array` | Reminders |
| `recurring` | `bool` | Whether the task is recurring |
| `tags` | `array` | Task tags |
| `priority` | `int` | Priority (0=none, 1=low, 2=medium, 3=high) |
| `doneDate` | `?int` | Date when task was done (milliseconds) |
| `createdAt` | `?int` | Date when task was created (milliseconds) |
| `updatedAt` | `?int` | Date when task was last updated (milliseconds) |

### Task Methods

| Method | Return Type | Description |
|---|---|---|
| `isOverdue()` | `bool` | Check if task is overdue |
| `isDueToday()` | `bool` | Check if task is due today |
| `isDueInFuture()` | `bool` | Check if task is due in the future |
| `getDueDateFormatted()` | `?string` | Get formatted due date (Y-m-d H:i:s) |
| `getStartFormatted()` | `?string` | Get formatted start time (Y-m-d H:i:s) |
| `getEstimateFormatted()` | `?string` | Get formatted estimate (HH:MM) |
| `getTimeSpentFormatted()` | `?string` | Get formatted time spent (HH:MM) |

Super Productivity encrypts state before sending to the server. The encryption key (`encryptKey`) is set in the UI and **the server does not know it** — it stores data as opaque blobs.

**Wire format:**
```
[SALT: 16 bytes] + [IV: 12 bytes] + [encrypted data] + [auth tag: 16 bytes]
→ base64_encode()
```

**KDF:** Argon2id (Argon2 ID variant)
- opslimit: 3 (iterations)
- memlimit: 65536 KiB (64 MiB)
- alg: `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`
- hashLength: 32 bytes (256-bit key)

**Cipher:** AES-256-GCM via `openssl_encrypt` / `openssl_decrypt`
- Auth tag (16 bytes) extracted from the end of ciphertext
- Passed separately via `openssl_decrypt` (not included in ciphertext)

```php
// Encryption
$encrypted = Crypto::encrypt($jsonPlaintext, $encryptKey);

// Decryption
$plaintext = Crypto::decrypt($encryptedBase64, $encryptKey);
```

### Client + Encryption

Client accepts `$encryptionKey` and transparently handles encrypted snapshots:

```php
new Client($accessToken, $encryptionKey = null, $clientId = null, $host);
```

On `getSnapshot()` with key:
1. Loads raw snapshot from server
2. Extracts `state` field (base64 wire format string)
3. Decrypts via `Crypto::decrypt()`
4. Parses JSON and returns `['state' => [...], 'serverSeq' => N]`

Without key returns snapshot as-is (binary garbage for encrypted servers).

### Data Mapper

`Workspace` stores local state as `[ENTITY_TYPE => [id => data]]`. On `commit()`, dirty entities are sent as CRT/UPD/DEL operations. Server operations are applied via `fetch()`.

### Identity Map

`$map: [id => entity]` — each unique ID exists as one object in memory. `findOne()` always returns the same object.

### Generics

`findOne()` and `findAll()` use `@template T of Task|Project` for type inference in IDE:

```php
/**
 * @template T of Task|Project
 * @param class-string<T> $class
 * @return T|null
 */
public function findOne(string $class, array $criteria): ?object
```

### Operations API

```json
POST /api/sync/ops
{
  "ops": [{
    "id": "generated",
    "clientId": "php-client",
    "actionType": "CRT_TASK",
    "opType": "CRT",
    "entityType": "TASK",
    "entityId": "task-123",
    "payload": {"id": "task-123", "title": "Hello"},
    "vectorClock": {},
    "timestamp": 1700000000000,
    "schemaVersion": 1
  }],
  "clientId": "php-client"
}
```

## Testing

### Integration Tests (HTTP without browser)

```bash
php vendor/bin/phpunit tests/
```

### E2E Tests (Chrome + ChromeDriver + real Super Productivity)

```bash
# Start all infrastructure (sync-server + super-productivity + chrome + test-runner)
docker compose up -d

# Run tests
docker compose run --rm test-runner

# Run only integration tests (no browser)
docker compose run --rm test-runner php vendor/bin/phpunit tests/

# Run only E2E tests
docker compose run --rm test-runner php vendor/bin/phpunit tests/E2E/
```

### E2E Test Structure

```
tests/E2E/
├── SyncTest.php          # E2E tests with real Super Productivity
├── E2EHelper.php         # Helper for direct API calls
├── Dockerfile            # Test runner container (PHP + Chrome + Panther)
└── README.md             # E2E test documentation
```

### Test Scenarios

1. **Server Health** — check sync server availability
2. **SP Creates Project → PHP Reads** — SP creates project, PHP reads it
3. **SP Creates Task → PHP Reads** — SP creates task, PHP reads it
4. **PHP Creates Project → SP Reads** — PHP creates project, SP reads it
5. **PHP Creates Task → SP Reads** — PHP creates task, SP reads it
6. **SP Updates Task → PHP Reads** — SP updates task, PHP reads it
7. **PHP Updates Task → SP Reads** — PHP updates task, SP reads it
8. **SP Deletes Task → PHP Reads** — SP deletes task, PHP reads it
9. **PHP Deletes Task → SP Reads** — PHP deletes task, SP reads it
10. **SP Project with Tasks → PHP Reads** — SP creates project with tasks, PHP reads them
11. **Full E2E Flow** — SP → PHP → SP (full cycle)
12. **SP Loads** — check that SP loads
13. **SP LocalStorage Config** — check localStorage configuration

### Architecture

```
┌──────────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│ Super Productivity   │────▶│  Sync Server     │◀────│  PHP Client     │
│ (johannesjo/super-   │     │  (supersync)     │     │  (PHPUnit +     │
│  productivity)       │     │                  │     │   Panther)      │
└──────────────────────┘     └──────────────────┘     └─────────────────┘
        │                                         │
        │  localStorage: sync URL + token         │  Workspace API
        │  UI: add/edit/delete projects & tasks   │  fetch/commit
        └─────────────────────────────────────────┘
```

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `SYNC_HOST` | Sync server URL | `http://localhost:3000` |
| `SYNC_JWT_SECRET` | JWT token secret | `85b6...` |
| `SYNC_TOKEN` | Ready JWT token | (generated) |
| `SUPER_PRODUCTIVITY_URL` | Super Productivity URL | `http://localhost:8080` |
| `PANTHER_CHROME_DRIVER_HOST` | ChromeDriver host | `chrome` |
| `PANTHER_NO_SANDBOX` | Disable Chrome sandbox | `1` |

## PHP Extensions

- `openssl` — AES-256-GCM (bundled with PHP)
- `sodium` — Argon2id KDF (bundled with PHP)
- `mbstring` — for Guzzle
