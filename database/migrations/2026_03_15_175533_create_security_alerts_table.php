<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_alert_id');
            $table->string('state');
            $table->string('severity')->nullable();
            $table->string('package_name')->nullable();
            $table->string('ecosystem')->nullable();
            $table->string('manifest_path')->nullable();
            $table->string('advisory_summary')->nullable();
            $table->string('advisory_url')->nullable();
            $table->string('html_url')->nullable();
            $table->string('fixed_in')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->timestamp('alert_created_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'github_alert_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
