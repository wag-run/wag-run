<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('namespace', 36)->default('');
            $table->string('key', 191);
            $table->longText('value');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workspace_id', 'namespace', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_entries');
    }
};
