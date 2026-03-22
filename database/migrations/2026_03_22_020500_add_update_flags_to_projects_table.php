<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('updates_available')->default(false)->after('last_error_message');
            $table->timestamp('updates_checked_at')->nullable()->after('updates_available');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['updates_available', 'updates_checked_at']);
        });
    }
};
