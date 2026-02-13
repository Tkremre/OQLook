<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_object_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('itop_class');
            $table->string('issue_code');
            $table->string('itop_id');
            $table->string('domain')->nullable();
            $table->string('title')->nullable();
            $table->string('object_name')->nullable();
            $table->string('object_link')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['connection_id', 'itop_class', 'issue_code', 'itop_id'],
                'issue_obj_ack_unique_scope'
            );
            $table->index(['connection_id', 'issue_code'], 'issue_obj_ack_connection_code_idx');
            $table->index(['connection_id', 'itop_class'], 'issue_obj_ack_connection_class_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_object_acknowledgements');
    }
};

