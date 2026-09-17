<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => EmailTemplate::KEYS[0],
            'email_channel_id' => null,
            'locale' => 'en',
            'subject' => '[{{ ticket.key }}] {{ ticket.subject }}',
            'body' => "Hello {{ requester.first_name }},\n\n{{ comment.body }}",
            'is_active' => true,
        ];
    }
}
