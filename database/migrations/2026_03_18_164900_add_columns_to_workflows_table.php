<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflows')) {
            return;
        }

        Schema::table('workflows', function (Blueprint $table) {
            if (! Schema::hasColumn('workflows', 'action')) {
                $table->string('action')->default('deploy');
            }
            if (! Schema::hasColumn('workflows', 'status')) {
                $table->string('status')->default('success');
            }
            if (! Schema::hasColumn('workflows', 'channel')) {
                $table->string('channel')->default('email');
            }
            if (! Schema::hasColumn('workflows', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
            if (! Schema::hasColumn('workflows', 'include_owner')) {
                $table->boolean('include_owner')->default(true);
            }
            if (! Schema::hasColumn('workflows', 'recipients')) {
                $table->text('recipients')->nullable();
            }
            if (! Schema::hasColumn('workflows', 'webhook_url')) {
                $table->text('webhook_url')->nullable();
            }
            if (! Schema::hasColumn('workflows', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable();
            }
            if (! Schema::hasColumn('workflows', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflows')) {
            return;
        }

        Schema::table('workflows', function (Blueprint $table) {
            $columns = [
                'action',
                'status',
                'channel',
                'enabled',
                'include_owner',
                'recipients',
                'webhook_url',
                'webhook_secret',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('workflows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
