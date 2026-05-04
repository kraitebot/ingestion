<?php

declare(strict_types=1);

use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * Pin the third notification channel — Telegram — alongside the
 * existing mail + Pushover routes.
 *
 * Channel selection is driven by the user's `notification_channels`
 * array (cast in `User`). The string `'telegram'` resolves to
 * `\NotificationChannels\Telegram\TelegramChannel::class` via the
 * accessor in `User::getNotificationChannelsAttribute`.
 *
 * Routing target comes from `User::routeNotificationForTelegram`
 * which returns the user's `telegram_chat_id`. When that's null,
 * Laravel's notification dispatcher silently skips the channel —
 * no exception, no log.
 *
 * Message rendering: `AlertNotification::toTelegram` falls back
 * from `$telegramMessage` → `$pushoverMessage` → `$message`, wraps
 * the title in `<b>`, and uses HTML parse-mode (lighter escape
 * burden than MarkdownV2).
 */
function makeUserWithChannels(array $channels, ?string $telegramChatId = null): User
{
    return User::factory()->create([
        'notification_channels' => $channels,
        'telegram_chat_id' => $telegramChatId,
    ]);
}

it('resolves the string `telegram` channel to TelegramChannel::class on the User accessor', function (): void {
    $user = makeUserWithChannels(['mail', 'telegram', 'pushover'], telegramChatId: '867511601');

    expect($user->notification_channels)->toContain(TelegramChannel::class);
    expect($user->notification_channels)->toContain('mail');
});

it('AlertNotification::via includes the Telegram channel when the user has it configured', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    $notification = new AlertNotification(
        message: 'body',
        title: 'Test alert',
    );

    expect($notification->via($user))->toBe([TelegramChannel::class]);
});

it('AlertNotification::toTelegram returns a TelegramMessage targeting the user\'s chat_id', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    $notification = new AlertNotification(
        message: 'fallback body',
        title: 'Test alert',
        pushoverMessage: 'pushover body',
        telegramMessage: 'telegram body',
    );

    $telegram = $notification->toTelegram($user);

    expect($telegram)->toBeInstanceOf(TelegramMessage::class);

    $payload = $telegram->toArray();

    expect($payload['chat_id'] ?? null)->toBe('867511601');
    expect($payload['text'] ?? '')->toContain('Test alert');
    expect($payload['text'] ?? '')->toContain('telegram body');
    expect($payload['parse_mode'] ?? null)->toBe('HTML');
});

it('falls back to pushoverMessage when telegramMessage is null (terse channels share copy)', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    $notification = new AlertNotification(
        message: 'long-form email body',
        title: 'Test',
        pushoverMessage: 'compact pushover body',
        telegramMessage: null,
    );

    $payload = $notification->toTelegram($user)->toArray();

    expect($payload['text'] ?? '')->toContain('compact pushover body');
});

it('falls back to plain message when both telegramMessage and pushoverMessage are null', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    $notification = new AlertNotification(
        message: 'plain body',
        title: 'Test',
    );

    $payload = $notification->toTelegram($user)->toArray();

    expect($payload['text'] ?? '')->toContain('plain body');
});

it('escapes HTML special chars in body and title (HTML parse-mode safety)', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    $notification = new AlertNotification(
        message: 'oops <script>alert(1)</script> & "quoted"',
        title: 'Title with <tag> & ampersand',
    );

    $payload = $notification->toTelegram($user)->toArray();

    expect($payload['text'])->toContain('&lt;tag&gt;');
    expect($payload['text'])->toContain('&amp;');
    expect($payload['text'])->not->toContain('<script>');
});

it('User::routeNotificationForTelegram returns null when telegram_chat_id is unset', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: null);

    expect($user->routeNotificationForTelegram(null))->toBeNull();
});

it('User::routeNotificationForTelegram returns the chat_id when set', function (): void {
    $user = makeUserWithChannels(['telegram'], telegramChatId: '867511601');

    expect($user->routeNotificationForTelegram(null))->toBe('867511601');
});
