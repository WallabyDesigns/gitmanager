<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workflows')) {
            return;
        }

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('action');
            $table->string('status');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->boolean('include_owner')->default(true);
            $table->text('recipients')->nullable();
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamps();

            $table->index(['action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
