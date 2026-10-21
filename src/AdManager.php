<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd;

use AfazTech\ReactorAd\Contracts\AdManagerInterface;
use AfazTech\ReactorAd\Support\AdConfig;
use Neili\Client;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Default AdManagerInterface implementation.
 *
 * Values are stored in a dedicated "ad_settings" table (key/value
 * string rows) created by the package migration. A short in-process
 * cache keeps repeated reads within one request from hitting the
 * database; every write refreshes the cache entry.
 *
 * Delivery uses copy/forward of a source chat+message so the admin can
 * update the ad content without redeploying: change the message, keep
 * the same message_id, and the next delivery picks up the new content.
 */
class AdManager implements AdManagerInterface
{
    public const KEY_RANDOM_ENABLED     = 'random_ad_enabled';
    public const KEY_RANDOM_STRATEGY    = 'random_ad_strategy';
    public const KEY_RANDOM_CHAT_ID     = 'random_ad_chat_id';
    public const KEY_RANDOM_MESSAGE_ID  = 'random_ad_message_id';
    public const KEY_RANDOM_PROBABILITY = 'random_ad_probability';

    public const KEY_START_ENABLED    = 'start_ad_enabled';
    public const KEY_START_STRATEGY   = 'start_ad_strategy';
    public const KEY_START_CHAT_ID    = 'start_ad_chat_id';
    public const KEY_START_MESSAGE_ID = 'start_ad_message_id';

    private const VALID_STRATEGIES = ['copy', 'forward'];

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    private bool $tableVerified = false;

    public function __construct(
        private DatabaseManagerInterface $db,
        private Client $client,
        private LoggerInterface $logger,
        private AdConfig $config,
    ) {
    }

    // -----------------------------------------------------------------
    // Random ad
    // -----------------------------------------------------------------

    public function isRandomEnabled(): bool
    {
        return $this->getInt(self::KEY_RANDOM_ENABLED, 0) === 1;
    }

    public function setRandomEnabled(bool $enabled): void
    {
        $this->set(self::KEY_RANDOM_ENABLED, $enabled ? '1' : '0');
    }

    public function randomProbability(): int
    {
        return max(0, min(100, $this->getInt(self::KEY_RANDOM_PROBABILITY, 0)));
    }

    public function setRandomProbability(int $probability): void
    {
        $this->set(
            self::KEY_RANDOM_PROBABILITY,
            (string) max(0, min(100, $probability))
        );
    }

    public function randomStrategy(): string
    {
        $value = $this->get(self::KEY_RANDOM_STRATEGY, 'copy');
        return in_array($value, self::VALID_STRATEGIES, true) ? $value : 'copy';
    }

    public function randomChatId(): string
    {
        return $this->get(self::KEY_RANDOM_CHAT_ID, '');
    }

    public function randomMessageId(): int
    {
        return $this->getInt(self::KEY_RANDOM_MESSAGE_ID, 0);
    }

    public function hasRandomMessage(): bool
    {
        return $this->randomChatId() !== '' && $this->randomMessageId() > 0;
    }

    public function setRandomSource(string $strategy, int|string $chatId, int $messageId): void
    {
        $strategy = in_array($strategy, self::VALID_STRATEGIES, true) ? $strategy : 'copy';

        $this->set(self::KEY_RANDOM_STRATEGY, $strategy);
        $this->set(self::KEY_RANDOM_CHAT_ID, (string) $chatId);
        $this->set(self::KEY_RANDOM_MESSAGE_ID, (string) $messageId);
        $this->set(self::KEY_RANDOM_ENABLED, '1');
    }

    public function clearRandom(): void
    {
        $this->set(self::KEY_RANDOM_ENABLED, '0');
        $this->set(self::KEY_RANDOM_STRATEGY, 'copy');
        $this->set(self::KEY_RANDOM_CHAT_ID, '');
        $this->set(self::KEY_RANDOM_MESSAGE_ID, '0');
        $this->set(self::KEY_RANDOM_PROBABILITY, '0');
    }

    public function sendRandomAd(int $userId): void
    {
        if (!$this->isRandomEnabled() || !$this->hasRandomMessage()) {
            return;
        }

        $probability = $this->randomProbability();
        if ($probability <= 0) {
            return;
        }

        if (random_int(1, 100) > $probability) {
            return;
        }

        $this->sendAd(
            $userId,
            $this->randomStrategy(),
            $this->randomChatId(),
            $this->randomMessageId(),
            'random'
        );
    }

