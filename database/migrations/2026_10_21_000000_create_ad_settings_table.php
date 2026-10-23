<?php

declare(strict_types=1);

namespace AfazTech\ReactorAd\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Database\Migrations\Migration;

/**
 * Creates the "ad_settings" key/value table used by the reactor-ad
 * package.
 *
 * Each named slot is stored as a single row whose value is a JSON
 * document:
 *
 *   key   = "slot:{name}"
 *   value = {"strategy":"copy","chat_id":"-100...","message_id":42,
 *            "probability":30,"enabled":true}
 *
 * Custom keys (feature flags, ad impression caps, ...) can share the
 * same table; the package only claims the "slot:" prefix.
 *
 * The value column is TEXT because slot payloads can grow beyond 255
 * bytes once chat_id carries a username and future fields are added.
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
                $table->text('value');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('ad_settings');
    }
}
