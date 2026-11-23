<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Authentication settings are identified by a UUID primary key instead
     * of an auto-incrementing one plus a key the person typed, and are named
     * by their description, which becomes required and unique in its
     * workspace.
     *
     * The table is rebuilt, since a primary key can't change type in place
     * on every database. The rebuilt table keeps its temporary name in its
     * primary key index, so rolling back rebuilds under another name. Each setting gets a UUID; one without a description
     * is described by its old key. Node fields that referred to a setting by
     * key, in drafts and in published versions, refer to its UUID instead.
     * Run history keeps the keys it recorded.
     */
    public function up(): void
    {
        Schema::create('credentials_rebuilt', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->text('config');
            $table->string('description');
            $table->timestamps();

            $table->unique(['workspace_id', 'description'], 'credentials_workspace_id_description_unique');
        });

        $ids = [];
        $taken = [];

        foreach (DB::table('credentials')->orderBy('id')->get() as $credential) {
            $id = (string) Str::uuid7();
            $description = $this->free(trim((string) $credential->description) ?: $credential->key, $taken[$credential->workspace_id] ?? [], fn (string $base, int $n) => "{$base} ({$n})");
            $taken[$credential->workspace_id][] = $description;
            $ids[$credential->workspace_id][$credential->key] = $id;

            DB::table('credentials_rebuilt')->insert([
                'id' => $id,
                'workspace_id' => $credential->workspace_id,
                'type' => $credential->type,
                'config' => $credential->config,
                'description' => $description,
                'created_at' => $credential->created_at,
                'updated_at' => $credential->updated_at,
            ]);
        }

        $this->rewriteGraphs($ids);

        Schema::drop('credentials');
        Schema::rename('credentials_rebuilt', 'credentials');
    }

    public function down(): void
    {
        Schema::create('credentials_restored', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type', 50);
            $table->text('config');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'key'], 'credentials_workspace_id_key_unique');
        });

        $keys = [];
        $taken = [];

        foreach (DB::table('credentials')->orderBy('created_at')->orderBy('id')->get() as $credential) {
            $base = Str::slug((string) $credential->description, '_') ?: 'setting';
            $base = preg_match('/^[A-Za-z_]/', $base) ? $base : 'setting_'.$base;
            $key = $this->free($base, $taken[$credential->workspace_id] ?? [], fn (string $base, int $n) => "{$base}_{$n}");
            $taken[$credential->workspace_id][] = $key;
            $keys[$credential->workspace_id][$credential->id] = $key;

            DB::table('credentials_restored')->insert([
                'workspace_id' => $credential->workspace_id,
                'key' => $key,
                'type' => $credential->type,
                'config' => $credential->config,
                'description' => $credential->description,
                'created_at' => $credential->created_at,
                'updated_at' => $credential->updated_at,
            ]);
        }

        $this->rewriteGraphs($keys);

        Schema::drop('credentials');
        Schema::rename('credentials_restored', 'credentials');
    }

    /**
     * Point each node's "credential" field (the name every authentication
     * setting field uses) through $map, per workspace, in drafts and in
     * versions.
     *
     * @param  array<int, array<string, string>>  $map  workspace id => old reference => new reference
     */
    protected function rewriteGraphs(array $map): void
    {
        foreach (DB::table('workflows')->get(['id', 'workspace_id', 'draft_graph']) as $workflow) {
            $references = $map[$workflow->workspace_id] ?? [];

            if ($references === []) {
                continue;
            }

            DB::table('workflows')->where('id', $workflow->id)->update(['draft_graph' => $this->rewrite($workflow->draft_graph, $references)]);

            foreach (DB::table('workflow_versions')->where('workflow_id', $workflow->id)->get(['id', 'graph']) as $version) {
                DB::table('workflow_versions')->where('id', $version->id)->update(['graph' => $this->rewrite($version->graph, $references)]);
            }
        }
    }

    /**
     * @param  array<string, string>  $references
     */
    protected function rewrite(string $json, array $references): string
    {
        $graph = json_decode($json, true);

        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
            return $json;
        }

        foreach ($graph['nodes'] as $index => $node) {
            $value = $node['config']['credential'] ?? null;

            if (is_string($value) && isset($references[$value])) {
                $graph['nodes'][$index]['config']['credential'] = $references[$value];
            }
        }

        return (string) json_encode($graph);
    }

    /**
     * $base, or $base with a number added by $numbered, whichever isn't in
     * $taken yet.
     *
     * @param  list<string>  $taken
     * @param  callable(string, int): string  $numbered
     */
    protected function free(string $base, array $taken, callable $numbered): string
    {
        $base = mb_substr($base, 0, 240);
        $candidate = $base;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $numbered($base, $n);
        }

        return $candidate;
    }
};
