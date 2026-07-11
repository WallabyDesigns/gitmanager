<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_digest_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_key', 64)->index();
            $table->json('recipients');
            $table->string('category', 40)->index();
            $table->string('source_key', 191)->unique();
            $table->string('summary');
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_digest_entries');
    }
};
