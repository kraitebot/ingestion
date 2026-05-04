<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\Kraite;

/**
 * Pin the auto-welcome behaviour for the engine (admin) Telegram
 * pairing transition. Mirror of `UserObserverTelegramWelcomeTest`
 * but watching the singleton `kraite` row's
 * `admin_telegram_chat_id` column instead of `users.telegram_chat_id`.
 *
 * Same failure-contained shape — Telegram errors must NOT abort
 * an engine save (which would block the entire bot from running).
 */
beforeEach(function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    config(['services.telegram-bot-api.token' => 'stub-bot-token']);

    // Reset the engine to a clean baseline. The Pest beforeEach in
    // the app-level Pest.php seeds a Kraite row with id=1 — we
    // wipe the chat_id so we can test the transition.
    Kraite::where('id', 1)->update(['admin_telegram_chat_id' => null]);
});

it('sends the admin welcome when admin_telegram_chat_id transitions from null to a value', function (): void {
    $engine = Kraite::find(1);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200),
    ]);

    $engine->update(['admin_telegram_chat_id' => '867511601']);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(1);

    $body = $sent->first()->data();
    expect($body['chat_id'] ?? null)->toBe('867511601');
    expect($body['parse_mode'] ?? null)->toBe('HTML');
    expect($body['text'] ?? '')->toContain('Welcome to Kraite');
    expect($body['text'] ?? '')->toContain('Admin');
    expect($body['text'] ?? '')->toContain('one-way');
});

it('does not re-send the welcome when an unrelated engine attribute changes', function (): void {
    $engine = Kraite::find(1);
    $engine->update(['admin_telegram_chat_id' => '111222333']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]], 200),
    ]);

    $engine->update(['email' => 'admin-rename@kraite.local']);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('does not send a welcome when admin_telegram_chat_id is cleared back to null', function (): void {
    $engine = Kraite::find(1);
    $engine->update(['admin_telegram_chat_id' => '555666777']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4]], 200),
    ]);

    $engine->update(['admin_telegram_chat_id' => null]);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('skips silently when the bot token is not configured', function (): void {
    config(['services.telegram-bot-api.token' => null]);

    $engine = Kraite::find(1);
    $engine->update(['admin_telegram_chat_id' => '123456789']);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('swallows Telegram API errors so a bad token cannot abort the engine save', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    $engine = Kraite::find(1);

    // Save must complete cleanly — no exception bubbles up.
    $engine->update(['admin_telegram_chat_id' => '999888777']);

    expect($engine->fresh()->admin_telegram_chat_id)->toBe('999888777');
});
