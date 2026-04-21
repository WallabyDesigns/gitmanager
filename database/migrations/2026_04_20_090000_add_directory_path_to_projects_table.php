<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'directory_path')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('directory_path')->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'directory_path')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('directory_path');
        });
    }
};
