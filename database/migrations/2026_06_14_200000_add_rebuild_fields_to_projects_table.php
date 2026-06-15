<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('rebuild_enabled')->default(false)->after('allow_dependency_updates');
            $table->unsignedSmallInteger('rebuild_interval_hours')->default(24)->after('rebuild_enabled');
            $table->timestamp('last_rebuild_at')->nullable()->after('rebuild_interval_hours');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['rebuild_enabled', 'rebuild_interval_hours', 'last_rebuild_at']);
        });
    }
};
