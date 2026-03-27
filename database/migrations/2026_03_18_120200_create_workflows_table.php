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
            $table->boolean('enabled')->default(true);
            $table->string('trigger')->default('deploy_success');
            $table->string('channel')->default('email');
            $table->text('recipients')->nullable();
            $table->boolean('include_owner')->default(true);
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
