<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('steps', 'idx_steps_state_completed_at')) {
            return;
        }

        Schema::table('steps', function (Blueprint $table): void {
            $table->index(['state', 'completed_at'], 'idx_steps_state_completed_at');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('steps', 'idx_steps_state_completed_at')) {
            return;
        }

        Schema::table('steps', function (Blueprint $table): void {
            $table->dropIndex('idx_steps_state_completed_at');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        /** @var array<int, stdClass> $rows */
        $rows = DB::select("SHOW INDEX FROM {$table}");

        foreach ($rows as $row) {
            if (($row->Key_name ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
