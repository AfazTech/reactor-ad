# Reactor Ad — Documentation

Complete reference for the `afaztech/reactor-ad` package.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Core Concepts](#core-concepts)
- [API Reference](#api-reference)
  - [Slot Lifecycle](#slot-lifecycle)
  - [Slot Configuration](#slot-configuration)
  - [Slot State](#slot-state)
  - [Delivery](#delivery)
  - [Low-Level Access](#low-level-access)
- [Delivery Semantics](#delivery-semantics)
- [Storage](#storage)
- [Error Handling](#error-handling)
- [Examples](#examples)
- [Testing](#testing)

---

## Overview

Reactor Ad provides a flexible, slot-based ad delivery system for Reactor Telegram bots. Instead of hard-coding ad behavior, the package exposes a set of programmable slots that the host application configures and fires on its own terms.

Each slot carries:

| Field | Description |
|-------|-------------|
| `name` | User-defined identifier (`welcome`, `after_purchase`, ...) |
| `strategy` | `copy` or `forward` |
| `chat_id` | Source chat (numeric id or `@username`) |
| `message_id` | Source message in that chat |
| `probability` | 0–100, used only by `sendRandom()` |
| `enabled` | Master on/off switch |

Delivery is entirely the host application's responsibility. The package provides the API; the application decides when to call it — from a handler, a cron job, a webhook, a CLI command, or an admin panel.

The package registers no handler, no middleware, and no event listener. It is a pure data + delivery layer.

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0

The framework provides `Neili\Client`, `DatabaseManagerInterface`, and `LoggerInterface` — the package's only runtime dependencies.

---

## Installation

```bash
composer require afaztech/reactor-ad
./reactor migrate
```

The migration creates the `ad_settings` table. The service provider is auto-discovered via Composer metadata.

---

## Configuration

### Table Name

By default, slot data is stored in `ad_settings`. Override via `AdConfig`:

```php
use AfazTech\ReactorAd\Support\AdConfig;

$container->singleton(AdConfig::class, fn () => (new AdConfig())
    ->table('custom_ad_settings'));
```

| Method | Default | Description |
|--------|---------|-------------|
| `table(string $name)` | `ad_settings` | Name of the storage table. |

Changing this requires a custom migration that creates the table with the same shape:

```php
$table->id();
$table->string('key', 64)->unique();
$table->text('value');
$table->timestamps();
```

### Accessing the Manager

```php
use AfazTech\ReactorAd\Contracts\AdManagerInterface;

$ads = $container->get(AdManagerInterface::class);
```

The binding is a singleton. Every consumer in the same process shares the same instance, along with its in-process cache.

---

## Core Concepts

### Slots

A **slot** is a named ad container. Slot names must match `/^[A-Za-z0-9_-]{1,32}$/`. Names are case-sensitive.

Slots are independent. Creating, updating, or deleting one slot never affects another.

### Enabled vs. Configured

A slot is **configured** when both `chat_id` is non-empty and `message_id` is positive. The `hasMessage()` method reports this.

A slot is **enabled** when the `enabled` flag is `true`. This is the master switch.

Delivery requires **both**:

- `isEnabled(name) === true`
- `hasMessage(name) === true`

### Deterministic vs. Probabilistic

Two delivery methods exist:

- `send()` — always delivers (if enabled + configured). Probability is ignored.
- `sendRandom()` — delivers only when a random roll passes against `probability`.

The choice is entirely the caller's. The same slot can be used deterministically by one caller and probabilistically by another.

### Enabled-on-Save

`setSource()` sets `enabled = true` as a side effect. This matches the intuitive admin flow: when you configure a slot, you want it to fire. To configure a source without enabling it, call `setEnabled(false)` after `setSource()`.

---

## API Reference

### Slot Lifecycle

#### `createSlot(string $name): void`

Creates a slot with default configuration (empty source, strategy `copy`, probability 100, disabled).

Idempotent: calling it twice for the same name is a no-op. Callers do not need to check `hasSlot()` first.

```php
$ads->createSlot('welcome');
```

**Throws:** `InvalidArgumentException` when the name is invalid.

#### `deleteSlot(string $name): void`

Permanently removes a slot and its configuration.

```php
$ads->deleteSlot('welcome');
```

Delivery calls on a deleted slot return `false` and log a debug message. Deleting a slot that does not exist is a no-op.

#### `hasSlot(string $name): bool`

Whether the named slot exists.

```php
if ($ads->hasSlot('welcome')) {
    // ...
}
```

Invalid names return `false` (they can never exist by definition).

#### `listSlots(): array`

Returns an array of slot names. Ordering is not guaranteed and may vary between calls.

```php
foreach ($ads->listSlots() as $slot) {
    echo $slot . "\n";
}
```

---

### Slot Configuration

All configuration methods operate on the slot by name. If the slot does not exist yet, they create it with the default config, apply the change, and persist.

#### `setSource(string $name, string $strategy, int|string $chatId, int $messageId): void`

Sets the source message and enables the slot.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | string | Slot name |
| `$strategy` | string | `copy` or `forward`; anything else falls back to `copy` |
| `$chatId` | int\|string | Numeric chat id (int) or username (`@channel`, string) |
| `$messageId` | int | Source message id |

```php
$ads->setSource('welcome', 'copy', -1001234567890, 42);
$ads->setSource('promo', 'forward', '@my_channel', 100);
```

Numeric chat ids are passed as ints; usernames as strings starting with `@`. The package does not validate that the chat exists — a bad chat id simply causes delivery to fail (logged at warning level).

#### `setProbability(string $name, int $probability): void`

Sets the delivery probability for `sendRandom()`. Values are clamped to `[0, 100]`.

```php
$ads->setProbability('welcome', 30); // 30%
```

Default is `100`, meaning every `sendRandom()` call delivers. Set to `0` to never deliver via `sendRandom()` without deleting the slot.

#### `setEnabled(string $name, bool $enabled): void`

Turns the slot on or off. Both `send()` and `sendRandom()` return `false` when the slot is disabled.

```php
$ads->setEnabled('welcome', false); // temporarily disable
$ads->setEnabled('welcome', true);  // re-enable
```

Toggling does not touch the source, strategy, or probability — only the `enabled` flag.

#### `clearSlot(string $name): void`

Resets every configuration field to its default:

- `strategy` → `copy`
- `chat_id` → `''`
- `message_id` → `0`
- `probability` → `100`
- `enabled` → `false`

The slot itself is kept. To delete a slot entirely, use `deleteSlot()`.

```php
$ads->clearSlot('welcome');
```

---

### Slot State

All state methods return sensible defaults for missing or invalid slot names:

- String getters return `''` or `'copy'`
- Integer getters return `0` or `100`
- Boolean getters return `false`

This means callers do not need to guard against missing slots in display code.

| Method | Returns | Default when missing |
|--------|---------|---------------------|
| `isEnabled(string $name): bool` | Whether the slot is enabled | `false` |
| `getStrategy(string $name): string` | `'copy'` or `'forward'` | `'copy'` |
| `getChatId(string $name): string` | Chat id as stored, or `''` | `''` |
| `getMessageId(string $name): int` | Message id, or `0` | `0` |
| `getProbability(string $name): int` | Value in `[0, 100]` | `100` |
| `hasMessage(string $name): bool` | Both chat_id and message_id set | `false` |

Example:

```php
$info = [
    'name'        => 'welcome',
    'enabled'     => $ads->isEnabled('welcome'),
    'strategy'    => $ads->getStrategy('welcome'),
    'chat_id'     => $ads->getChatId('welcome'),
    'message_id'  => $ads->getMessageId('welcome'),
    'probability' => $ads->getProbability('welcome'),
    'configured'  => $ads->hasMessage('welcome'),
];
```

---

### Delivery

#### `send(string $name, int $userId): bool`

Delivers the slot to `$userId` **unconditionally** (probability is ignored). Requires the slot to be enabled and configured.

Returns `true` on success, `false` when:

- The slot is disabled
- The slot is not configured (`hasMessage()` returns `false`)
- Telegram rejected the request (user blocked the bot, source deleted, bot not a member, network error, ...)

Delivery failures never throw. They are logged at `warning` level with the slot name and error message.

```php
if ($ads->send('welcome', $userId)) {
    // delivered
}
```

#### `sendRandom(string $name, int $userId): bool`

Delivers the slot to `$userId` **probabilistically**. Roll semantics:

1. If the slot is disabled → `false`
2. If the slot is not configured → `false`
3. If probability is `0` → `false`
4. Roll `random_int(1, 100)`; if the result is greater than the probability → `false`
5. Otherwise, delegate to `send()` semantics

```php
if ($ads->sendRandom('promo', $userId)) {
    // the dice passed and delivery succeeded
}
```

Because `sendRandom()` respects the same enabled and configured checks as `send()`, disabling a slot takes effect for both methods immediately.

---

### Low-Level Access

The `ad_settings` table is a generic key/value store. Custom keys can share it alongside slot rows.

#### `get(string $key, string $default = ''): string`

Reads any key. Values are cached in-process for the lifetime of the `AdManager` singleton.

```php
$cap = (int) $ads->get('ad_daily_cap', '5');
```

If the underlying query fails, the default value is returned and the fallback is **not** cached, so a transient database error does not freeze the default.

#### `set(string $key, string $value): void`

Writes any key and refreshes the cache entry.

```php
$ads->set('ad_daily_cap', '10');
```

Custom keys must not begin with `slot:`, which is reserved for slot storage.

---

## Delivery Semantics

### Strategy

| Strategy | Client method used | Label |
|----------|-------------------|-------|
| `copy` | `Neili\Client::copyMessage()` | None |
| `forward` | `Neili\Client::forwardMessage()` | "Forwarded from ..." |

Copy is the default. Forward preserves the source attribution.

### Chat Id Handling

- Numeric chat ids (`-1001234567890`) are cast to `int` before being sent to Telegram.
- Username strings (`@channel`) are sent as-is.

The package auto-detects the type via `is_numeric()`. No manual conversion is needed.

### Failure Modes

A delivery attempt can fail for any of the following reasons:

- The user blocked the bot (`403 Forbidden: bot was blocked`)
- The source chat was deleted or the bot was removed from it
- The source message was deleted
- The bot is not a member of the source chat
- Network error, timeout
- Telegram rate limit

In every case, `send()` / `sendRandom()` return `false`, a warning is logged, and no exception is raised. The caller's flow continues uninterrupted.

If the caller needs stricter error handling, they can listen for `warning`-level logs via the framework's logger, or invoke the underlying `Neili\Client` methods directly.

### Debugging

Two debug-level log entries help diagnose silent skips:

- `Ad delivery skipped: slot is disabled` — `enabled` flag is `false`
- `Ad delivery skipped: slot has no source configured` — missing `chat_id` or `message_id`

Enable debug mode (`DEBUG_MODE=true`) to see them.

---

## Storage

The migration creates `ad_settings` with the following shape:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `key` | varchar(64) | Unique |
| `value` | text | Slot JSON or custom string |
| `created_at`, `updated_at` | timestamps | |

Slot rows use the key `slot:{name}` and store the following JSON document:

```json
{
  "strategy": "copy",
  "chat_id": "-1001234567890",
  "message_id": 42,
  "probability": 30,
  "enabled": true
}
```

Missing fields in a stored document are filled in with defaults when loading, so older rows remain forward-compatible.

### In-Process Cache

Reads are cached for the lifetime of the `AdManager` singleton. Writes refresh the affected cache entry immediately. There is no cross-process cache, so a second process reading the same table always sees the latest committed value.

The cache is bypassed for:

- `listSlots()` — always queries the database
- `hasSlot()` — queries on cache miss only
- `loadSlot()` — always queries the database

This keeps slot listings accurate even when the table was modified by another process between calls.

### Reserved Keys

Keys beginning with `slot:` are reserved for slot storage. Custom keys must avoid this prefix.

---

## Error Handling

| Situation | Behavior |
|-----------|----------|
| Invalid slot name (fails `/^[A-Za-z0-9_-]{1,32}$/`) | `InvalidArgumentException` on `createSlot`, `setSource`, `setProbability`, `setEnabled`, `clearSlot`, `deleteSlot` |
| Missing slot on `deleteSlot` | No-op |
| Missing slot on `clearSlot` | Creates the slot with defaults, then clears (which is also defaults) |
| Missing slot on getters | Defaults returned |
| Missing slot on `send` / `sendRandom` | Returns `false` |
| Table does not exist | `RuntimeException` on first operation that touches the table |
| Database read failure during `get` | Fallback value returned, `warning` logged |
| Database write failure during `set` | `warning` logged, in-process cache not updated |
| Telegram delivery failure | Returns `false`, `warning` logged |

All error paths that can be triggered by normal runtime use (delivery, DB read/write) are non-throwing. Only programmer errors (invalid slot names) and configuration errors (missing table) throw.

---

## Examples

### Basic welcome ad

```php
$ads->createSlot('welcome');
$ads->setSource('welcome', 'copy', -1001234567890, 42);
$ads->setProbability('welcome', 100);

// Inside your /start handler:
$userId = $this->getUserId();
$ads->send('welcome', $userId);
```

### Probabilistic promo

```php
$ads->createSlot('promo');
$ads->setSource('promo', 'forward', '@my_channel', 100);
$ads->setProbability('promo', 25); // 25% chance

// After every successful handler:
$ads->sendRandom('promo', $userId);
```

### Multiple slots with different policies

```php
$ads->createSlot('welcome');
$ads->setSource('welcome', 'copy', -1001234567890, 42);
$ads->setProbability('welcome', 100); // always

$ads->createSlot('daily');
$ads->setSource('daily', 'forward', '@announcements', 200);
$ads->setProbability('daily', 10);    // rarely

$ads->createSlot('purchase');
$ads->setSource('purchase', 'copy', -1001234567890, 500);
// fires only when called explicitly with send()
```

### Enabling and disabling on demand

```php
// Disable temporarily for a seasonal pause
$ads->setEnabled('promo', false);

// Later, re-enable with the same source and probability
$ads->setEnabled('promo', true);
```

### Inspector in an admin panel

```php
foreach ($ads->listSlots() as $slot) {
    printf(
        "%s: %s, %s, prob=%d%%, %s\n",
        $slot,
        $ads->isEnabled($slot) ? 'on' : 'off',
        $ads->getStrategy($slot),
        $ads->getProbability($slot),
        $ads->hasMessage($slot) ? 'configured' : 'unconfigured'
    );
}
```

### Integration with the framework's `HANDLER_EXECUTED` event

```php
use Reactor\Events;
use Reactor\Core\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(Events::HANDLER_EXECUTED, function (string $handler, array $update) use ($ads): void {
    // Skip admin handlers so panel navigation is not peppered with ads.
    if (str_starts_with($handler, 'App\\Handlers\\Admin\\')) {
        return;
    }

    $userId = $update['message']['from']['id']
        ?? $update['callback_query']['from']['id']
        ?? null;

    if ($userId === null) {
        return;
    }

    $ads->sendRandom('promo', (int) $userId);
});
```

---

## Testing

The package is designed to be tested without a live Telegram connection by mocking `AdManagerInterface`.

```php
$ads = $this->createMock(AdManagerInterface::class);
$ads->method('send')->willReturn(true);
$ads->method('sendRandom')->willReturn(false);

$container->instance(AdManagerInterface::class, $ads);
```

For integration tests against the real database, use a throwaway SQLite file and run the migration before each test class.

Slot data is fully inspectable via `listSlots()`, `getStrategy()`, `getChatId()`, `getMessageId()`, `getProbability()`, and `isEnabled()`, so assertions do not need to touch the database directly.

---

## License

This project is licensed under the [MIT License](LICENSE).