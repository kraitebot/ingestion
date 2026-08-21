<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use Kraite\Core\Support\PositionClosedNotifier;

beforeEach(function (): void {
    Kraite::findOrFail(1)->updateSaving(['notifications_enabled' => true]);
    config([
        'kraite.app_push.enabled' => true,
        'kraite.app_push.endpoint' => 'https://exp.host/--/api/v2/push/send',
    ]);

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
function buildPositionForPenultimateCloseNotification(
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
        'total_limit_orders' => 4,
        'pnl' => $pnl,
    ]);
}

/** @return array{waped_closed_notification_sent: bool, high_profit_notification_sent: bool} */
function dispatchPenultimateCloseNotification(
    Position $position,
    int $filledLimitCount = 1,
): array {
    return app(PositionClosedNotifier::class)->send(
        position: $position->fresh(),
        closingPrice: '12.45678900',
        filledLimitCount: $filledLimitCount,
    );
}

it('sends a qualifying close to the app only after exchange PnL exists and only once', function (): void {
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => [['status' => 'ok', 'id' => 'wap-close-ticket']],
        ]),
    ]);
    $position = buildPositionForPenultimateCloseNotification(true, ['mail', 'pushover']);
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
        ->where('canonical', 'position_high_profit_closed')
        ->where('channel', 'app');
    $mailLogs = NotificationLog::query()
        ->where('user_id', $user->id)
        ->where('canonical', 'position_high_profit_closed')
        ->where('channel', 'mail');

    expect((clone $appLogs)->count())
        ->toBe(0)
        ->and((clone $mailLogs)->count())
        ->toBe(0);

    expect(dispatchPenultimateCloseNotification($position, filledLimitCount: 3))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertNothingSent();
    expect((clone $appLogs)->count())->toBe(0);

    $position->updateSaving(['pnl' => '4.25000000']);

    expect(dispatchPenultimateCloseNotification($position->fresh(), filledLimitCount: 3))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    Http::assertSent(static function (Request $request) use ($position, $token): bool {
        $payload = $request->data()[0] ?? [];

        return $payload['to'] === $token
            && $payload['title'] === "🎉 High-Profit Close — LONG {$position->parsed_trading_pair}"
            && str_contains($payload['body'], 'Exit: 12.456')
            && ! str_contains($payload['body'], '12.456789')
            && $payload['data']['canonical'] === 'position_high_profit_closed'
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

    expect(dispatchPenultimateCloseNotification($position->fresh(), filledLimitCount: 3))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertSentCount(1);
    expect((clone $appLogs)->count())
        ->toBe(1)
        ->and((clone $mailLogs)->count())
        ->toBe(0);
});

it('forces qualifying closes to the trader app without email or operator Pushover', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(true, ['mail', 'pushover'], pnl: '4.25000000');
    $trader = $position->account->user;

    NotificationFacade::assertNothingSent();

    expect(dispatchPenultimateCloseNotification($position, filledLimitCount: 3))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    NotificationFacade::assertSentToTimes($trader, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $trader,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_high_profit_closed'
            && $notification->via($trader) === [AppPushChannel::class],
    );
    NotificationFacade::assertNotSentTo(Kraite::admin(), AlertNotification::class);
});

it('does not send a close alert before the penultimate limit', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(true, pnl: '4.25000000');

    expect(dispatchPenultimateCloseNotification($position, filledLimitCount: 2))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertNothingSent();
});

it('does not label a non-positive stop-loss close as high profit', function (string $pnl): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(true, pnl: $pnl);
    $stopLoss = Order::withoutEvents(function () use ($position): Order {
        return Order::create([
            'position_id' => $position->id,
            'uuid' => Str::uuid()->toString(),
            'client_order_id' => Str::uuid()->toString(),
            'type' => 'STOP-MARKET',
            'side' => 'SELL',
            'position_side' => $position->direction,
            'status' => 'FILLED',
            'reference_status' => 'FILLED',
            'price' => '286.81654000',
            'quantity' => '0',
            'is_algo' => true,
            'filled_at' => now(),
        ]);
    });

    expect($position->pnl)
        ->toBe($pnl)
        ->and($stopLoss->status)
        ->toBe('FILLED');
    NotificationFacade::assertNothingSent();

    expect(dispatchPenultimateCloseNotification($position, filledLimitCount: 4))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    expect($position->fresh()->pnl)
        ->toBe($pnl)
        ->and($stopLoss->fresh()->status)
        ->toBe('FILLED');
    NotificationFacade::assertNothingSent();
})->with([
    'production-sized loss' => '-415.85686241',
    'break-even close' => '0.00000000',
]);

it('qualifies a close from ladder depth even if the WAP flag was not persisted', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(false, pnl: '2.10000000');
    $user = $position->account->user;

    NotificationFacade::assertNothingSent();

    expect(dispatchPenultimateCloseNotification(
        $position,
        filledLimitCount: 3,
    ))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    NotificationFacade::assertSentToTimes($user, AlertNotification::class, 1);
});

it('keeps a manual close silent when the position did not reach the penultimate limit', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(false, pnl: '2.10000000');
    $position->updateSaving(['manually_closed_at' => now()]);

    NotificationFacade::assertNothingSent();

    expect(dispatchPenultimateCloseNotification($position->fresh(), filledLimitCount: 2))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertNothingSent();
});

it('sends only the specialized app close after the penultimate limit is reached', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(true, pnl: '8.75000000');
    $user = $position->account->user;

    NotificationFacade::assertNothingSent();

    expect(dispatchPenultimateCloseNotification(
        $position,
        filledLimitCount: 3,
    ))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => true,
    ]);

    NotificationFacade::assertSentToTimes($user, AlertNotification::class, 1);
    NotificationFacade::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_high_profit_closed'
            && $notification->via($user) === [AppPushChannel::class]
            && str_contains((string) $notification->pushoverMessage, 'Exit: 12.456')
            && ! str_contains((string) $notification->pushoverMessage, '12.456789'),
    );
    NotificationFacade::assertNotSentTo(Kraite::admin(), AlertNotification::class);
});

it('keeps manual attribution on a high-profit close', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForPenultimateCloseNotification(true, pnl: '8.75000000');
    $position->updateSaving(['manually_closed_at' => now()]);
    $user = $position->account->user;

    expect(dispatchPenultimateCloseNotification(
        $position->fresh(),
        filledLimitCount: 3,
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
