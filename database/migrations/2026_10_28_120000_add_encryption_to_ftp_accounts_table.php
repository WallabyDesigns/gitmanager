<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->string('encryption', 16)->default('explicit')->after('ssl');
        });

        DB::table('ftp_accounts')->where('ssl', false)->update(['encryption' => 'none']);
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn('encryption');
        });
    }
};
