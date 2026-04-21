<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropUnique('projects_local_path_unique');
            });
        } catch (\Throwable $e) {
            // Index may already be dropped on some environments.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasDuplicates = DB::table('projects')
            ->select('local_path')
            ->groupBy('local_path')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            return;
        }

        try {
            Schema::table('projects', function (Blueprint $table): void {
                $table->unique('local_path');
            });
        } catch (\Throwable $e) {
            // Ignore if the index already exists.
        }
    }
};

