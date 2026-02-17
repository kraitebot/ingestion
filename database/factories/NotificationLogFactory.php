<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;

/**
 * @extends Factory<NotificationLog>
 */
final class NotificationLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NotificationLog>
     */
    protected $model = NotificationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canonical' => 'server_rate_limit_exceeded',
            'relatable_type' => Account::class,
            'relatable_id' => Account::factory(),
            'channel' => 'mail',
            'recipient' => fake()->safeEmail(),
            'sent_at' => now(),
            'status' => 'sent',
            'http_headers_sent' => null,
            'http_headers_received' => null,
            'gateway_response' => null,
            'content_dump' => json_encode([
                'title' => 'Test Notification',
                'message' => 'This is a test notification',
            ]),
            'error_message' => null,
        ];
    }

    /**
     * Notification sent via Pushover channel.
     */
    public function pushover(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'channel' => 'pushover',
                'recipient' => fake()->regexify('[a-z0-9]{30}'), // Pushover key format
            ];
        });
    }

    /**
     * Notification sent via email channel.
     */
    public function mail(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'channel' => 'mail',
                'recipient' => fake()->safeEmail(),
            ];
        });
    }

    /**
     * Notification delivered successfully.
     */
    public function delivered(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => 'delivered',
            ];
        });
    }

    /**
     * Notification failed to send.
     */
    public function failed(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => 'failed',
                'error_message' => 'Failed to send notification',
            ];
        });
    }

    /**
     * Notification opened by recipient (mail channel only).
     */
    public function opened(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => 'opened',
                'opened_at' => now(),
                'gateway_response' => [
                    'message_id' => fake()->uuid(),
                    'status' => 'opened',
                ],
            ];
        });
    }

    /**
     * Notification hard bounced (permanent failure).
     */
    public function hardBounced(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => 'hard bounced',
                'hard_bounced_at' => now(),
                'error_message' => 'Hard bounce: Mailbox does not exist',
                'gateway_response' => [
                    'bounce_type' => 'hard',
                    'bounce_reason' => 'Mailbox does not exist',
                ],
            ];
        });
    }

    /**
     * Notification soft bounced (temporary failure).
     */
    public function softBounced(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => 'soft bounced',
                'soft_bounced_at' => now(),
                'error_message' => 'Soft bounce: Mailbox full',
                'gateway_response' => [
                    'bounce_type' => 'soft',
                    'bounce_reason' => 'Mailbox full',
                ],
            ];
        });
    }

    /**
     * Notification related to a User (instead of Account).
     */
    public function forUser(?User $user = null): self
    {
        return $this->state(function (array $attributes) use ($user): array {
            return [
                'relatable_type' => User::class,
                'relatable_id' => $user !== null ? $user->id : User::factory(),
            ];
        });
    }

    /**
     * Notification for admin (no relatable model).
     */
    public function forAdmin(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'relatable_type' => null,
                'relatable_id' => null,
            ];
        });
    }

    /**
     * Notification with specific canonical.
     */
    public function withCanonical(string $canonical): self
    {
        return $this->state(function (array $attributes) use ($canonical): array {
            return [
                'canonical' => $canonical,
            ];
        });
    }

    /**
     * Notification with gateway response data.
     *
     * @param  array<string, mixed>  $response
     */
    public function withGatewayResponse(array $response): self
    {
        return $this->state(function (array $attributes) use ($response): array {
            return [
                'gateway_response' => $response,
            ];
        });
    }
}
