<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$rows = DB::table('api_data_stream')
    ->where('account_id', 1)
    ->where('raw_event_type', 'ACCOUNT_UPDATE')
    ->whereBetween('received_at', ['2026-07-14 21:29:55', '2026-07-14 21:30:15'])
    ->orderBy('id')
    ->get()
    ->map(static function ($row): array {
        $payload = json_decode((string) $row->raw_payload, true);
        $positions = collect($payload['a']['P'] ?? [])
            ->filter(static fn (array $position): bool => ($position['s'] ?? null) === 'FILUSDT')
            ->values()
            ->all();

        return [
            'id' => $row->id,
            'event_time' => $row->event_time,
            'received_at' => $row->received_at,
            'reason' => $payload['a']['m'] ?? null,
            'positions' => $positions,
        ];
    })
    ->filter(static fn (array $row): bool => $row['positions'] !== [])
    ->values();

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
