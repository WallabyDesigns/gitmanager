<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('permissions_locked')->default(false)->after('last_error_message');
            $table->text('permissions_issue_message')->nullable()->after('permissions_locked');
            $table->timestamp('permissions_checked_at')->nullable()->after('permissions_issue_message');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'permissions_locked',
                'permissions_issue_message',
                'permissions_checked_at',
            ]);
        });
    }
};
