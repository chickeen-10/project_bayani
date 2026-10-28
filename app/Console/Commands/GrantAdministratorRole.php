<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantAdministratorRole extends Command
{
    protected $signature = 'bayani:grant-admin {email : Email of an existing account}';

    protected $description = 'Grant administrator access to an existing account after operator confirmation';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account exists for that email. Register the user first.');

            return self::FAILURE;
        }

        if ($user->role === 'admin') {
            $this->info('This account already has administrator access.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Grant administrator access to {$user->email}?", false)) {
            $this->comment('No role changes made.');

            return self::SUCCESS;
        }

        $user->forceFill(['role' => 'admin'])->save();
        $this->info('Administrator access granted.');

        return self::SUCCESS;
    }
}
