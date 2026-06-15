<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('stopped'); // stopped|starting|running|crashed
            $table->string('start_command')->default('npm start');
            $table->unsignedInteger('port')->nullable();
            $table->unsignedBigInteger('pid')->nullable();
            $table->boolean('auto_restart')->default(true);
            $table->unsignedInteger('crash_count')->default(0);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_stopped_at')->nullable();
            $table->timestamp('last_crashed_at')->nullable();
            $table->timestamps();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_processes');
    }
};
