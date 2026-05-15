<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kraite\Core\Models\User;

/**
 * Fired the moment a user's `email_verified_at` transitions from null
 * to a timestamp — i.e., the user has clicked the verification link on
 * kraite.com and the controller has just persisted the change.
 *
 * Listeners react to this without caring about HOW the verification
 * happened (public form, operator-set, future flows). The event
 * carries the user id only — listeners refetch the User model so they
 * see the latest DB state regardless of when they run.
 */
final class UserEmailConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly int $userId) {}

    /**
     * Convenience constructor — accepts a User and stores the id.
     */
    public static function for(User $user): self
    {
        return new self($user->id);
    }
}
