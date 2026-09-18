<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ReplyTemplate;
use Illuminate\Database\Seeder;

/**
 * Four replies every desk sends, in both languages.
 *
 * Seeded rather than left empty for the same reason the SLA policy is: a
 * feature that starts blank is a feature nobody turns on. These are also the
 * worked examples — somebody who reads them learns what a placeholder is and
 * what the difference between a reply and an internal note buys them, without
 * reading the manual first.
 *
 * Idempotent, like every other seeder here. Editing a seeded template is
 * expected; `updateOrCreate` on the slug would undo that edit on the next
 * deploy, so an existing slug is left exactly as the desk left it.
 */
class ReplyTemplateSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const TEMPLATES = [
        [
            'slug' => 'acknowledge',
            'position' => 10,
            'is_internal' => false,
            'en' => [
                'name' => 'We have your request',
                'description' => 'A first reply that says somebody has it.',
                'body' => '<p>Hi {{ requester.first_name }},</p><p>Thanks for getting in touch. Your request is logged as <strong>{{ ticket.key }}</strong> and we are looking at it.</p><p>You can follow it here: {{ ticket.portal_url }}</p><p>Kind regards,<br>{{ app.name }}</p>',
            ],
            'nl' => [
                'name' => 'We hebben je melding',
                'description' => 'Een eerste reactie die zegt dat iemand ernaar kijkt.',
                'body' => '<p>Hallo {{ requester.first_name }},</p><p>Bedankt voor je bericht. Je melding staat bij ons onder <strong>{{ ticket.key }}</strong> en we kijken ernaar.</p><p>Je kunt hem hier volgen: {{ ticket.portal_url }}</p><p>Met vriendelijke groet,<br>{{ app.name }}</p>',
            ],
        ],
        [
            'slug' => 'need-more-detail',
            'position' => 20,
            'is_internal' => false,
            'en' => [
                'name' => 'We need a bit more detail',
                'description' => 'When the request does not say enough to start on.',
                'body' => '<p>Hi {{ requester.first_name }},</p><p>To pick up {{ ticket.key }} we need a little more to go on. Could you tell us:</p><ul><li>what you were doing when it happened;</li><li>what you expected to happen instead;</li><li>whether anyone else is affected.</li></ul><p>A screenshot usually saves us both a round.</p><p>Kind regards,<br>{{ agent.first_name }}</p>',
            ],
            'nl' => [
                'name' => 'We hebben iets meer informatie nodig',
                'description' => 'Als de melding te weinig zegt om mee te beginnen.',
                'body' => '<p>Hallo {{ requester.first_name }},</p><p>Om met {{ ticket.key }} aan de slag te gaan hebben we iets meer nodig. Kun je ons vertellen:</p><ul><li>wat je aan het doen was toen het gebeurde;</li><li>wat je in plaats daarvan verwachtte;</li><li>of er nog iemand last van heeft.</li></ul><p>Een schermafbeelding scheelt ons meestal een ronde.</p><p>Met vriendelijke groet,<br>{{ agent.first_name }}</p>',
            ],
        ],
        [
            'slug' => 'resolved',
            'position' => 30,
            'is_internal' => false,
            'en' => [
                'name' => 'This should be sorted',
                'description' => 'A closing reply that invites a reopen.',
                'body' => '<p>Hi {{ requester.first_name }},</p><p>We believe {{ ticket.key }} is sorted. If it turns out it is not, reply to this message and the request opens again — there is no need to raise a new one.</p><p>Kind regards,<br>{{ agent.first_name }}</p>',
            ],
            'nl' => [
                'name' => 'Dit zou opgelost moeten zijn',
                'description' => 'Een afsluitende reactie waarop de melder kan terugkomen.',
                'body' => '<p>Hallo {{ requester.first_name }},</p><p>We denken dat {{ ticket.key }} opgelost is. Blijkt dat niet zo, reageer dan op dit bericht en de melding gaat weer open — je hoeft geen nieuwe aan te maken.</p><p>Met vriendelijke groet,<br>{{ agent.first_name }}</p>',
            ],
        ],
        [
            'slug' => 'waiting-on-supplier',
            'position' => 40,
            // An internal note, and the example of why that flag exists: this
            // is the sentence a desk must be able to write down without any
            // risk of it reaching the customer.
            'is_internal' => true,
            'en' => [
                'name' => 'Waiting on the supplier',
                'description' => 'An internal note. Never sent to the requester.',
                'body' => '<p>Passed to the supplier. Waiting on them; nothing to tell {{ requester.first_name }} yet.</p><p>Chase if there is no answer by tomorrow.</p>',
            ],
            'nl' => [
                'name' => 'Wachten op de leverancier',
                'description' => 'Een interne notitie. Gaat nooit naar de melder.',
                'body' => '<p>Doorgezet naar de leverancier. We wachten op hen; nog niets om {{ requester.first_name }} te melden.</p><p>Morgen rappelleren als er geen antwoord is.</p>',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $template) {
            foreach (['en', 'nl'] as $locale) {
                $slug = "{$template['slug']}-{$locale}";

                if (ReplyTemplate::query()->where('slug', $slug)->exists()) {
                    continue;
                }

                ReplyTemplate::query()->create([
                    'slug' => $slug,
                    'name' => $template[$locale]['name'],
                    'description' => $template[$locale]['description'],
                    'body' => $template[$locale]['body'],
                    'locale' => $locale,
                    'is_internal' => $template['is_internal'],
                    'is_active' => true,
                    'position' => $template['position'],
                ]);
            }
        }
    }
}
