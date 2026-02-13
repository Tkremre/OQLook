<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Default iTop');
            $table->string('itop_url');
            $table->string('auth_mode');
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('token_encrypted')->nullable();
            $table->string('connector_url')->nullable();
            $table->text('connector_bearer_encrypted')->nullable();
            $table->json('fallback_config_json')->nullable();
            $table->timestamp('last_scan_time')->nullable();
            $table->timestamps();
        });

        Schema::create('metamodel_caches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('metamodel_hash')->index();
            $table->json('payload_json');
            $table->timestamps();
            $table->unique(['connection_id', 'metamodel_hash']);
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('mode');
            $table->json('summary_json')->nullable();
            $table->json('scores_json')->nullable();
            $table->timestamps();
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->string('domain');
            $table->string('severity');
            $table->unsignedTinyInteger('impact');
            $table->unsignedInteger('affected_count')->default(0);
            $table->text('recommendation')->nullable();
            $table->text('suggested_oql')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->index(['scan_id', 'domain', 'severity']);
        });

        Schema::create('issue_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('itop_class');
            $table->string('itop_id');
            $table->string('name')->nullable();
            $table->string('link')->nullable();
            $table->timestamps();
            $table->index(['issue_id', 'itop_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_samples');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('metamodel_caches');
        Schema::dropIfExists('connections');
    }
};
