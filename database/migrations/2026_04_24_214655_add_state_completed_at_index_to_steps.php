<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('steps', 'idx_steps_state_completed_at')) {
            return;
        }

        Schema::table('steps', function ($table) {
            $table->index(['state', 'completed_at'], 'idx_steps_state_completed_at');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('steps', 'idx_steps_state_completed_at')) {
            return;
        }

        Schema::table('steps', function ($table) {
            $table->dropIndex('idx_steps_state_completed_at');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($row) => $row->Key_name === $indexName);
    }
};
