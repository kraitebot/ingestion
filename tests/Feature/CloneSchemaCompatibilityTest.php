<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('keeps the shared production schemas required by kraite clone', function (): void {
    expect(Schema::hasTable('ai_conversations'))
        ->toBeTrue()
        ->and(Schema::hasTable('ai_conversation_messages'))
        ->toBeTrue()
        ->and(Schema::hasTable('ai_api_configs'))
        ->toBeTrue()
        ->and(Schema::hasColumn('ai_api_configs', 'application_id'))
        ->toBeTrue()
        ->and(Schema::hasTable('trading_analyses'))
        ->toBeTrue();
});
