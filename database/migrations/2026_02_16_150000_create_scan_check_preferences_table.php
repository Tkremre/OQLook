<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_check_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('issue_code', 120)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('severity_override', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_check_preferences');
    }
};
