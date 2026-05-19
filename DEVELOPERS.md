# Developer Guide

## Coding Standards

PSR-12, PHP 8.2+. Explicit nullable types (`?string`), no implicit nullable.

## Architecture

```
src/
  Client.php      — HTTP-транспорт, JWT, прозрачное шифрование snapshot
  Workspace.php   — Data Mapper + Identity Map
  Crypto.php      — E2EE: Argon2id + AES-256-GCM
  Task.php        — Task DTO
  Project.php     — Project DTO
```

### Компоненты

| Компонент | Роль |
|---|---|
| **Client** | HTTP-транспорт, JWT auth. При `encryptionKey !== null` автоматически шифрует/дешифрует snapshot |
| **Crypto** | Статический класс для E2EE шифрования. Wire формат: `[SALT:16][IV:12][ciphertext][tag:16]` → base64 |
| **Workspace** | Data Mapper, sync orchestration, Identity Map |
| **Task / Project** | DTO с `fromArray()` / `toArray()` / `getSectionType()` |

### E2EE Шифрование

Super Productivity шифрует состояние до отправки на сервер. Ключ шифрования (`encryptKey`) задаётся в UI и **не знает сервер** — он хранит данные как непрозрачные блобы.

**Wire формат:**
```
[SALT: 16 байт] + [IV: 12 байт] + [зашифрованные данные] + [auth tag: 16 байт]
→ base64_encode()
```

**KDF:** Argon2id (Argon2 ID variant)
- opslimit: 3 (iterations)
- memlimit: 65536 KiB (64 MiB)
- alg: `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`
- hashLength: 32 байта (256-битный ключ)

**Cipher:** AES-256-GCM через `openssl_encrypt` / `openssl_decrypt`
- Auth tag (16 байт) извлекается из конца ciphertext
- Передаётся отдельно через `openssl_decrypt` (не в составе ciphertext)

```php
// Шифрование
$encrypted = Crypto::encrypt($jsonPlaintext, $encryptKey);

// Расшифрование
$plaintext = Crypto::decrypt($encryptedBase64, $encryptKey);
```

### Client + Encryption

Client принимает `$encryptionKey` и прозрачно обрабатывает зашифрованный snapshot:

```php
new Client($accessToken, $encryptionKey = null, $clientId = null, $host);
```

При `getSnapshot()` с ключом:
1. Загружает сырой snapshot с сервера
2. Извлекает поле `state` (base64-строку wire-формата)
3. Расшифровывает через `Crypto::decrypt()`
4. Парсит JSON и возвращает структуру `['state' => [...], 'serverSeq' => N]`

Без ключа возвращает snapshot как есть (binарный мусор для зашифрованных серверов).

### Data Mapper

`Workspace` хранит локальное состояние как `[ENTITY_TYPE => [id => data]]`. На `commit()`, dirty-сущности отправляются как CRT/UPD/DEL операции. Операции сервера применяются через `fetch()`.

### Identity Map

`$map: [id => entity]` — каждый уникальный ID существует как один объект в памяти. `findOne()` всегда возвращает одинаковый объект.

### Generics

`findOne()` и `findAll()` используют `@template T of Task|Project` для inference типов в IDE:

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

## Тестирование

```bash
# Интеграционные тесты (HTTP без браузера)
php vendor/bin/phpunit tests/IntegrationTest.php

# E2E (Chrome + ChromeDriver)
PANTHER_CHROME_DRIVER_BINARY=/path/to/chromedriver \
php vendor/bin/phpunit tests/E2E/
```

## Экстензии PHP

- `openssl` — AES-256-GCM (в бандле PHP)
- `sodium` — Argon2id KDF (в бандле PHP)
- `mbstring` — для Guzzle
