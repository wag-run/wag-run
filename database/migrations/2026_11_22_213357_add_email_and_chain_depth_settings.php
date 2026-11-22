<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('mail')->nullable()->after('settings');
            $table->unsignedSmallInteger('max_chain_depth')->default(10)->after('mail');
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('chain_depth')->default(1)->after('parent_step_id');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->unique(['execution_step_id', 'assignee_id']);
            $table->dropUnique(['execution_step_id']);
        });

        $this->convertApprovalNodes('workflows', 'draft_graph');
        $this->convertApprovalNodes('workflow_versions', 'graph');
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['mail', 'max_chain_depth']);
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->dropColumn('chain_depth');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->unique('execution_step_id');
            $table->dropUnique(['execution_step_id', 'assignee_id']);
        });
    }

    /**
     * Approval nodes used to take one "assignee"; they now take a list of
     * "approvers", each with their own assignment on the step.
     */
    protected function convertApprovalNodes(string $table, string $column): void
    {
        DB::table($table)->where($column, 'like', '%core.approval%')->orderBy('id')->each(function (object $row) use ($table, $column) {
            $graph = json_decode($row->{$column}, true);

            if (! is_array($graph)) {
                return;
            }

            foreach ($graph['nodes'] ?? [] as $index => $node) {
                if (($node['type'] ?? null) === 'core.approval' && array_key_exists('assignee', $node['config'] ?? [])) {
                    $graph['nodes'][$index]['config']['approvers'] = [(int) $node['config']['assignee']];
                    unset($graph['nodes'][$index]['config']['assignee']);
                }
            }

            DB::table($table)->where('id', $row->id)->update([$column => json_encode($graph)]);
        });
    }
};
