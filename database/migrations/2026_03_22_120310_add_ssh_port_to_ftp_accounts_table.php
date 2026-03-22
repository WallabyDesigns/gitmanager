<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->unsignedInteger('ssh_port')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn('ssh_port');
        });
    }
};
