<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Jobs\Atomic\Position\UpdateRemainingClosingDataJob;
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
function buildPositionForWapedCloseNotification(bool $wasWaped, array $channels = ['mail']): Position
{
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
    ]);
}

/** @return array{waped_closed_notification_sent: bool, high_profit_notification_sent: bool} */
function dispatchWapedCloseNotification(
    Position $position,
    int $filledLimitCount = 1,
    int $notifyThreshold = 3,
): array {
    $job = new UpdateRemainingClosingDataJob($position->id);
    $method = new ReflectionMethod($job, 'dispatchClosingNotifications');

    return $method->invoke(
        $job,
        $position,
        '12.45000000',
        $filledLimitCount,
        false,
        $notifyThreshold,
    );
}

it('sends a WAP-position close through the trader channels including the iPhone app', function (): void {
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

    expect((clone $appLogs)->count())->toBe(0);

    expect(dispatchWapedCloseNotification($position))->toBe([
        'waped_closed_notification_sent' => true,
        'high_profit_notification_sent' => false,
    ]);

    Http::assertSent(static function (Request $request) use ($position, $token): bool {
        $payload = $request->data()[0] ?? [];

        return $payload['to'] === $token
            && $payload['title'] === "Position Closed — LONG {$position->parsed_trading_pair}"
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
});

it('does not send the close notification for a position that never completed WAP', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(false);

    NotificationFacade::assertNothingSent();

    expect(dispatchWapedCloseNotification($position))->toBe([
        'waped_closed_notification_sent' => false,
        'high_profit_notification_sent' => false,
    ]);

    NotificationFacade::assertNothingSent();
});

it('sends only the specialized high-profit close when its threshold is met', function (): void {
    NotificationFacade::fake();
    $position = buildPositionForWapedCloseNotification(true);
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
            && $notification->via($user) === ['mail', AppPushChannel::class],
    );
});
