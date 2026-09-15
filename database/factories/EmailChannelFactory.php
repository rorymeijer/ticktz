<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailChannel;
use App\Services\Mail\InboundMessageProcessor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailChannel>
 */
class EmailChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $slug = Str::slug($name);

        return [
            'name' => ucfirst($name),
            'slug' => $slug,
            'address' => $slug.'@'.fake()->safeEmailDomain(),
            'from_name' => ucfirst($name),
            'reply_to' => null,
            'is_active' => true,
            'imap_enabled' => false,
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
            'imap_folder' => 'INBOX',
            'imap_delete_after_processing' => false,
            'auto_provision_requesters' => true,
            'ignore_senders' => [],
        ];
    }

    /**
     * A mailbox that is actually read in — the IMAP credentials are dummies,
     * because tests drive {@see InboundMessageProcessor}
     * directly rather than talking to a server.
     */
    public function reading(): static
    {
        return $this->state(fn (array $attributes) => [
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => $attributes['address'] ?? 'inbox@example.test',
            'imap_password' => 'secret',
        ]);
    }

    /**
     * A mailbox with its own SMTP server rather than the application default.
     */
    public function sending(): static
    {
        return $this->state(fn () => [
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'mailer',
            'smtp_password' => 'secret',
        ]);
    }
}
