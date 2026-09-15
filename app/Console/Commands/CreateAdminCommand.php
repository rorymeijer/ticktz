<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Bootstraps the first administrator on a fresh instance, and promotes an
 * existing account afterwards. Without this there is no way in: Ticktz ships
 * with no default credentials on purpose.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'ticktz:admin
                            {--name= : Full name}
                            {--email= : E-mail address}
                            {--password= : Password (prompted when omitted)}
                            {--promote : Promote the existing account with this e-mail instead of creating one}';

    protected $description = 'Create an administrator account (or promote an existing one)';

    public function handle(AuditLogger $audit): int
    {
        $adminRole = Role::query()->where('name', Role::ADMIN)->first();

        if (! $adminRole) {
            $this->components->error('The administrator role is missing. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) ($this->option('email') ?: text(
            label: 'E-mail address',
            required: true,
        ))));

        $existing = User::query()->withTrashed()->where('email', $email)->first();

        if ($existing) {
            if (! $this->option('promote') && ! $this->confirm("An account already exists for {$email}. Promote it to administrator?", true)) {
                return self::FAILURE;
            }

            $existing->restore();
            $existing->forceFill(['is_active' => true])->save();
            $existing->assignRole(Role::ADMIN);

            $audit->as('system', 'Console')->log($existing, 'role.granted', 'Promoted to administrator from the console');

            $this->components->info("{$existing->name} is now an administrator.");

            return self::SUCCESS;
        }

        $name = (string) ($this->option('name') ?: text(label: 'Full name', required: true));
        $plainPassword = (string) ($this->option('password') ?: promptPassword(label: 'Password', required: true));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
            'locale' => config('app.locale'),
            'directory' => 'local',
            'is_active' => true,
        ]);

        // Not mass-assignable on purpose: verification is never something a
        // request payload gets to set.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->roles()->attach($adminRole);

        $audit->as('system', 'Console')->created($user, 'Administrator created from the console');

        $this->components->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
