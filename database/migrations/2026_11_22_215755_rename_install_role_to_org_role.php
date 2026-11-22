<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->renameColumn('install_role', 'org_role');
        });

        $this->renameAuditKey('install_role', 'org_role');
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->renameColumn('org_role', 'install_role');
        });

        $this->renameAuditKey('org_role', 'install_role');
    }

    /**
     * Sent invitations record the organization role in their audit details.
     */
    protected function renameAuditKey(string $from, string $to): void
    {
        DB::table('audit_logs')->where('action', 'invitation.sent')->where('metadata', 'like', "%\"{$from}\"%")->orderBy('id')->each(function (object $row) use ($from, $to) {
            $metadata = json_decode((string) $row->metadata, true);

            if (! is_array($metadata) || ! array_key_exists($from, $metadata)) {
                return;
            }

            $metadata = [$to => $metadata[$from], ...array_diff_key($metadata, [$from => true])];

            DB::table('audit_logs')->where('id', $row->id)->update(['metadata' => json_encode($metadata)]);
        });
    }
};
