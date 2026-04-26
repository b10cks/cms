<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_saml_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('idp_entity_id', 512);
            $table->string('sso_url', 1024);
            $table->string('slo_url', 1024)->nullable();
            $table->text('idp_x509_cert');
            $table->text('sp_x509_cert')->nullable();
            $table->text('sp_private_key')->nullable();
            $table->string('name_id_format', 255)->default('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
            $table->json('attribute_mapping');
            $table->string('role_attribute', 255)->nullable();
            $table->json('role_mapping')->nullable();
            $table->string('default_role', 80)->default('member');
            $table->boolean('allow_jit')->default(true);
            $table->boolean('strict')->default(true);
            $table->boolean('sign_authn_requests')->default(false);
            $table->boolean('sign_logout_requests')->default(false);
            $table->boolean('want_assertions_signed')->default(true);
            $table->boolean('want_messages_signed')->default(false);
            $table->boolean('want_assertions_encrypted')->default(false);
            $table->string('digest_algorithm', 255)->default('http://www.w3.org/2001/04/xmlenc#sha256');
            $table->string('signature_algorithm', 255)->default('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique('team_id');
            $table->index(['enabled', 'team_id']);
        });

        Schema::create('user_saml_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_saml_provider_id')->constrained('team_saml_providers')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('external_id', 512);
            $table->string('name_id', 512)->nullable();
            $table->string('session_index', 512)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['team_saml_provider_id', 'external_id'], 'saml_provider_external_unique');
            $table->unique(['team_saml_provider_id', 'user_id'], 'saml_provider_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_saml_identities');
        Schema::dropIfExists('team_saml_providers');
    }
};
