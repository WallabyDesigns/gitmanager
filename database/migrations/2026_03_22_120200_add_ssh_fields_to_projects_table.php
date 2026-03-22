<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('ssh_enabled')->default(false);
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_root_path')->nullable();
            $table->text('ssh_commands')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['ssh_enabled', 'ssh_port', 'ssh_root_path', 'ssh_commands']);
        });
    }
};
