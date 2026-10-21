<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real equipment and real feeds.
 *
 * A gateway on a farm posts its sensors' readings, collects the commands waiting for its farm and
 * reports what the equipment did, authenticated by its own secret (only the hash is kept). A device
 * with an endpoint is sent its commands straight away as well.
 *
 * A source says how it is fetched — an RSS or Atom feed, a CAP alert feed (the standard weather
 * services publish warnings in), or a JSON API with a field map — and what kind of item each entry
 * is, so the registry fetches it on its own schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prd_devices', function (Blueprint $t) {
            $t->string('endpoint_url', 500)->nullable();
            $t->timestampTz('secret_issued_at')->nullable();
        });
        Schema::table('prd_sources', function (Blueprint $t) {
            $t->string('adapter', 12)->nullable();                        // rss | cap | json
            $t->string('item_kind', 20)->nullable();                      // warning | outbreak | funding | price | notice
            $t->jsonb('config')->nullable();                              // field map, filters, default radius
        });
    }

    public function down(): void
    {
        Schema::table('prd_devices', fn (Blueprint $t) => $t->dropColumn(['endpoint_url', 'secret_issued_at']));
        Schema::table('prd_sources', fn (Blueprint $t) => $t->dropColumn(['adapter', 'item_kind', 'config']));
    }
};
