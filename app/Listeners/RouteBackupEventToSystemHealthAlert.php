<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Throwable;

/**
 * `--disable-notifications` on `backup:run` only flips Spatie's
 * internal EventHandler subscriber off — the `event(...)` calls
 * still fire, so this listener runs unconditionally and is the
 * single bridge from backup events to the `system_health_alert`
 * Pushover canonical (matching the channel choice used by every
 * other operator alert in the system).
 */
final class RouteBackupEventToSystemHealthAlert
{
    public const THROTTLE_SECONDS = 3600;

    public function handleBackupHasFailed(BackupHasFailed $event): void
    {
        $diskName = $event->diskName ?? 'unknown';
        $backupName = $event->backupName ?? 'unknown';
        $reason = $this->summariseException($event->exception);

        // Include the exception's short class name so a transient
        // auth blip does not silently mask a quota / connectivity
        // alert inside the 1h per-signal throttle window.
        $exceptionShortName = $this->shortClassName($event->exception);

        $this->emit(
            signal: "backup_has_failed_{$diskName}_{$exceptionShortName}",
            severity: NotificationSeverity::Critical,
            title: "Backup failed on disk `{$diskName}`",
            detail: "Backup `{$backupName}` could not be written to disk `{$diskName}`.\n\nReason: {$reason}",
        );
    }

    public function handleCleanupHasFailed(CleanupHasFailed $event): void
    {
        $diskName = $event->diskName ?? 'unknown';
        $backupName = $event->backupName ?? 'unknown';
        $reason = $this->summariseException($event->exception);
        $exceptionShortName = $this->shortClassName($event->exception);

        $this->emit(
            signal: "backup_cleanup_failed_{$diskName}_{$exceptionShortName}",
            severity: NotificationSeverity::High,
            title: "Backup cleanup failed on disk `{$diskName}`",
            detail: "Cleanup of `{$backupName}` failed on disk `{$diskName}`.\n\nReason: {$reason}",
        );
    }

    public function handleUnhealthyBackupWasFound(UnhealthyBackupWasFound $event): void
    {
        $messages = $event->failureMessages
            ->map(function (array $failure): string {
                return "- [{$failure['check']}] {$failure['message']}";
            })
            ->implode("\n");

        $this->emit(
            signal: "backup_unhealthy_{$event->diskName}",
            severity: NotificationSeverity::High,
            title: "Backup destination unhealthy on disk `{$event->diskName}`",
            detail: "Backup `{$event->backupName}` failed health checks:\n\n{$messages}",
        );
    }

    private function emit(string $signal, NotificationSeverity $severity, string $title, string $detail): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'system_health_alert',
                referenceData: [
                    'signal' => $signal,
                    'severity' => $severity->value,
                    'title' => $title,
                    'detail' => $detail,
                    'detected_at' => now()->toIso8601String(),
                ],
                duration: self::THROTTLE_SECONDS,
                cacheKeys: ['signal' => $signal],
            );
        } catch (Throwable $exception) {
            Log::channel('jobs')->error('[BACKUP-ALERT] Failed to dispatch system_health_alert', [
                'signal' => $signal,
                'severity' => $severity->value,
                'title' => $title,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function shortClassName(Throwable $exception): string
    {
        $fqcn = $exception::class;
        $lastSeparator = mb_strrpos($fqcn, '\\');

        return $lastSeparator === false ? $fqcn : mb_substr($fqcn, $lastSeparator + 1);
    }

    /**
     * Trim a Throwable into a single-line summary that fits a
     * Pushover message without truncating the actionable bits.
     * Keeps the message body and the deepest cause's message —
     * the full stack trace already lives in laravel.log.
     */
    private function summariseException(Throwable $exception): string
    {
        $message = mb_trim($exception->getMessage());

        $previous = $exception->getPrevious();
        if ($previous instanceof Throwable) {
            $message .= ' | caused by: '.mb_trim($previous->getMessage());
        }

        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr($message, 0, 800);
    }
}
