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

        Schema::table('workflows', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflows', 'trigger_actions')) {
                $table->text('trigger_actions')->nullable()->after('action');
            }

            if (! Schema::hasColumn('workflows', 'trigger_statuses')) {
                $table->text('trigger_statuses')->nullable()->after('status');
            }

            if (! Schema::hasColumn('workflows', 'deliveries')) {
                $table->text('deliveries')->nullable()->after('webhook_secret');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflows')) {
            return;
        }

        Schema::table('workflows', function (Blueprint $table): void {
            $columns = [];

            foreach (['trigger_actions', 'trigger_statuses', 'deliveries'] as $column) {
                if (Schema::hasColumn('workflows', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
