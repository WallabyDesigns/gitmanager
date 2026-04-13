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
        Schema::create('audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 32);
            $table->string('status', 16)->default('open');
            $table->string('severity', 32)->nullable();
            $table->text('summary')->nullable();
            $table->text('fix_summary')->nullable();
            $table->unsignedInteger('found_count')->nullable();
            $table->unsignedInteger('fixed_count')->nullable();
            $table->unsignedInteger('remaining_count')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'tool']);
            $table->index(['status', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_issues');
    }
};
