<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_updates', function (Blueprint $table) {
            if (! Schema::hasColumn('app_updates', 'action')) {
                $table->string('action')->default('self_update')->after('triggered_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_updates', function (Blueprint $table) {
            if (Schema::hasColumn('app_updates', 'action')) {
                $table->dropColumn('action');
            }
        });
    }
};
