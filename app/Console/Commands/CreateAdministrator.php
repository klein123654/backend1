<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-administrator')]
#[Description('Create an administrator account using interactive prompts')]
class CreateAdministrator extends Command
{
    public function handle(): int
    {
        $name = trim((string) $this->ask('Administrator name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));
        $password = (string) $this->secret('Administrator password');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        if (! $this->confirm('Create this administrator account?', true)) {
            $this->components->warn('Administrator creation cancelled.');

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'account_status' => 'active',
        ]);

        $this->components->info('Administrator account created.');

        return self::SUCCESS;
    }
}
