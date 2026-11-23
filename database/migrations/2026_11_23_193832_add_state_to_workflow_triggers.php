<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a trigger that listens for another app's events keeps between
     * checks: the events a polling trigger has already seen, or the webhook
     * a subscribed trigger registered and its signing secret. Encrypted,
     * because of the secret.
     */
    public function up(): void
    {
        Schema::table('workflow_triggers', function (Blueprint $table) {
            $table->text('state')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_triggers', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
