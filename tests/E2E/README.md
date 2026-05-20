# E2E Integration Tests — Real Super Productivity

## Overview

These tests verify full synchronization between:
1. **Super Productivity** — official Docker image (`johannesjo/super-productivity`)
2. **PHP Client** — `basis-company/super-sync-client` library
3. **Sync Server** — official sync server (`ghcr.io/johannesjo/supersync`)

## Architecture

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

## Running

### Docker Compose (recommended)

```bash
# Start all infrastructure
docker compose up -d postgres sync-server

# Build and start Super Productivity
docker compose build super-productivity
docker compose up -d super-productivity

# Run tests
docker compose run --rm test-runner

# Run specific test
docker compose run --rm test-runner php vendor/bin/phpunit tests/E2E/SyncTest.php --filter testSPCreatesProjectPhpReads
```

### Locally (without Docker)

```bash
# Start sync server
docker compose up -d postgres sync-server

# Run Super Productivity manually
docker run -d --name sp-test \
  -p 8080:80 \
  -e SYNC_HOST=http://host.docker.internal:3000 \
  johannesjo/super-productivity:latest

# Run tests
php vendor/bin/phpunit tests/E2E/SyncTest.php
```

## Tests

### 1. Server Health
- `testServerIsHealthy` — check sync server availability

### 2. SP → PHP (projects and tasks)
- `testSPCreatesProjectPhpReads` — SP creates project → PHP reads
- `testSPCreatesTaskPhpReads` — SP creates task → PHP reads
- `testSPUpdatesTaskPhpReads` — SP updates task → PHP reads
- `testSPDeletesTaskPhpReads` — SP deletes task → PHP reads
- `testSPProjectWithTasksPhpReads` — SP creates project with tasks → PHP reads

### 3. PHP → SP (projects and tasks)
- `testPhpCreatesProjectSPReads` — PHP creates project → SP reads
- `testPhpCreatesTaskSPReads` — PHP creates task → SP reads
- `testPhpUpdatesTaskSPReads` — PHP updates task → SP reads

### 4. Configuration
- `testSPLocalStorageConfig` — check localStorage configuration
- `testSPLoads` — check that SP loads

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `SYNC_HOST` | Sync server URL | `http://localhost:3000` |
| `SYNC_JWT_SECRET` | JWT token secret | `85b6...` |
| `SYNC_TOKEN` | Ready JWT token | (generated) |
| `SUPER_PRODUCTIVITY_URL` | Super Productivity URL | `http://localhost:8080` |
| `PANTHER_CHROME_DRIVER_HOST` | ChromeDriver host | `chrome` |
| `PANTHER_NO_SANDBOX` | Disable Chrome sandbox | `1` |

## Structure

```
tests/E2E/
├── SyncTest.php          # E2E tests with real Super Productivity
├── E2EHelper.php         # Helper for direct API calls
├── Dockerfile            # Test runner container (PHP + Chrome + Panther)
└── README.md             # This file
```

## Debugging

### Logs
```bash
# Test runner logs
docker compose logs -f test-runner

# Super Productivity logs
docker compose logs -f super-productivity

# Sync server logs
docker compose logs -f sync-server
```

### Debug in browser
```bash
# Open Super Productivity in browser
open http://localhost:8080

# Check sync server
curl http://localhost:3000/api/sync/status
```

### Manual test
```bash
# Create task manually
TOKEN=$(php -r "require 'vendor/autoload.php'; echo \Firebase\JWT\JWT::encode(['userId'=>1,'email'=>'test@example.com','tokenVersion'=>0,'iat'=>time(),'exp'=>time()+3600],'85b62616ab5ce2aceac1f9191240207bab896dd8ccd84b45804a26095d19878b','HS256');")

curl -X POST http://localhost:3000/api/sync/ops \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ops": [{
      "id": "manual-1",
      "clientId": "manual",
      "actionType": "CRT_TASK",
      "opType": "CRT",
      "entityType": "TASK",
      "entityId": "manual-1",
      "payload": {"id": "manual-1", "title": "Manual Task", "isDone": false},
      "vectorClock": {},
      "timestamp": 1234567890000,
      "schemaVersion": 1
    }],
    "clientId": "manual"
  }'
```
