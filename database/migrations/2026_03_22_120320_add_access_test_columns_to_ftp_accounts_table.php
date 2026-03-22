<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->string('ftp_test_status')->nullable()->after('timeout');
            $table->text('ftp_test_message')->nullable()->after('ftp_test_status');
            $table->timestamp('ftp_tested_at')->nullable()->after('ftp_test_message');
            $table->string('ssh_test_status')->nullable()->after('ftp_tested_at');
            $table->text('ssh_test_message')->nullable()->after('ssh_test_status');
            $table->timestamp('ssh_tested_at')->nullable()->after('ssh_test_message');
        });
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'ftp_test_status',
                'ftp_test_message',
                'ftp_tested_at',
                'ssh_test_status',
                'ssh_test_message',
                'ssh_tested_at',
            ]);
        });
    }
};
