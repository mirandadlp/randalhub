<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateUserCommand extends Command
{
    protected $signature = 'jobhunter:create-user {email} {--name=} {--password=}';

    protected $description = 'Create (or reset the password of) the single application user';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        $existing = User::first();
        if ($existing && $existing->email !== $email) {
            $this->error("This is a single-user app; a user already exists ({$existing->email}).");

            return self::FAILURE;
        }
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], ['name' => $this->option('name') ?: 'Job Hunter', 'password' => $password]);
        $this->info("User {$user->email} ready.");

        return self::SUCCESS;
    }
}
