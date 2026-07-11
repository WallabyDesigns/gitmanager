<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('latest_remote_hash')->nullable()->after('updates_checked_at');
            $table->string('auto_deploy_blocked_hash')->nullable()->after('latest_remote_hash');
            $table->timestamp('auto_deploy_blocked_at')->nullable()->after('auto_deploy_blocked_hash');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'latest_remote_hash',
                'auto_deploy_blocked_hash',
                'auto_deploy_blocked_at',
            ]);
        });
    }
};