    // -----------------------------------------------------------------
    // Start ad
    // -----------------------------------------------------------------

    public function isStartEnabled(): bool
    {
        return $this->getInt(self::KEY_START_ENABLED, 0) === 1;
    }

    public function setStartEnabled(bool $enabled): void
    {
        $this->set(self::KEY_START_ENABLED, $enabled ? '1' : '0');
    }

    public function startStrategy(): string
    {
        $value = $this->get(self::KEY_START_STRATEGY, 'copy');
        return in_array($value, self::VALID_STRATEGIES, true) ? $value : 'copy';
    }

    public function startChatId(): string
    {
        return $this->get(self::KEY_START_CHAT_ID, '');
    }

    public function startMessageId(): int
    {
        return $this->getInt(self::KEY_START_MESSAGE_ID, 0);
    }

    public function hasStartMessage(): bool
    {
        return $this->startChatId() !== '' && $this->startMessageId() > 0;
    }

    public function setStartSource(string $strategy, int|string $chatId, int $messageId): void
    {
        $strategy = in_array($strategy, self::VALID_STRATEGIES, true) ? $strategy : 'copy';

        $this->set(self::KEY_START_STRATEGY, $strategy);
        $this->set(self::KEY_START_CHAT_ID, (string) $chatId);
        $this->set(self::KEY_START_MESSAGE_ID, (string) $messageId);
        $this->set(self::KEY_START_ENABLED, '1');
    }

    public function clearStart(): void
    {
        $this->set(self::KEY_START_ENABLED, '0');
        $this->set(self::KEY_START_STRATEGY, 'copy');
        $this->set(self::KEY_START_CHAT_ID, '');
        $this->set(self::KEY_START_MESSAGE_ID, '0');
    }

    public function sendStartAd(int $userId): void
    {
        if (!$this->isStartEnabled() || !$this->hasStartMessage()) {
            return;
        }

        $this->sendAd(
            $userId,
            $this->startStrategy(),
            $this->startChatId(),
            $this->startMessageId(),
            'start'
        );
    }

    // -----------------------------------------------------------------
    // Low-level
    // -----------------------------------------------------------------

    public function get(string $key, string $default = ''): string
    {
        $this->ensureTable();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $row = $this->db->table($this->config->getTable())
                ->where('key', $key)
                ->first();
            $value = $row ? (string) $row->value : $default;
        } catch (\Throwable $e) {
            $this->logger->warning('Ad settings read failed, using default', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);

            // Do NOT cache the fallback. Caching it would freeze the
            // default value until the process restarts, so a transient
            // DB failure would silently mask an enabled setting.
            return $default;
        }

        return $this->cache[$key] = $value;
    }

    public function set(string $key, string $value): void
    {
        $this->ensureTable();
        $table = $this->config->getTable();
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table($table)->where('key', $key)->first();
        if ($existing) {
            $this->db->table($table)->where('key', $key)->update([
                'value'      => $value,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table($table)->insert([
                'key'        => $key,
                'value'      => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->cache[$key] = $value;
    }

    private function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    private function ensureTable(): void
    {
        if ($this->tableVerified) {
            return;
        }

        $table = $this->config->getTable();
        if (!$this->db->schema()->hasTable($table)) {
            $hint = $table === 'ad_settings'
                ? "Run 'php reactor.php migrate' to create it."
                : "The default migration creates 'ad_settings', not '{$table}'. "
                  . "Provide a custom migration for a renamed table, or revert "
                  . "AdConfig::table() to the default.";

            throw new \RuntimeException(
                "Ad settings table '{$table}' does not exist. {$hint}"
            );
        }

        $this->tableVerified = true;
    }

    private function sendAd(int $userId, string $strategy, string $chatId, int $messageId, string $type): void
    {
        // A numeric chat id (private chat, group, channel) must be sent
        // as an integer; a username (@channel) stays a string.
        $fromChatId = is_numeric($chatId) ? (int) $chatId : $chatId;

        try {
            $future = $strategy === 'forward'
                ? $this->client->forwardMessage($userId, $fromChatId, $messageId)
                : $this->client->copyMessage($userId, $fromChatId, $messageId);

            $future->await();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send ad', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
