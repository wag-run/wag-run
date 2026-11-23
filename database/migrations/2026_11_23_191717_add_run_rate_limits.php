<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many runs a workflow may start in a minute: an organization-wide
     * default, which a workflow can override. Runs past it are cancelled,
     * which slows down loops that leave the app, such as a workflow whose
     * action fires its own trigger.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_runs_per_minute')->default(60)->after('max_chain_depth');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_runs_per_minute')->nullable()->after('retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('max_runs_per_minute');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('max_runs_per_minute');
        });
    }
};
