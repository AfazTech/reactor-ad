<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd\Contracts;

/**
 * Public API of the reactor-ad package.
 *
 * Two independent ad slots are exposed:
 *
 *   - Random ad: sent after a user update with a configurable
 *     probability (0..100). The host application decides when to call
 *     sendRandomAd() (typically from a HANDLER_EXECUTED listener).
 *
 *   - Start ad: sent once per /start flow, before the greeting. The
 *     host application calls sendStartAd() from its own /start
 *     handler.
 *
 * Both slots share the same shape: strategy ("copy" or "forward"),
 * source chat id, source message id, and an on/off flag. Random ads
 * additionally carry a probability.
 *
 * The package performs no UI and no automatic hooks. Every read/write
 * and every delivery is explicit so the host application can decide
 * where and when ads fire.
 *
 * Storage lives in the "ad_settings" table created by the package
 * migration. Run "php reactor.php migrate" after installation.
 */
interface AdManagerInterface
{
    // -----------------------------------------------------------------
    // Random ad lifecycle
    // -----------------------------------------------------------------

    public function isRandomEnabled(): bool;

    public function setRandomEnabled(bool $enabled): void;

    /**
     * Probability of delivery for one update, in the range 0..100.
     * 0 means "never", 100 means "on every update".
     */
    public function randomProbability(): int;

    public function setRandomProbability(int $probability): void;

    /**
     * Delivery strategy for the random slot: "copy" or "forward".
     */
    public function randomStrategy(): string;

    /**
     * Source chat id as stored (numeric string, e.g. "-1001234567890",
     * or a username string, e.g. "@channel"). Empty when unconfigured.
     */
    public function randomChatId(): string;

    /**
     * Source message id. 0 when unconfigured.
     */
    public function randomMessageId(): int;

    /**
     * True when both a source chat id and a positive message id are
     * present. The slot is deliverable only when this returns true.
     */
    public function hasRandomMessage(): bool;

    /**
     * Save a complete random-ad configuration in a single call. Also
     * flips the enabled flag to true so that a successful capture
     * immediately activates the slot (matches the historical admin UI
     * behavior).
     *
     * @param string     $strategy  "copy" or "forward"; anything else
     *                              falls back to "copy".
     * @param int|string $chatId    Numeric chat id or username string.
     * @param int        $messageId Source message id (> 0).
     */
    public function setRandomSource(string $strategy, int|string $chatId, int $messageId): void;

    /**
     * Clear the random slot entirely: disable, reset strategy to
     * "copy", clear chat_id and message_id, reset probability to 0.
     */
    public function clearRandom(): void;

    /**
     * Roll the probability dice and, when it succeeds, deliver the
     * random ad to $userId. Never throws — delivery failures are
     * logged at warning level so the caller's flow is not interrupted.
     */
    public function sendRandomAd(int $userId): void;

    // -----------------------------------------------------------------
    // Start ad lifecycle
    // -----------------------------------------------------------------

    public function isStartEnabled(): bool;

    public function setStartEnabled(bool $enabled): void;

    /**
     * Delivery strategy for the start slot: "copy" or "forward".
     */
    public function startStrategy(): string;

    public function startChatId(): string;

    public function startMessageId(): int;

    public function hasStartMessage(): bool;

    /**
     * Save a complete start-ad configuration in a single call. Also
     * flips the enabled flag to true.
     */
    public function setStartSource(string $strategy, int|string $chatId, int $messageId): void;

    /**
     * Clear the start slot entirely (no probability to reset here).
     */
    public function clearStart(): void;

    /**
     * Deliver the start ad to $userId. Never throws — failures are
     * logged at warning level.
     */
    public function sendStartAd(int $userId): void;

    // -----------------------------------------------------------------
    // Low-level escape hatch
    // -----------------------------------------------------------------

    /**
     * Read any key from the ad_settings table, with in-process caching.
     * Use this for custom keys that are not part of the two standard
     * slots (e.g. feature flags). Standard callers should use the
     * typed methods above.
     */
    public function get(string $key, string $default = ''): string;

    /**
     * Write any key into the ad_settings table and refresh the cache.
     */
    public function set(string $key, string $value): void;
}
