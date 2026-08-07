<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDefinition;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use Kraite\Core\Support\PositionClosedNotifier;
use NotificationChannels\Pushover\PushoverChannel;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    Kraite::findOrFail(1)->updateSaving(['notifications_enabled' => true]);
    config([
        'kraite.app_push.enabled' => true,
        'kraite.app_push.endpoint' => 'https://exp.host/--/api/v2/push/send',
    ]);

    NotificationDefinition::query()->updateOrCreate(
        ['canonical' => 'position_closed'],
        [
            'title' => 'Position Closed',
            'description' => 'WAP close test definition.',
            'default_severity' => NotificationSeverity::Info,
            'cache_duration' => 60,
            'cache_key' => ['position'],
            'is_active' => true,
        ],
    );
    NotificationDefinition::query()->updateOrCreate(
        ['canonical' => 'position_high_profit_closed'],
        [
            'title' => 'High-Profit Position Closed',
            'description' => 'High-profit close test definition.',
            'default_severity' => NotificationSeverity::Info,
            'cache_duration' => 60,
            'cache_key' => ['position'],
            'is_active' => true,
        ],
    );

});

/** @param array<int, string> $channels */
function buildPositionForWapedCloseNotification(
    bool $wasWaped,
    array $channels = ['mail'],
    ?string $pnl = null,
): Position {
    $token = 'WAP'.mb_strtoupper(Str::random(5));
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => "WAP Close {$token}",
    ]);
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $symbol->token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'price_precision' => 3,
        'tick_size' => '0.001',
    ]);
    $user = User::factory()->active()->create([
        'email' => mb_strtolower("wap-close-{$token}@example.test"),
        'notification_channels' => $channels,
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
        'name' => "WAP Close {$token}",
    ]);

    return Position::factory()->closed()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $symbol->token.'USDT',
        'was_waped' => $wasWaped,
        'waped_at' => $wasWaped ? now()->subMinute() : null,
        'pnl' => $pnl,
    ]);
}

/** @return array{waped_closed_notification_sent: bool, high_profit_notification_sent: bool} */
function dispatchWapedCloseNotification(
    Position $position,
    int $filledLimitCount = 1,
    int $notifyThreshold = 3,
): array {
    $position->account->updateSaving([
        'total_limit_orders_filled_to_notify' => $notifyThreshold,
    ]);

    return app(PositionClosedNotifier::class)->send(
        position: $position->fresh(),
        closingPrice: '12.45678900',
        filledLimitCount: $filledLimitCount,
    );
}

it('sends a WAP-position close through the trader channels including the iPhone app', function (): void {
    Mail::fake();
    mock(PushoverChannel::class)
        ->shouldReceive('send')
        ->once()
        ->andReturnNull();
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => [['status' => 'ok', 'id' => 'wap-close-ticket']],
        ]),
    ]);
    $position = buildPositionForWapedCloseNotification(true, []);
    $user = $position->account->user;
    $token = 'ExponentPushToken[wap_close_phone]';
    AppPushDevice::factory()->create([
        'user_id' => $user->id,
        'expo_push_token' => $token,
        'token_hash' => hash('sha256', $token),
        'disabled_at' => null,
    ]);

    $appLogs = NotificationLog::query()
        ->where('user_id', $user->id)
        ->where('canonical', 'position_closed')
        ->where('channel', 'app');
    $mailLogs = NotificationLog::query()
        ->where('user_id', $user->id)
        ->where('canonical', 'position_closed')
        ->where('channel', 'mail');

    expect((clone $appLogs)->count())
        ->toBe(0)
        ->and((clone $mailLogs)->count())
        ->toBe(0);

    expect(dispatchWapedCloseNotification($position))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertNothingSent();
    expect((clone $appLogs)->count())->toBe(0);

    $position->updateSaving(['pnl' => '4.25000000']);

    expect(dispatchWapedCloseNotification($position->fresh()))->toBe([
        'waped_closed_notification_sent' => true,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertSent(static function (Request $request) use ($position, $token): bool {
        $payload = $request->data()[0] ?? [];

        return $payload['to'] === $token
            && $payload['title'] === "Position Closed — LONG {$position->parsed_trading_pair}"
            && str_contains($payload['body'], 'Exit: 12.456')
            && ! str_contains($payload['body'], '12.456789')
            && $payload['data']['canonical'] === 'position_closed'
            && $payload['data']['screen'] === 'Dashboard'
            && $payload['badge'] === 1;
    });

    $appLog = (clone $appLogs)->sole();

    expect((clone $appLogs)->count())
        ->toBe(1)
        ->and($appLog->recipient)
        ->toBe('iPhone app')
        ->and($appLog->message_id)
        ->toBe('wap-close-ticket');

    expect(dispatchWapedCloseNotification($position->fresh()))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertSentCount(1);
    expect((clone $appLogs)->count())
        ->toBe(1)
        ->and((clone $mailLogs)->count())
        ->toBe(1)
        ->and((clone $mailLogs)->sole()->recipient)
        ->toBe($user->email);
});

