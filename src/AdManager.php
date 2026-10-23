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
 * Slot data is stored as JSON in a single key/value row per slot:
 *
 *   key   = "slot:{name}"
 *   value = {"strategy":"copy","chat_id":"-100...","message_id":42,
 *            "probability":30,"enabled":true}
 *
 * A short in-process cache keeps repeated reads within one request
 * from hitting the database; every write refreshes the cache entry for
 * that key.
 *
 * Delivery uses copy/forward of the stored source message so the
 * operator can edit the source content without redeploying: change the
 * message text in place, keep the same message_id, and the next
 * delivery picks up the new content.
 */
class AdManager implements AdManagerInterface
{
    private const SLOT_PREFIX = 'slot:';
    private const VALID_STRATEGIES = ['copy', 'forward'];
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

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
    // Slot lifecycle
    // -----------------------------------------------------------------

    public function createSlot(string $name): void
    {
        $this->validateName($name);

        if ($this->hasSlot($name)) {
            return;
        }

        $this->saveSlot($name, $this->defaultSlot());
    }

    public function deleteSlot(string $name): void
    {
        $this->validateName($name);
        $this->ensureTable();

        $key = self::SLOT_PREFIX . $name;

        try {
            $this->db->table($this->config->getTable())
                ->where('key', $key)
                ->delete();
        } catch (\Throwable $e) {
            $this->logger->warning('Ad slot delete failed', [
                'slot'  => $name,
                'error' => $e->getMessage(),
            ]);
        }

        unset($this->cache[$key]);
    }

