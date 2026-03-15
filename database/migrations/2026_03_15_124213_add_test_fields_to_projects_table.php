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
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'run_test_command')) {
                $table->boolean('run_test_command')->default(false);
            }

            if (! Schema::hasColumn('projects', 'test_command')) {
                $table->string('test_command')->default('php artisan test');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'run_test_command')) {
                $table->dropColumn('run_test_command');
            }

            if (Schema::hasColumn('projects', 'test_command')) {
                $table->dropColumn('test_command');
            }
        });
    }
};
