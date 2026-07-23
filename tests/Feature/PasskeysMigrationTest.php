<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\User;

it('owns passkey storage with unique credentials and restricted user deletion', function (): void {
    expect(Schema::hasColumns('passkeys', [
        'id',
        'user_id',
        'name',
        'credential_id',
        'credential',
        'last_used_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $user = User::factory()->create();

    DB::table('passkeys')->insert([
        'user_id' => $user->id,
        'name' => 'Primary device',
        'credential_id' => 'credential-1',
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): bool => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class)
        ->and(fn (): bool => DB::table('passkeys')->insert([
            'user_id' => $user->id,
            'name' => 'Duplicate device',
            'credential_id' => 'credential-1',
            'credential' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->toThrow(QueryException::class);
});