    public function hasSlot(string $name): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return false;
        }

        return $this->loadSlot($name) !== null;
    }

    public function listSlots(): array
    {
        $this->ensureTable();

        try {
            $rows = $this->db->table($this->config->getTable())
                ->where('key', 'like', self::SLOT_PREFIX . '%')
                ->pluck('key')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $slots = [];
        $prefixLen = strlen(self::SLOT_PREFIX);
        foreach ($rows as $key) {
            $slots[] = substr((string) $key, $prefixLen);
        }

        return $slots;
    }

    // -----------------------------------------------------------------
    // Slot configuration
    // -----------------------------------------------------------------

    public function setSource(string $name, string $strategy, int|string $chatId, int $messageId): void
    {
        $this->validateName($name);

        $slot = $this->loadSlot($name) ?? $this->defaultSlot();
        $slot['strategy']   = in_array($strategy, self::VALID_STRATEGIES, true) ? $strategy : 'copy';
        $slot['chat_id']    = (string) $chatId;
        $slot['message_id'] = $messageId;
        $slot['enabled']    = true;

        $this->saveSlot($name, $slot);
    }

    public function setProbability(string $name, int $probability): void
    {
        $this->validateName($name);

        $slot = $this->loadSlot($name) ?? $this->defaultSlot();
        $slot['probability'] = max(0, min(100, $probability));

        $this->saveSlot($name, $slot);
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->validateName($name);

        $slot = $this->loadSlot($name) ?? $this->defaultSlot();
        $slot['enabled'] = $enabled;

        $this->saveSlot($name, $slot);
    }

    public function clearSlot(string $name): void
    {
        $this->validateName($name);
        $this->saveSlot($name, $this->defaultSlot());
    }

    // -----------------------------------------------------------------
    // Slot state
    // -----------------------------------------------------------------

    public function isEnabled(string $name): bool
    {
        $slot = $this->loadSlot($name);
        return $slot !== null && ($slot['enabled'] ?? false) === true;
    }

    public function getStrategy(string $name): string
    {
        $slot = $this->loadSlot($name);
        $strategy = (string) ($slot['strategy'] ?? 'copy');
        return in_array($strategy, self::VALID_STRATEGIES, true) ? $strategy : 'copy';
    }

    public function getChatId(string $name): string
    {
        $slot = $this->loadSlot($name);
        return (string) ($slot['chat_id'] ?? '');
    }

    public function getMessageId(string $name): int
    {
        $slot = $this->loadSlot($name);
        return (int) ($slot['message_id'] ?? 0);
    }

    public function getProbability(string $name): int
    {
        $slot = $this->loadSlot($name);
        return max(0, min(100, (int) ($slot['probability'] ?? 100)));
    }

    public function hasMessage(string $name): bool
    {
        return $this->getChatId($name) !== '' && $this->getMessageId($name) > 0;
    }

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    public function send(string $name, int $userId): bool
    {
        return $this->deliver($name, $userId);
    }

    public function sendRandom(string $name, int $userId): bool
    {
        if (!$this->isEnabled($name)) {
            return false;
        }

        if (!$this->hasMessage($name)) {
            return false;
        }

        $probability = $this->getProbability($name);
        if ($probability <= 0) {
            return false;
        }

        if (random_int(1, 100) > $probability) {
            return false;
        }

        return $this->deliver($name, $userId);
    }

    // -----------------------------------------------------------------
    // Low-level escape hatch
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

            // Do not cache the fallback: a transient DB failure must
            // not freeze the default until the process restarts.
            return $default;
        }

        return $this->cache[$key] = $value;
    }

    public function set(string $key, string $value): void
    {
        $this->ensureTable();
        $table = $this->config->getTable();
        $now = date('Y-m-d H:i:s');

        try {
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
        } catch (\Throwable $e) {
            $this->logger->warning('Ad settings write failed', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->cache[$key] = $value;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function validateName(string $name): void
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'Invalid slot name. Must match ' . self::NAME_PATTERN . ', got: ' . $name
            );
        }
    }

    /**
     * @return array{strategy: string, chat_id: string, message_id: int, probability: int, enabled: bool}
     */
    private function defaultSlot(): array
    {
        return [
            'strategy'    => 'copy',
            'chat_id'     => '',
            'message_id'  => 0,
            'probability' => 100,
            'enabled'     => false,
        ];
    }

    /**
     * Load and decode a slot. Missing keys are filled in with defaults
     * so the caller can trust the returned shape.
     *
     * @return array{strategy: string, chat_id: string, message_id: int, probability: int, enabled: bool}|null
     */
    private function loadSlot(string $name): ?array
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $this->ensureTable();
        $key = self::SLOT_PREFIX . $name;

        try {
            $row = $this->db->table($this->config->getTable())->where('key', $key)->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $decoded = json_decode((string) $row->value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_merge($this->defaultSlot(), $decoded);
    }

    private function saveSlot(string $name, array $slot): void
    {
        $key = self::SLOT_PREFIX . $name;
        $value = json_encode($slot, JSON_UNESCAPED_UNICODE);
        $this->set($key, $value);
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
                  . 'Provide a custom migration for a renamed table, or revert '
                  . 'AdConfig::table() to the default.';

            throw new \RuntimeException(
                "Ad settings table '{$table}' does not exist. {$hint}"
            );
        }

        $this->tableVerified = true;
    }

    private function deliver(string $name, int $userId): bool
    {
        if (!$this->isEnabled($name)) {
            $this->logger->debug('Ad delivery skipped: slot is disabled', [
                'slot'    => $name,
                'user_id' => $userId,
            ]);
            return false;
        }

        if (!$this->hasMessage($name)) {
            $this->logger->debug('Ad delivery skipped: slot has no source configured', [
                'slot'    => $name,
                'user_id' => $userId,
            ]);
            return false;
        }

        $strategy   = $this->getStrategy($name);
        $chatId     = $this->getChatId($name);
        $messageId  = $this->getMessageId($name);

        // A numeric chat id (private chat, group, channel) must be sent
        // as an integer; a username (@channel) stays a string.
        $fromChatId = is_numeric($chatId) ? (int) $chatId : $chatId;

        try {
            $future = $strategy === 'forward'
                ? $this->client->forwardMessage($userId, $fromChatId, $messageId)
                : $this->client->copyMessage($userId, $fromChatId, $messageId);

            $future->await();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send ad', [
                'user_id' => $userId,
                'slot'    => $name,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }
}
