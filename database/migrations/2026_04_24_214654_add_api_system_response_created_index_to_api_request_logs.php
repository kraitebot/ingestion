<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('api_request_logs', 'idx_arl_api_system_resp_created')) {
            return;
        }

        Schema::table('api_request_logs', function ($table) {
            $table->index(['api_system_id', 'http_response_code', 'created_at'], 'idx_arl_api_system_resp_created');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('api_request_logs', 'idx_arl_api_system_resp_created')) {
            return;
        }

        Schema::table('api_request_logs', function ($table) {
            $table->dropIndex('idx_arl_api_system_resp_created');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($row) => $row->Key_name === $indexName);
    }
};
