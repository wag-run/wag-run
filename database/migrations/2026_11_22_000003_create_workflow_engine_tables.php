<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('concurrency', 20)->default('allow');
            $table->unsignedInteger('retention_days')->nullable();
            $table->json('draft_graph');
            $table->timestamp('draft_updated_at')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->json('graph');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workflow_id', 'number']);
        });

        Schema::create('executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('trigger_type', 30);
            $table->string('trigger_node_id')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('parent_step_id')->nullable()->index();
            $table->unsignedSmallInteger('chain_depth')->default(1);
            $table->boolean('is_test')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['workspace_id', 'finished_at']);
        });

        Schema::create('execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('execution_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');
            $table->string('node_type');
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedBigInteger('retry_of_id')->nullable();
            $table->unsignedBigInteger('previous_step_id')->nullable();
            $table->string('entry_port', 50)->nullable();
            $table->string('scope_key')->default('');
            $table->string('status', 20);
            $table->string('wait_type', 20)->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->json('state')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('output_ports')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->string('claim_token', 36)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['execution_id', 'status']);
            $table->index(['execution_id', 'node_id', 'scope_key']);
            $table->index(['status', 'available_at']);
            $table->index(['status', 'resume_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_steps');
        Schema::dropIfExists('executions');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
