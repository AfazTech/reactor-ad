# Reactor Ad

Headless random and start ad delivery for **[Reactor](https://github.com/afaztech/reactor-framework)** bots.

> **Built specifically for the Reactor PHP framework.**
> Requires `afaztech/reactor-framework ^0.5.0` and will not work standalone.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Framework](https://img.shields.io/badge/reactor-%5E0.5.0-orange.svg)](https://github.com/afaztech/reactor-framework)

---

## Overview

Reactor Ad manages two independent ad slots inside a Telegram bot:

| Slot | When it fires | Probability control |
| --- | --- | --- |
| **Random ad** | After each user update | Yes (`0..100`) |
| **Start ad** | Before the `/start` greeting | No (fires every time when enabled) |

Both slots share the same delivery mechanics: they copy or forward a source message from a chat the bot can access. That means the operator can edit the source message at any time and the next delivery reflects the new content — no bot redeploy is needed.

The package is deliberately **headless**: it does not register any handler, middleware, or event listener. Every read, write, and delivery call is explicit, so the host application owns the timing and targeting decisions. This keeps the package framework-agnostic in spirit: it only depends on `Neili\Client`, the framework's `DatabaseManagerInterface`, and `LoggerInterface`.

Unlike `reactor-broadcast`, which queues jobs, reactor-ad is synchronous. Ads are tiny, single-message deliveries fired inline after a handler runs. Introducing a queue would add latency and operational cost without a benefit.

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0
- A Reactor application with a working database connection (SQLite or MySQL)
- A `Neili\Client` binding in the container (the framework's `TelegramBootstrapper` provides one)

---

## Installation

```bash
composer require afaztech/reactor-ad
php reactor.php migrate
```

The service provider is auto-discovered through Composer metadata (`extra.reactor.providers`), and the migration is picked up from `extra.reactor.migrations_namespace`.

If you are upgrading an existing application that previously stored ad keys in a `settings` table, the migration copies every `random_ad_*` and `start_ad_*` row into the new `ad_settings` table so admin configuration survives the upgrade.

---

## Configuration

The package is configured through the `AdConfig` value object, bound as a singleton by `AdServiceProvider`. There is exactly one tunable:

```php
use AfazTech\ReactorAd\Support\AdConfig;

$container->singleton(AdConfig::class, fn () => (new AdConfig())
    ->table('custom_ad_settings'));
```

| Setter | Default | Description |
| --- | --- | --- |
| `table(string $name)` | `ad_settings` | Name of the key/value table used for storage. |

Changing the table name requires providing your own migration. The package migration only creates `ad_settings`; if you override the table, add a custom migration that creates your table with the same `(id, key UNIQUE, value, timestamps)` shape.

---

## Usage

```php
use AfazTech\ReactorAd\Contracts\AdManagerInterface;

$ads = $container->get(AdManagerInterface::class);
```

### Configure a random ad

```php
// Save the source, strategy, and enable the slot in one call.
$ads->setRandomSource('copy', -1001234567890, 42);
// setRandomSource() also flips the enabled flag to true.

// Set the delivery probability (0..100).
$ads->setRandomProbability(30);
```

### Configure a start ad

```php
$ads->setStartSource('forward', '@my_channel', 100);
// Same behavior: enabling the slot is implied by setStartSource().
```

### Inspect the current state

```php
$ads->isRandomEnabled();       // bool
$ads->randomStrategy();        // 'copy' | 'forward'
$ads->randomChatId();          // numeric string, '@channel', or ''
$ads->randomMessageId();       // int, 0 when unconfigured
$ads->hasRandomMessage();      // true only when both chat_id and message_id are set
$ads->randomProbability();     // int, 0..100

$ads->isStartEnabled();
$ads->startStrategy();
$ads->startChatId();
$ads->startMessageId();
$ads->hasStartMessage();
```

### Toggle without losing configuration

```php
$ads->setRandomEnabled(false); // temporarily disable; all other fields survive
$ads->setRandomEnabled(true);  // re-enable with the previous source and probability
```

### Clear a slot entirely

```php
$ads->clearRandom();
// enabled=0, strategy='copy', chat_id='', message_id=0, probability=0

$ads->clearStart();
// enabled=0, strategy='copy', chat_id='', message_id=0
```

### Deliver

```php
$ads->sendRandomAd($userId); // internally rolls the probability; no-op if disabled
$ads->sendStartAd($userId);  // fires unconditionally when enabled + configured
```

Neither method throws. Delivery failures (the user blocked the bot, the source message was deleted, the bot is not a member of the source chat) are logged at warning level and swallowed.

### Low-level escape hatch

Use the `get`/`set` methods for keys that are not part of the two standard slots — for example, a future "ad impression cap" or a feature flag:

```php
$ads->set('ad_daily_cap', '5');
$dailyCap = (int) $ads->get('ad_daily_cap', '5');
```

Both methods operate on the same `ad_settings` table and share its in-process cache.

---

## Wiring the delivery hooks

The package does not ship any hooks. The host application decides when to call `sendRandomAd()` and `sendStartAd()`. Below are the two standard patterns.

### Random ad — after every successful handler

The framework dispatches `Events::HANDLER_EXECUTED` only after the handler completes without throwing. Wiring the random-ad listener onto that event guarantees the user always sees the handler's own reply before the ad.

```php
use Reactor\Core\EventDispatcher;
use Reactor\Events;
use AfazTech\ReactorAd\Contracts\AdManagerInterface;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(
    Events::HANDLER_EXECUTED,
    function (string $handlerClass, array $update) use ($container): void {
        // Skip handlers that already own an ad slot, and admin handlers
        // so the operator is not spammed by their own ads.
        if ($handlerClass === \App\Handlers\StartHandler::class) {
            return;
        }
        if (str_starts_with($handlerClass, 'App\\Handlers\\Admin')) {
            return;
        }

        $fromId = $update['message']['from']['id']
            ?? $update['callback_query']['from']['id']
            ?? $update['inline_query']['from']['id']
            ?? null;

        if ($fromId === null) {
            return;
        }

        try {
            $container->get(AdManagerInterface::class)->sendRandomAd((int) $fromId);
        } catch (\Throwable $e) {
            $container->get(\Reactor\Contracts\LoggerInterface::class)->warning(
                'Random ad send failed',
                ['user_id' => (int) $fromId, 'error' => $e->getMessage()]
            );
        }
    }
);
```

### Start ad — from your `/start` handler

The start ad should fire *before* the main menu. Call it early in your `StartHandler::handle()`:

```php
<?php
namespace App\Handlers;

use AfazTech\ReactorAd\Contracts\AdManagerInterface;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Text;

#[Text(name: '/start', isCommand: true, priority: 100)]
#[OnUpdate('message')]
class StartHandler extends BaseHandler
{
    public function __construct(
        // ... existing dependencies ...
        private AdManagerInterface $ads,
    ) {
        parent::__construct(/* ... */);
    }

    protected function handle(array $params = []): void
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return;
        }

        // Ad first, then the greeting.
        $this->ads->sendStartAd($userId);

        $this->showMainMenu();
    }
}
```

If your `StartHandler` runs only after gates like force-join, the ad fires only for users who clear every gate. That is usually the correct behavior.

---

## API reference

### Random ad

| Method | Returns | Description |
| --- | --- | --- |
| `isRandomEnabled()` | `bool` | Whether the slot is on. |
| `setRandomEnabled(bool)` | `void` | Toggle the slot without touching other fields. |
| `randomProbability()` | `int` | Delivered value in `[0, 100]`. |
| `setRandomProbability(int)` | `void` | Clamped to `[0, 100]`. |
| `randomStrategy()` | `string` | `'copy'` or `'forward'`. Invalid stored values fall back to `'copy'`. |
| `randomChatId()` | `string` | Numeric string, `'@username'`, or `''`. |
| `randomMessageId()` | `int` | `0` when unconfigured. |
| `hasRandomMessage()` | `bool` | True only when both `chat_id` and `message_id` are set. |
| `setRandomSource(string $strategy, int\|string $chatId, int $messageId)` | `void` | Saves the source and enables the slot. |
| `clearRandom()` | `void` | Disables and wipes every field. |
| `sendRandomAd(int $userId)` | `void` | Rolls the probability; no-op if disabled or unconfigured. |

### Start ad

| Method | Returns | Description |
| --- | --- | --- |
| `isStartEnabled()` | `bool` | Whether the slot is on. |
| `setStartEnabled(bool)` | `void` | Toggle the slot without touching other fields. |
| `startStrategy()` | `string` | `'copy'` or `'forward'`. |
| `startChatId()` | `string` | Numeric string, `'@username'`, or `''`. |
| `startMessageId()` | `int` | `0` when unconfigured. |
| `hasStartMessage()` | `bool` | True only when both `chat_id` and `message_id` are set. |
| `setStartSource(string $strategy, int\|string $chatId, int $messageId)` | `void` | Saves the source and enables the slot. |
| `clearStart()` | `void` | Disables and wipes every field. |
| `sendStartAd(int $userId)` | `void` | Fires unconditionally when enabled and configured. |

### Low-level

| Method | Returns | Description |
| --- | --- | --- |
| `get(string $key, string $default = '')` | `string` | Reads any key from `ad_settings` with in-process caching. |
| `set(string $key, string $value)` | `void` | Writes any key and refreshes the cache. |

---

## Delivery semantics

- `copy` uses `copyMessage` (no "Forwarded from" label). `forward` uses `forwardMessage` (with the label).
- A numeric `chat_id` (private chat, group, channel) is sent as an integer; a username (`@channel`) is sent as a string. The package handles the conversion automatically.
- `hasRandomMessage()` / `hasStartMessage()` require **both** a non-empty `chat_id` and a positive `message_id`. Setting only one has no effect on delivery.
- Delivery failures are logged at warning level and never propagate. The caller's flow is never interrupted.
- `randomProbability()` is clamped to `[0, 100]`. A probability of `0` short-circuits before the random roll so a disabled slot never generates random numbers.

---

## Storage

The package migration creates a dedicated `ad_settings` table:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK | |
| `key` | varchar(64) | Unique. |
| `value` | varchar(255) | Always a string; callers cast. |
| `created_at`, `updated_at` | timestamps | |

Standard keys used by `AdManager`:

```
random_ad_enabled      '0' | '1'
random_ad_strategy     'copy' | 'forward'
random_ad_chat_id      numeric string | '@username' | ''
random_ad_message_id   numeric string
random_ad_probability  '0' .. '100'

start_ad_enabled       '0' | '1'
start_ad_strategy      'copy' | 'forward'
start_ad_chat_id       numeric string | '@username' | ''
start_ad_message_id    numeric string
```

Reads are cached in-process for the lifetime of the request or CLI command. Writes refresh the cache entry immediately. There is no cross-process cache, so a second process reading the same table always sees the latest committed value.

---

## Migration from a legacy `settings` table

Applications that previously stored ad keys in a generic `settings` table keep working: the migration copies every `random_ad_*` and `start_ad_*` row into `ad_settings`. The copy is:

- **Idempotent** — keys already present in `ad_settings` are left untouched, so re-running the migration never overwrites a fresher value.
- **Schema-safe** — the legacy `settings` table is only queried when it actually has `key` and `value` columns. Applications using a differently-shaped `settings` table are silently skipped.
- **Non-destructive** — the legacy `settings` rows are left in place. They become stale once the admin edits the ad in the new UI, but the package never touches them.

After the migration you can optionally drop the legacy `random_ad_*` and `start_ad_*` rows from `settings` with a follow-up migration of your own.

---

## Rollback caveat

Do **not** roll back this migration after the application has written to `ad_settings`. The `down()` method drops the table and all its data; the legacy `settings` rows are not a substitute because they represent the pre-migration state, not the current configuration.

If you need to downgrade in production, dump `ad_settings` first:

```bash
sqlite3 database/app.sqlite "SELECT key, value FROM ad_settings" > ad_settings_backup.txt
```

Then apply the rollback, and restore from the backup after the downgrade is complete.

---

## Testing the package locally

To develop against a local checkout, add a path repository in your application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../reactor-ad",
        "options": { "symlink": true }
    }
],
"require": {
    "afaztech/reactor-ad": "@dev"
}
```

The framework must also be a path repository or a tagged release of `^0.5.0`. When the framework is checked out locally, add a `branch-alias` to its `composer.json` so `dev-main` is treated as `0.5.x-dev`:

```json
"extra": {
    "branch-alias": {
        "dev-main": "0.5.x-dev"
    }
}
```

---

## License

MIT. See [LICENSE](LICENSE).