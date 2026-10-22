<?php

namespace App\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Production first-run: create the very first Super Admin.
 *
 * Deployments that boot WITHOUT demo seeding (SEED_DEMO off) have an
 * empty users table, so nobody can sign in. This command is the
 * supported way to create the initial platform owner. It refuses to
 * run once any super_admin exists, so it can never become a backdoor
 * for minting extra platform owners on a live school.
 *
 * Usage:
 *   php artisan hackstra:create-super-admin \
 *       --name="School Owner" --email=owner@example.com --password=...
 * or via env: HACKSTRA_ADMIN_NAME / HACKSTRA_ADMIN_EMAIL /
 * HACKSTRA_ADMIN_PASSWORD. With no password supplied on an
 * interactive terminal, one is asked for (hidden input).
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'hackstra:create-super-admin
                            {--name= : Full name of the Super Admin}
                            {--email= : Login email}
                            {--username= : Login username (defaults to the email)}
                            {--password= : Password (prefer HACKSTRA_ADMIN_PASSWORD in scripts)}';

    protected $description = 'Create the first Super Admin account (refuses if one already exists)';

    public function handle()
    {
        if (User::where('user_type', 'super_admin')->exists()) {
            $this->error('A Super Admin already exists. Refusing to create another one.');
            $this->line('Additional staff accounts are created from inside the app (Users menu).');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: env('HACKSTRA_ADMIN_NAME');
        $email = $this->option('email') ?: env('HACKSTRA_ADMIN_EMAIL');
        $password = $this->option('password') ?: env('HACKSTRA_ADMIN_PASSWORD');

        if (! $name && $this->input->isInteractive()) {
            $name = $this->ask('Super Admin full name');
        }
        if (! $email && $this->input->isInteractive()) {
            $email = $this->ask('Super Admin email');
        }
        if (! $password && $this->input->isInteractive()) {
            $password = $this->secret('Super Admin password (min 8 characters)');
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:100|unique:users,email',
                'password' => 'required|string|min:8',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            $this->line('Supply --name, --email and --password (or the HACKSTRA_ADMIN_* env vars).');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'username' => $this->option('username') ?: $email,
            'password' => Hash::make($password),
            'user_type' => 'super_admin',
            'code' => strtoupper(Str::random(10)),
            'remember_token' => Str::random(10),
        ]);

        $this->info("Super Admin created: {$user->name} <{$user->email}> (id {$user->id}).");
        $this->line('Sign in at /login with this email and the password you supplied.');

        return self::SUCCESS;
    }
}
