<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Models\Account;
use Throwable;

/**
 * Live smoke test for the bot's service-level BitGet credentials.
 *
 * Reads the credentials exactly the way production code does — from the
 * engine row via Account::admin('bitget') — detects the account mode
 * (classic vs unified), and performs read-only calls against the live
 * BitGet API: API-key permission read (withdrawal safety), positions
 * read, and balance read. Fails when a call errors or when the key
 * unexpectedly has withdrawal access. A unified key without the "UTA
 * management (read)" scope cannot read balances; that is reported as a
 * warning, not a failure, because the admin key does not need balances.
 */
final class SmokeBitgetAdminCommand extends Command
{
    private const BITGET_PERMISSION_ERROR_CODE = '40014';

    protected $signature = 'kraite:smoke-bitget-admin';

    protected $description = 'Verify the seeded BitGet admin credentials against the live BitGet API (read-only)';

    public function handle(): int
    {
        $account = Account::admin('bitget');
        $account->portfolio_quote = 'USDT';
        $account->trading_quote = 'USDT';

        try {
            $mode = $account->resolveBitgetAccountMode();
        } catch (Throwable $exception) {
            $this->error("Account-mode detection FAILED: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Account mode: {$mode->value}");

        try {
            $permissions = $account->apiQueryWithdrawalPermission();
            $withdrawalsEnabled = (bool) ($permissions->result['withdrawals_enabled'] ?? true);
        } catch (Throwable $exception) {
            $this->error("Permission read FAILED: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($withdrawalsEnabled) {
            $this->error('Permission read OK, but the key HAS WITHDRAWAL ACCESS — rotate it and bind a key without withdrawals.');

            return self::FAILURE;
        }

        $this->info('Permission read OK — withdrawals disabled.');

        try {
            $positions = collect($account->apiQueryPositions()->result ?? []);
            $this->info("Positions read OK — {$positions->count()} open position(s).");
        } catch (Throwable $exception) {
            $this->error("Positions read FAILED: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! $this->readBalance($account, $mode)) {
            return self::FAILURE;
        }

        $this->info('BitGet admin credentials are live and safe.');

        return self::SUCCESS;
    }

    private function readBalance(Account $account, BitgetAccountMode $mode): bool
    {
        try {
            $balance = $account->apiQueryBalance()->result ?? [];
            $this->info('Balance read OK — total wallet balance: '.($balance['total-wallet-balance'] ?? '0').' USDT.');

            return true;
        } catch (RequestException $exception) {
            if ($mode === BitgetAccountMode::Unified && $this->isPermissionError($exception)) {
                $this->warn('Balance read skipped — key lacks the "UTA management (read)" scope. Fine for the admin key; trader keys need it.');

                return true;
            }

            $this->error("Balance read FAILED: {$exception->getMessage()}");

            return false;
        } catch (Throwable $exception) {
            $this->error("Balance read FAILED: {$exception->getMessage()}");

            return false;
        }
    }

    private function isPermissionError(RequestException $exception): bool
    {
        $response = $exception->getResponse();

        if ($response === null) {
            return false;
        }

        $payload = json_decode((string) $response->getBody(), associative: true);

        return is_array($payload)
            && (string) ($payload['code'] ?? '') === self::BITGET_PERMISSION_ERROR_CODE;
    }
}