it('routes every WAP close to trader app and email plus operator Pushover despite empty trader preferences', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(true, [], pnl: '4.25000000');
    $trader = $position->account->user;
    $operator = Kraite::admin();

    NotificationFacade::assertNothingSent();

    expect(dispatchWapedCloseNotification($position))->toBe([
        'waped_closed_notification_sent' => true,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertSentToTimes($trader, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $trader,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_closed'
            && $notification->via($trader) === [AppPushChannel::class, 'mail'],
    );
    NotificationFacade::assertSentToTimes($operator, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $operator,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_closed'
            && $notification->via($operator) === [PushoverChannel::class],
    );
});

it('still delivers trader app and email when operator Pushover throws', function (): void {
    Mail::fake();
    mock(PushoverChannel::class)
        ->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('Pushover unavailable'));
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => [['status' => 'ok', 'id' => 'fallback-app-ticket']],
        ]),
    ]);
    $position = buildPositionForWapedCloseNotification(true, [], pnl: '4.25000000');
    $trader = $position->account->user;
    $token = 'ExponentPushToken[fallback_phone]';
    AppPushDevice::factory()->create([
        'user_id' => $trader->id,
        'expo_push_token' => $token,
        'token_hash' => hash('sha256', $token),
        'disabled_at' => null,
    ]);

    expect(dispatchWapedCloseNotification($position))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertSent(static fn (Request $request): bool => ($request->data()[0]['to'] ?? null) === $token);
    expect(NotificationLog::query()
        ->where('user_id', $trader->id)
        ->where('canonical', 'position_closed')
        ->where('channel', 'app')
        ->count())
        ->toBe(1)
        ->and(NotificationLog::query()
            ->where('user_id', $trader->id)
            ->where('canonical', 'position_closed')
            ->where('channel', 'mail')
            ->where('recipient', $trader->email)
            ->count())
        ->toBe(1);
});

it('does not send the close notification for a position that never completed WAP', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(false, pnl: '2.10000000');

    NotificationFacade::assertNothingSent();

    expect(dispatchWapedCloseNotification(
        $position,
        filledLimitCount: 3,
        notifyThreshold: 3,
    ))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertNothingSent();
});

it('labels an exchange-side manual close explicitly even when the position was never WAPed', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(false, pnl: '2.10000000');
    $position->updateSaving(['manually_closed_at' => now()]);
    $user = $position->account->user;

    NotificationFacade::assertNothingSent();

    expect(dispatchWapedCloseNotification($position->fresh()))->toBe([
        'waped_closed_notification_sent' => true,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertSentToTimes($user, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_closed'
            && $notification->title === "Position Manually Closed — LONG {$position->parsed_trading_pair}"
            && str_contains((string) $notification->pushoverMessage, 'manually closed')
            && str_contains((string) $notification->pushoverMessage, 'Closed outside Kraite.')
            && $notification->via($user) === [AppPushChannel::class, 'mail'],
    );
});

it('sends only the specialized high-profit close when its threshold is met', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(true, pnl: '8.75000000');
    $user = $position->account->user;

    NotificationFacade::assertNothingSent();

    expect(dispatchWapedCloseNotification(
        $position,
        filledLimitCount: 3,
        notifyThreshold: 3,
    ))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    NotificationFacade::assertSentToTimes($user, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_high_profit_closed'
            && $notification->via($user) === [AppPushChannel::class, 'mail']
            && str_contains((string) $notification->pushoverMessage, 'Exit: 12.456')
            && ! str_contains((string) $notification->pushoverMessage, '12.456789'),
    );
    NotificationFacade::assertSentToTimes(Kraite::admin(), AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_high_profit_closed'
            && $notification->via(Kraite::admin()) === [PushoverChannel::class],
    );
});

it('keeps manual attribution on a high-profit close', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(true, pnl: '8.75000000');
    $position->updateSaving(['manually_closed_at' => now()]);
    $user = $position->account->user;

    expect(dispatchWapedCloseNotification(
        $position->fresh(),
        filledLimitCount: 3,
        notifyThreshold: 3,
    ))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    NotificationFacade::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_high_profit_closed'
            && $notification->title === "🎉 High-Profit Position Manually Closed — LONG {$position->parsed_trading_pair}"
            && str_contains((string) $notification->pushoverMessage, 'manually closed outside Kraite.'),
    );
});
