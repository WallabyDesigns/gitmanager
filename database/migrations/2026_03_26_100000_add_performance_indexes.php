<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->index(['project_id', 'status'], 'idx_deployments_project_status');
            $table->index(['status', 'started_at'], 'idx_deployments_status_started');
            $table->index(['project_id', 'action', 'status'], 'idx_deployments_project_action_status');
        });

        Schema::table('deployment_queue_items', function (Blueprint $table) {
            $table->index(['status', 'position'], 'idx_queue_status_position');
            $table->index(['project_id', 'status', 'action'], 'idx_queue_project_status_action');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex('idx_deployments_project_status');
            $table->dropIndex('idx_deployments_status_started');
            $table->dropIndex('idx_deployments_project_action_status');
        });

        Schema::table('deployment_queue_items', function (Blueprint $table) {
            $table->dropIndex('idx_queue_status_position');
            $table->dropIndex('idx_queue_project_status_action');
        });
    }
};
