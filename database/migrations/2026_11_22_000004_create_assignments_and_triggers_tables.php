<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('fields')->nullable();
            $table->json('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->unique(['execution_step_id', 'assignee_id']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('workflow_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');
            $table->string('type', 20);
            $table->string('key', 64)->nullable()->unique();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['workflow_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_triggers');
        Schema::dropIfExists('assignments');
    }
};
