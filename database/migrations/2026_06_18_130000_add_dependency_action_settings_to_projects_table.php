<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('dependency_actions')->nullable()->after('allow_dependency_updates');
            $table->json('custom_dependency_actions')->nullable()->after('dependency_actions');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['dependency_actions', 'custom_dependency_actions']);
        });
    }
};
