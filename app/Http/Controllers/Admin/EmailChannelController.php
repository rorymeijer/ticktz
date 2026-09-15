<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Mail\PollMailboxJob;
use App\Models\EmailChannel;
use App\Models\EmailTemplate;
use App\Models\InboundMessage;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\Mail\MailboxPoller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Mailbox configuration, notification templates and the inbound message log.
 *
 * Passwords are write-only throughout: they are encrypted at rest, reduced to
 * a boolean on the way out, and an empty field on update means "keep the
 * stored password" rather than "clear it".
 */
class EmailChannelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('email.manage');

        return Inertia::render('Admin/Email/Index', [
            'channels' => EmailChannel::query()
                ->orderBy('id')
                ->get()
                ->map(fn (EmailChannel $channel) => $channel->toAdminArray())
                ->all(),
            'templates' => EmailTemplate::query()
                ->orderBy('key')
                ->get()
                ->map(fn (EmailTemplate $template) => [
                    'id' => $template->id,
                    'key' => $template->key,
                    'email_channel_id' => $template->email_channel_id,
                    'locale' => $template->locale,
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'is_active' => $template->is_active,
                ])->all(),
            'templateKeys' => EmailTemplate::KEYS,
            'placeholders' => $this->placeholderList(),
            'recentMessages' => InboundMessage::query()
                ->with('ticket:id,key')
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (InboundMessage $message) => $message->toAdminArray())
                ->all(),
            'options' => [
                'queues' => Queue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'requestTypes' => RequestType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
                'priorities' => Priority::query()->orderBy('level')->get()
                    ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
                'locales' => array_keys(config('ticktz.locales')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('email.manage');

        $channel = EmailChannel::query()->create($this->validateChannel($request, null));

        $this->audit->created($channel, "Created mailbox {$channel->address}");

        return back()->with('success', __('admin.email.created', ['name' => $channel->name]));
    }

    public function update(Request $request, EmailChannel $channel): RedirectResponse
    {
        $this->authorize('email.manage');

        $data = $this->validateChannel($request, $channel);

        // An empty password field means "leave it alone", never "clear it".
        foreach (['smtp_password', 'imap_password'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $channel->fill($data)->save();

        $this->audit->updated($channel, "Updated mailbox {$channel->address}");

        return back()->with('success', __('admin.email.updated', ['name' => $channel->name]));
    }

    public function destroy(EmailChannel $channel): RedirectResponse
    {
        $this->authorize('email.manage');

        $this->audit->deleted($channel, "Deleted mailbox {$channel->address}");
        $channel->delete();

        return back()->with('success', __('admin.email.deleted'));
    }

    /**
     * Send a message to the signed-in administrator through this channel.
     */
    public function testSending(Request $request, EmailChannel $channel): RedirectResponse
    {
        $this->authorize('email.manage');

        try {
            $mailerConfig = $channel->mailerConfig();

            if ($mailerConfig) {
                config(['mail.mailers.ticktz-test' => $mailerConfig]);
            }

            Mail::mailer($mailerConfig ? 'ticktz-test' : null)
                ->raw(
                    __('admin.email.test_body', ['name' => $channel->name, 'app' => config('app.name')]),
                    fn ($message) => $message
                        ->to($request->user()->email)
                        ->from($channel->address, $channel->from_name ?: config('app.name'))
                        ->subject(__('admin.email.test_subject', ['app' => config('app.name')])),
                );

            return back()->with('success', __('admin.email.test_sent', ['email' => $request->user()->email]));
        } catch (Throwable $exception) {
            return back()->with('error', __('admin.email.test_failed', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * Poll the mailbox now instead of waiting for the scheduler.
     */
    public function poll(EmailChannel $channel, MailboxPoller $poller): RedirectResponse
    {
        $this->authorize('email.manage');

        if (! $channel->imap_enabled) {
            return back()->with('error', __('admin.email.imap_disabled'));
        }

        try {
            $stats = $poller->poll($channel, limit: 25);

            $this->audit->log($channel, 'mailbox.polled', "Polled mailbox {$channel->address}");

            return back()->with('success', __('admin.email.poll_result', [
                'fetched' => $stats['fetched'],
                'processed' => $stats['processed'],
            ]));
        } catch (Throwable $exception) {
            return back()->with('error', __('admin.email.poll_failed', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * Queue a poll for every mailbox, the same way the scheduler does.
     */
    public function pollAll(): RedirectResponse
    {
        $this->authorize('email.manage');

        $channels = EmailChannel::query()
            ->where('is_active', true)
            ->where('imap_enabled', true)
            ->pluck('id');

        $channels->each(fn (int $id) => PollMailboxJob::dispatch($id));

        return back()->with('info', __('admin.email.poll_queued', ['count' => $channels->count()]));
    }

    // -----------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------

    public function storeTemplate(Request $request): RedirectResponse
    {
        $this->authorize('email.manage');

        $data = $this->validateTemplate($request, null);

        $template = EmailTemplate::query()->updateOrCreate(
            [
                'key' => $data['key'],
                'email_channel_id' => $data['email_channel_id'] ?? null,
                'locale' => $data['locale'],
            ],
            $data,
        );

        $this->audit->updated($template, "Saved e-mail template {$template->key}");

        return back()->with('success', __('admin.email.templates.saved'));
    }

    public function updateTemplate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $this->authorize('email.manage');

        $template->fill($this->validateTemplate($request, $template))->save();

        $this->audit->updated($template, "Updated e-mail template {$template->key}");

        return back()->with('success', __('admin.email.templates.saved'));
    }

    public function destroyTemplate(EmailTemplate $template): RedirectResponse
    {
        $this->authorize('email.manage');

        // Removing an override falls back to the packaged default, so nothing
        // stops sending.
        $this->audit->deleted($template, "Removed e-mail template override {$template->key}");
        $template->delete();

        return back()->with('success', __('admin.email.templates.reset'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function placeholderList(): array
    {
        return [
            'ticket.key', 'ticket.subject', 'ticket.description', 'ticket.status',
            'ticket.priority', 'ticket.queue', 'ticket.portal_url', 'ticket.agent_url',
            'requester.name', 'requester.first_name', 'requester.email',
            'assignee.name', 'recipient.name', 'recipient.first_name',
            'comment.body', 'comment.author', 'app.name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateChannel(Request $request, ?EmailChannel $channel): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('email_channels', 'slug')->ignore($channel?->getKey()),
            ],
            'address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],

            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['required', Rule::in(['none', 'ssl', 'tls'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],

            'imap_enabled' => ['boolean'],
            'imap_host' => ['nullable', 'required_if:imap_enabled,true', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['required', Rule::in(['none', 'ssl', 'tls', 'starttls'])],
            'imap_validate_cert' => ['boolean'],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'imap_folder' => ['required', 'string', 'max:255'],
            'imap_processed_folder' => ['nullable', 'string', 'max:255'],
            'imap_delete_after_processing' => ['boolean'],

            'queue_id' => ['nullable', 'integer', Rule::exists('queues', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'request_type_id' => ['nullable', 'integer', Rule::exists('request_types', 'id')],
            'priority_id' => ['nullable', 'integer', Rule::exists('priorities', 'id')],
            'auto_provision_requesters' => ['boolean'],
            'ignore_senders' => ['array'],
            'ignore_senders.*' => ['string', 'max:255'],
        ]);

        $validated['ignore_senders'] = array_values(array_filter(array_map(
            'trim',
            $validated['ignore_senders'] ?? [],
        )));

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request, ?EmailTemplate $template): array
    {
        return $request->validate([
            'key' => ['required', Rule::in(EmailTemplate::KEYS)],
            'email_channel_id' => ['nullable', 'integer', Rule::exists('email_channels', 'id')],
            'locale' => ['required', Rule::in(array_keys(config('ticktz.locales')))],
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]);
    }
}
