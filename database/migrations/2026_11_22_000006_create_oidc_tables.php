<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('issuer');
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('scopes')->default('openid email profile');
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_registration')->default(false);
            $table->boolean('trust_email')->default(false);
            $table->timestamps();

            $table->unique('slug');
        });

        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oidc_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();

            $table->unique(['oidc_provider_id', 'subject']);
        });

        Schema::create('oidc_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('oidc_provider_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('verification_token', 40);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_domains');
        Schema::dropIfExists('oidc_identities');
        Schema::dropIfExists('oidc_providers');
    }
};
