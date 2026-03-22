<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('ftp_account_id')->nullable()->constrained('ftp_accounts')->nullOnDelete();
            $table->string('ftp_root_path')->nullable();
            $table->boolean('ftp_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['ftp_account_id']);
            $table->dropColumn(['ftp_account_id', 'ftp_root_path', 'ftp_enabled']);
        });
    }
};
