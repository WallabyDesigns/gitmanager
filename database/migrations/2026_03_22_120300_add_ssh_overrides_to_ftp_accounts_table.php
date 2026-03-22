<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->string('ssh_pass_binary')->nullable()->after('password_encrypted');
            $table->string('ssh_key_path')->nullable()->after('ssh_pass_binary');
        });
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn(['ssh_pass_binary', 'ssh_key_path']);
        });
    }
};
