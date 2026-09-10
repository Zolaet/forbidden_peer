<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant or revoke the platform-owner role.
 *
 * The only supported way to change a role. `role` is deliberately absent from
 * User's $fillable, so this force-fills the column rather than mass-assigning
 * it — which is exactly what stops a registration payload from ever setting
 * it, now or after someone refactors register().
 */
class PromoteUser extends Command
{
    protected $signature = 'user:promote
                            {email : The account to grant administrator access to}
                            {--demote : Revoke administrator access instead}';

    protected $description = 'Grant or revoke administrator access for a user account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error('No account found for ' . $email . '.');

            return self::FAILURE;
        }

        $demoting = (bool) $this->option('demote');

        if (!$demoting && $user->isAdmin()) {
            $this->info($user->name . ' is already an administrator. Nothing to do.');

            return self::SUCCESS;
        }

        if ($demoting && !$user->isAdmin()) {
            $this->info($user->name . ' is not an administrator. Nothing to do.');

            return self::SUCCESS;
        }

        // The guard that matters. Losing the last administrator leaves nobody
        // able to resolve a dispute, and the escrow of every disputed trade
        // frozen with no in-product way back — the only recovery would be
        // editing the row by hand.
        if ($demoting && $this->adminCount() <= 1) {
            $this->error('This is the only administrator account. Demoting it would leave nobody able to resolve disputes.');

            return self::FAILURE;
        }

        $user->forceFill([
            'role' => $demoting ? User::ROLE_USER : User::ROLE_ADMIN,
        ])->save();

        $this->info(
            ($demoting ? 'Revoked administrator access from ' : 'Granted administrator access to ')
            . $user->name . ' <' . $user->email . '>.'
        );

        return self::SUCCESS;
    }

    protected function adminCount(): int
    {
        return User::where('role', User::ROLE_ADMIN)->count();
    }
}
