<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->string('ftp_test_signature', 64)->nullable()->after('ftp_tested_at');
            $table->string('ssh_test_signature', 64)->nullable()->after('ssh_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn(['ftp_test_signature', 'ssh_test_signature']);
        });
    }
};
