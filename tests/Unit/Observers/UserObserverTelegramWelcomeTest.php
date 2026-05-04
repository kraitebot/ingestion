<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\User;

/**
 * Pin the auto-welcome behaviour for the Telegram channel.
 *
 * When a user's `telegram_chat_id` transitions from null/empty to a
 * concrete chat_id (manual admin entry today; pairing webhook
 * later), the `UserObserver` fires a one-off `sendMessage` that
 * tells the user the channel is one-way + alerts-only. The message
 * is intrinsically once-per-user and bypasses the canonical
 * pipeline (no throttling, no severity).
 *
 * Failure is contained — a bad bot token / network blip / Telegram
 * 5xx must NOT abort the surrounding user save.
 */
beforeEach(function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    config(['services.telegram-bot-api.token' => 'stub-bot-token']);
});

it('sends the welcome message when a user is created with a telegram_chat_id', function (): void {
    User::factory()->create([
        'telegram_chat_id' => '123456789',
    ]);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(1);

    $req = $sent->first();
    expect($req->url())->toContain('/bot'.'stub-bot-token'.'/sendMessage');

    $body = $req->data();
    expect($body['chat_id'] ?? null)->toBe('123456789');
    expect($body['parse_mode'] ?? null)->toBe('HTML');
    expect($body['text'] ?? '')->toContain('Welcome to Kraite');
    expect($body['text'] ?? '')->toContain('one-way');
});

it('sends the welcome message when telegram_chat_id transitions from null to a value', function (): void {
    $user = User::factory()->create(['telegram_chat_id' => null]);

    // Reset Http::fake's request log so the create hook's empty
    // run (no chat_id present) doesn't pollute the assertion below.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200),
    ]);

    $user->update(['telegram_chat_id' => '987654321']);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(1);
    expect($sent->first()->data()['chat_id'] ?? null)->toBe('987654321');
});

it('does not re-send the welcome when an unrelated user attribute changes', function (): void {
    $user = User::factory()->create(['telegram_chat_id' => '111222333']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]], 200),
    ]);

    $user->update(['name' => 'Renamed User']);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('does not send a welcome when telegram_chat_id is cleared back to null', function (): void {
    $user = User::factory()->create(['telegram_chat_id' => '555666777']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4]], 200),
    ]);

    $user->update(['telegram_chat_id' => null]);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('skips silently when the bot token is not configured', function (): void {
    config(['services.telegram-bot-api.token' => null]);

    User::factory()->create([
        'telegram_chat_id' => '123456789',
    ]);

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($req) => str_contains($req->url(), 'sendMessage'));

    expect($sent)->toHaveCount(0);
});

it('swallows Telegram API errors so a bad token cannot abort the user save', function (): void {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    // Save must complete cleanly — no exception bubbles up.
    $user = User::factory()->create([
        'telegram_chat_id' => '999888777',
    ]);

    expect($user->exists)->toBeTrue();
    expect($user->id)->not->toBeNull();
});
