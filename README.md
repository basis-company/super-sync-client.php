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

## Task Fields

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
| `subtasks` | `array` | Subtasks (array of arrays) |
| `reminders` | `array` | Reminders (array of arrays) |
| `recurring` | `bool` | Whether the task is recurring |
| `tags` | `array` | Task tags |
| `priority` | `int` | Priority (0=none, 1=low, 2=medium, 3=high) |
| `doneDate` | `?int` | Date when task was done (milliseconds) |
| `createdAt` | `?int` | Date when task was created (milliseconds) |
| `updatedAt` | `?int` | Date when task was last updated (milliseconds) |

## Task Helper Methods

| Method | Description |
|---|---|
| `isOverdue()` | Check if task is overdue (dueDate is in the past and not done) |
| `isDueToday()` | Check if task is due today |
| `isDueInFuture()` | Check if task is due in the future |
| `getDueDateFormatted()` | Get human-readable due date |
| `getStartFormatted()` | Get human-readable start time |
| `getEstimateFormatted()` | Get estimated time in HH:MM format |
| `getTimeSpentFormatted()` | Get time spent in HH:MM format |

## Workspace

## Project Fields

| Field | Type | Description |
|---|---|---|
| `id` | `string` | Unique project ID |
| `title` | `string` | Project title |
| `themeColor` | `?string` | Theme color (hex) |
| `order` | `int` | Display order |
| `archived` | `bool` | Whether the project is archived |
| `reminderIds` | `array` | Associated reminder IDs |
| `createdAt` | `?int` | Date when project was created (milliseconds) |
| `updatedAt` | `?int` | Date when project was last updated (milliseconds) |

## Project Helper Methods

| Method | Description |
|---|---|
| `isArchived()` | Check if project is archived |
| `archive()` | Archive the project |
| `unarchive()` | Unarchive the project |
| `getThemeColor()` | Get human-readable theme color |
| `setThemeColor(string)` | Set theme color from hex string |

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


### Create a new project

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Project;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Create a new project
$newProject = new Project(
    id: $ws->generateId(),
    title: 'My Project',
    themeColor: '#4fc3f7',
    order: 0,
);

$ws->add($newProject);
$ws->commit();

// Read back and check
$found = $ws->findOne(Project::class, ['id' => $newProject->id]);
echo 'Project: ' . $found->title . PHP_EOL;
echo 'Color: ' . $found->getThemeColor() . PHP_EOL;
echo 'Archived: ' . ($found->isArchived() ? 'yes' : 'no') . PHP_EOL;

// Archive the project
$found->archive();
$ws->commit();


### Create a new project

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Project;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Create a new project
$newProject = new Project(
    id: $ws->generateId(),
    title: 'My Project',
    themeColor: '#4fc3f7', // hex color
);

$ws->add($newProject);
$ws->commit();

// Read back and check
$found = $ws->findOne(Project::class, ['id'] => $newProject->id);
if ($found) {
    echo 'Project: ' . $found->title . ' (color: ' . $found->getThemeColor() . ')' . PHP_EOL;
}
```
### Create task with scheduling and time estimation

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Task;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Create a task with due date and estimate
$task = new Task(
    id: $ws->generateId(),
    title: 'Task with schedule',
    isDone: false,
    dueDate: time() * 1000 + 86400000, // 1 day from now (milliseconds)
    estimate: 3600, // 1 hour in seconds
    notes: 'Important task with deadline',
);

$ws->add($task);
$ws->commit();

// Read back and check
$found = $ws->findOne(Task::class, ['id' => $task->id]);
echo 'Due: ' . $found->getDueDateFormatted() . PHP_EOL; // 2026-05-21 23:50:00
echo 'Estimate: ' . $found->getEstimateFormatted() . PHP_EOL; // 01:00
echo 'Overdue: ' . ($found->isOverdue() ? 'yes' : 'no') . PHP_EOL;
```

### Update task scheduling

```php
<?php

use Basis\SuperSyncClient\Client;
use Basis\SuperSyncClient\Workspace;
use Basis\SuperSyncClient\Task;

$client = new Client($accessToken, encryptionKey: 'your-encryptKey');
$ws = new Workspace($client);
$ws->fetch();

// Find and update task
$task = $ws->findOne(Task::class, ['id' => 'task-123']);
if ($task) {
    // Set due date to tomorrow
    $task->dueDate = strtotime('tomorrow') * 1000;
    
    // Set start time
    $task->start = time() * 1000;
    $task->startMode = 1; // remind
    
    // Set estimate
    $task->estimate = 7200; // 2 hours
    
    $ws->commit();
}
```

## Requirements

- PHP 8.2+
- Extensions: `openssl`, `sodium` (usually bundled)
- GuzzleHTTP 7+
