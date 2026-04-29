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
        if ($this->indexExists('api_request_logs', 'idx_arl_api_system_resp_created')) {
            return;
        }

        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->index(['api_system_id', 'http_response_code', 'created_at'], 'idx_arl_api_system_resp_created');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('api_request_logs', 'idx_arl_api_system_resp_created')) {
            return;
        }

        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_arl_api_system_resp_created');
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
