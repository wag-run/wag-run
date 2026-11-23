<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files that runs make or receive: downloads, form and webhook uploads,
     * and files built by Create file nodes. The bytes live on the workflow
     * files disk; nodes pass a small reference to a row here.
     */
    public function up(): void
    {
        Schema::create('workflow_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('disk');
            $table->string('path');
            $table->timestamps();

            $table->index(['execution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_files');
    }
};
