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
            $table->string('key', 100);
            $table->string('type', 20);
            $table->boolean('is_collection')->default(false);
            $table->boolean('read_only')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('store_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_entry_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 191)->default('');
            $table->longText('value');
            $table->timestamps();

            $table->unique(['store_entry_id', 'item_key']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_values');
        Schema::dropIfExists('store_entries');
    }
};
