<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd\Contracts;

/**
 * Public API of the reactor-ad package.
 *
 * Named slots: instead of a fixed set of pre-defined slots, the host
 * application creates any number of slots by name and configures each
 * one independently. A slot carries:
 *
 *   - strategy     : "copy" or "forward"
 *   - chat_id      : numeric chat id or "@username"
 *   - message_id   : source message id
 *   - probability  : 0..100, used only by sendRandom()
 *   - enabled      : master on/off switch
 *
 * Delivery is entirely up to the host application. Two methods are
 * exposed:
 *
 *   - send($name, $userId)        deterministic; ignores probability
 *   - sendRandom($name, $userId)  probabilistic; respects probability
 *
 * Both respect the enabled flag and require a configured source. The
 * package ships no handler, no middleware and no event listener.
 *
 * Storage lives in the "ad_settings" table created by the package
 * migration. Run "php reactor.php migrate" after installation.
 */
interface AdManagerInterface
{
    // -----------------------------------------------------------------
    // Slot lifecycle
    // -----------------------------------------------------------------

    /**
     * Create a slot with default (empty, disabled) configuration.
     *
     * Idempotent: creating a slot that already exists is a no-op, so
     * callers do not need to check hasSlot() first. Slot names must
     * match /^[A-Za-z0-9_-]{1,32}$/.
     *
     * @throws \InvalidArgumentException When the name is invalid.
     */
    public function createSlot(string $name): void;

    /**
     * Permanently remove a slot and its configuration.
     */
    public function deleteSlot(string $name): void;

    /**
     * Whether the named slot exists.
     */
    public function hasSlot(string $name): bool;

    /**
     * List the names of every existing slot.
     *
     * @return array<int, string>
     */
    public function listSlots(): array;

    // -----------------------------------------------------------------
    // Slot configuration
    // -----------------------------------------------------------------

    /**
     * Set the source message for a slot and enable it.
     *
     * "strategy" must be "copy" or "forward"; anything else falls back
     * to "copy". "chatId" may be a numeric chat id (as int) or a
     * username string (as string starting with "@"). "messageId" must
     * be a positive integer to be deliverable.
     *
     * Enabling on save matches the intuitive flow: the admin configures
     * a slot because they want it to fire. Use setEnabled(false)
     * afterwards to turn it off without losing the source.
     */
    public function setSource(string $name, string $strategy, int|string $chatId, int $messageId): void;

    /**
     * Set the delivery probability of a slot, in the range 0..100.
     *
     * 0 means "never", 100 means "on every sendRandom() call". Values
     * are clamped to the range. This value is only consulted by
     * sendRandom(); send() always delivers regardless of probability.
     */
    public function setProbability(string $name, int $probability): void;

    /**
     * Turn a slot on or off.
     *
     * The enabled flag is the master switch: both send() and
     * sendRandom() return false when it is off.
     */
    public function setEnabled(string $name, bool $enabled): void;

    /**
     * Reset every configuration field of a slot to its default value
     * (empty source, strategy "copy", probability 100, disabled) while
     * keeping the slot itself.
     */
    public function clearSlot(string $name): void;

    // -----------------------------------------------------------------
    // Slot state
    // -----------------------------------------------------------------

    public function isEnabled(string $name): bool;

    /**
     * Delivery strategy of the slot: "copy" or "forward".
     */
    public function getStrategy(string $name): string;

    /**
     * Source chat id as stored (numeric string or "@username"), or ""
     * when unconfigured.
     */
    public function getChatId(string $name): string;

    /**
     * Source message id, or 0 when unconfigured.
     */
    public function getMessageId(string $name): int;

    /**
     * Delivery probability of the slot, in the range 0..100.
     */
    public function getProbability(string $name): int;

    /**
     * True when both a non-empty chat id and a positive message id are
     * present. The slot is deliverable only when this returns true.
     */
    public function hasMessage(string $name): bool;

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    /**
     * Deliver the slot to the given user unconditionally.
     *
     * Ignores probability. Requires the slot to be enabled and
     * configured. Returns true on successful delivery, false when the
     * slot is disabled, unconfigured, or delivery failed. Delivery
     * failures are logged at warning level and never propagate.
     */
    public function send(string $name, int $userId): bool;

    /**
     * Deliver the slot to the given user only when the random dice
     * passes against the slot's probability.
     *
     * Returns true on successful delivery, false when the slot is
     * disabled, unconfigured, the probability roll failed, or delivery
     * failed. Delivery failures are logged at warning level and never
     * propagate.
     */
    public function sendRandom(string $name, int $userId): bool;

    // -----------------------------------------------------------------
    // Low-level escape hatch
    // -----------------------------------------------------------------

    /**
     * Read any key from the ad_settings table, with in-process caching.
     * Custom callers can use this to store their own feature flags
     * alongside the slot keys. Prefer the typed methods above for
     * normal slot work.
     */
    public function get(string $key, string $default = ''): string;

    /**
     * Write any key into the ad_settings table and refresh the cache.
     */
    public function set(string $key, string $value): void;
}
