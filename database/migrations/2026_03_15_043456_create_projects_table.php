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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('repo_url')->nullable();
            $table->string('local_path')->unique();
            $table->string('default_branch')->default('main');
            $table->boolean('auto_deploy')->default(false);
            $table->string('health_url')->nullable();
            $table->string('health_status')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->string('last_deployed_hash')->nullable();
            $table->text('last_error_message')->nullable();
            $table->boolean('run_composer_install')->default(true);
            $table->boolean('run_npm_install')->default(false);
            $table->boolean('run_build_command')->default(false);
            $table->string('build_command')->default('npm run build');
            $table->boolean('run_test_command')->default(false);
            $table->string('test_command')->default('php artisan test');
            $table->boolean('allow_dependency_updates')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
