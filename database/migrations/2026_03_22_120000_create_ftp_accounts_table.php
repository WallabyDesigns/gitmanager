<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(21);
            $table->string('username');
            $table->text('password_encrypted')->nullable();
            $table->string('root_path')->nullable();
            $table->boolean('passive')->default(true);
            $table->boolean('ssl')->default(true);
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftp_accounts');
    }
};
