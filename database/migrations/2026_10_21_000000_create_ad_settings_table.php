<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Database\Migrations\Migration;

/**
 * Creates the "ad_settings" key/value table used by the reactor-ad
 * package.
 *
 * When upgrading an existing application that used the legacy
 * "settings" table for ad keys (random_ad_*, start_ad_*), this
 * migration copies those rows into the new table so the admin's
 * configured ad does not disappear on the upgrade.
 *
 * The copy is per-key and idempotent: keys already present in
 * ad_settings are left untouched. That makes the migration safe to
 * re-run and prevents re-copying stale values after a
 * rollback + re-migrate cycle.
 *
 * Schema safety: the legacy "settings" table is only queried when it
 * has both a "key" and a "value" column, so the migration is harmless
 * on applications that use a "settings" table with a different shape.
 *
 * NOTE: this migration should not be rolled back after the app has
 * already written to ad_settings in production. A rollback drops the
 * table and its data; the only remaining source of truth would be the
 * (now stale) legacy "settings" rows.
 */
class CreateAdSettingsTable extends Migration
{
    public function __construct(DatabaseManagerInterface $db)
    {
        parent::__construct($db);
    }

    public function up(): void
    {
        if (!$this->db->schema()->hasTable('ad_settings')) {
            $this->db->schema()->create('ad_settings', function ($table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('value', 255);
                $table->timestamps();
            });
        }

        $this->copyFromLegacySettings();
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('ad_settings');
    }

    private function copyFromLegacySettings(): void
    {
        if (!$this->db->schema()->hasTable('settings')) {
            return;
        }

        // Verify the legacy table actually has the expected columns.
        // If another application uses a "settings" table with a
        // different schema, the copy is silently skipped rather than
        // failing the whole migration.
        try {
            $columns = $this->db->schema()->getColumnListing('settings');
        } catch (\Throwable $e) {
            return;
        }

        if (!in_array('key', $columns, true) || !in_array('value', $columns, true)) {
            return;
        }

        try {
            $rows = $this->db->table('settings')
                ->where('key', 'like', 'random_ad_%')
                ->orWhere('key', 'like', 'start_ad_%')
                ->get();
        } catch (\Throwable $e) {
            return;
        }

        if (count($rows) === 0) {
            return;
        }

        $existing = array_map(
            'strval',
            $this->db->table('ad_settings')->pluck('key')->toArray()
        );
        $existing = array_flip($existing);

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $key = (string) ($row->key ?? '');
            if ($key === '' || isset($existing[$key])) {
                continue;
            }

            $this->db->table('ad_settings')->insert([
                'key'        => $key,
                'value'      => (string) ($row->value ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
