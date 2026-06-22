<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('installed_version')->nullable();
            $table->string('latest_version')->nullable();
            $table->string('status')->default('not_installed');
            $table->boolean('auto_update')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
