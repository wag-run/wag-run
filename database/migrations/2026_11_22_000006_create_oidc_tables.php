<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
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

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oidc_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();

            $table->unique(['oidc_provider_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
        Schema::dropIfExists('oidc_providers');
    }
};
