<?php

declare(strict_types=1);

/**
 * Architectural Tests for Security
 *
 * These tests enforce security best practices.
 */
arch('no insecure hash functions')
    ->expect(['md5', 'sha1'])
    ->not->toBeUsed()
    ->ignoring([
        'Tests', // Tests may use md5 for non-security purposes
        'Kraite\Core\Support', // May use for cache keys
    ]);

arch('no direct SQL execution')
    ->expect('DB::statement')
    ->not->toBeUsed()
    ->ignoring([
        'Tests', // Tests may use DB::statement for setup
        'database/migrations', // Migrations may need raw SQL
    ]);

// Note: Manual review required for sensitive data logging
