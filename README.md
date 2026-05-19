# super-sync-client

[![Tests](https://github.com/basis-company/super-sync-client/actions/workflows/tests.yml/badge.svg)](https://github.com/basis-company/super-sync-client/actions/workflows/tests.yml)

> **⚠️ Disclaimer:** This is an **unofficial** client. It is not affiliated with, endorsed by, or connected to Super Productivity or its developers. Use it at your own risk.

A PHP client for the [Super Productivity](https://super-productivity.com/) sync server with end-to-end encryption support.

## Installation

```bash
composer require basis-company/super-sync-client
```

## Quick Start

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Task;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);

// Snapshot (encrypted)
$ws->fetch(true);

// List tasks
foreach ($ws->findAll(Task::class) as $task) {
    echo $task->title, "\n";
}
```

## Constructor

```php
new Client(
    $accessToken,         // JWT token from sync server
    $encryptionKey,       // encryption key (from Sync Settings)
    $clientId,            // optional client ID
    $host                 // server URL
);
```

| Parameter | Type | Description |
|---|---|---|
| `$accessToken` | `string` (required) | JWT authentication token |
| `$encryptionKey` | `?string` | Encryption key from Super Productivity Sync Settings. Without it, `getSnapshot()` returns raw data |
| `$clientId` | `?string` | Unique client ID, auto-generated if not provided |
| `$host` | `string` | Sync server URL, defaults to `https://sync.super-productivity.com/` |

## End-to-End Encryption

Super Productivity encrypts data before sending to the server. The encryption key (`encryptKey`) is set in the UI and **never reaches the server** — the server stores encrypted blobs as opaque data.

**Encryption format:**
```
[SALT: 16 bytes] + [IV: 12 bytes] + [encrypted data] + [auth tag: 16 bytes]
→ base64
```

**KDF:** Argon2id (iterations=3, memory=65536 KiB, parallelism=1)  
**Cipher:** AES-256-GCM

```php
// Without a key — getSnapshot() returns ['state' => 'encrypted-string', ...]
// Client automatically decrypts and parses JSON
$ws->fetch(true);

// Data is available through workspace
$tasks = $ws->findAll(Task::class);

// Download ops (incremental operations) — not encrypted
$ws->fetch();
```

If `encryptionKey` is not provided, `fetch(true)` returns the unencrypted state as-is (usually binary garbage for encrypted servers).

## Workspace

| Method | Description |
|---|---|
| `fetch($forceSnapshot = false)` | Load data: incremental (`false`) or snapshot (`true`) |
| `findOne(Class, criteria)` | Find an entity (typed, generics) |
| `findAll(Class, ?criteria)` | Find all entities |
| `add($entity)` | Queue creation |
| `remove(Class, $id)` | Queue deletion |
| `commit()` | Send changes to server |
| `generateId()` | Generate ID (12 chars, 0-9a-zA-Z) |
| `getServerSeq()` | Current serverSeq |
| `reset()` | Clear local state |

## Examples

### Update an existing task

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Task;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Find a task
$task = $ws->findOne(Task::class, ['id' => 'task-123']);
if ($task) {
    $task->title = 'New title';
    $task->isDone = true;
    $ws->commit();
}
```

### Add a new task to a project

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Task;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Create a new task
$newTask = new Task(
    id: $ws->generateId(),
    title: 'New task',
    isDone: false,
    projectId: 'proj-456', // project ID
);

$ws->add($newTask);
$ws->commit();
```

## Requirements

- PHP 8.2+
- Extensions: `openssl`, `sodium` (usually bundled)
- GuzzleHTTP 7+
