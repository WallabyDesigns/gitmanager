<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_issues', function (Blueprint $table) {
            $table->timestamp('last_emailed_at')->nullable()->after('last_seen_at');
            $table->index('last_emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_issues', function (Blueprint $table) {
            $table->dropIndex(['last_emailed_at']);
            $table->dropColumn('last_emailed_at');
        });
    }
};
