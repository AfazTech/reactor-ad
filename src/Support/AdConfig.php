<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd\Support;

/**
 * Fluent configuration for the reactor-ad package.
 *
 * The only tunable is the storage table name. The default,
 * "ad_settings", matches the package migration and should not be
 * changed unless the host application provides its own table.
 *
 * Example override in a service provider:
 *
 *   $container->singleton(AdConfig::class, fn () => (new AdConfig())
 *       ->table('custom_ad_settings'));
 */
final class AdConfig
{
    private string $table = 'ad_settings';

    public function table(string $table): self
    {
        $trimmed = trim($table);
        $this->table = $trimmed !== '' ? $trimmed : 'ad_settings';
        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }
}
