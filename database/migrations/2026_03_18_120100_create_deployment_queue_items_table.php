<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->default('deploy');
            $table->json('payload')->nullable();
            $table->string('status')->default('queued');
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_queue_items');
    }
};
