<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasRichText;
use App\Services\RichText\RichTextAttribute;
use App\Services\RichText\RichTextProfile;
use App\Services\Tickets\TicketPlaceholders;
use Database\Factories\ReplyTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply the desk sends often enough to write down once.
 *
 * Two things use these, and that is the whole design: an agent picks one in
 * the reply box, and an automation rule sends one unattended. Keeping them in
 * one table means the acknowledgement a rule fires at three in the morning is
 * the same text an agent would have sent at ten — and when the wording is
 * wrong, it is wrong in one place.
 *
 * `body` is sanitised HTML with `{{ placeholder }}` tokens in it, filled at
 * the moment of use by {@see TicketPlaceholders}. The
 * tokens survive sanitising because they are text, not markup.
 *
 * @property string $body sanitised HTML
 * @property string|null $body_text the same content, flattened
 */
class ReplyTemplate extends Model
{
    /** @use HasFactory<ReplyTemplateFactory> */
    use Auditable, HasFactory, HasRichText;

    protected $fillable = [
        'name', 'slug', 'description', 'body', 'team_id', 'locale',
        'is_internal', 'is_active', 'position', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The Basic profile, and no images. A canned reply is a message, not a
     * document, and an image pasted into a template would be one file
     * referenced by every ticket that ever used it — the one case where
     * deleting a ticket's attachments could blank out somebody else's.
     *
     * @return array<string, RichTextAttribute>
     */
    protected static function richTextAttributes(): array
    {
        return ['body' => new RichTextAttribute(text: 'body_text', profile: RichTextProfile::Basic)];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The templates this user may reach for.
     *
     * Team scoping is an offer, not a wall: a template belongs to a team so
     * that a hardware agent is not scrolling past finance's wording, and an
     * agent who may see a ticket may see the canned answers for it. The
     * permission that matters is the one on the reply itself, which the
     * comment policy already decides.
     *
     * @param  Builder<ReplyTemplate>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        $teamIds = $user->teamIds();

        $query->where('is_active', true)
            ->where(function (Builder $scoped) use ($teamIds): void {
                $scoped->whereNull('team_id')->orWhereIn('team_id', $teamIds);
            })
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * Narrow to the templates written for the language somebody reads.
     *
     * The reader here is the requester, never the agent. A Dutch agent
     * answering an English customer needs the English wording, and a picker
     * that offered them Dutch because Ticktz is in Dutch for *them* would be
     * offering the one thing they must not send. A template with no locale is
     * always offered: that is what "no locale" means.
     *
     * @param  Builder<ReplyTemplate>  $query
     */
    public function scopeForLocale(Builder $query, ?string $locale): void
    {
        $query->where(function (Builder $scoped) use ($locale): void {
            $scoped->whereNull('locale');

            if ($locale !== null && $locale !== '') {
                $scoped->orWhere('locale', $locale);
            }
        });
    }

    /**
     * The templates a rule may send on this ticket.
     *
     * A rule has no team of its own, so the ticket's is the one that counts:
     * an unscoped template, or one belonging to the team the ticket sits with.
     * A rule pointed at a template that later moved to another team stops
     * matching rather than quietly sending finance's wording to a hardware
     * customer.
     *
     * Language is deliberately not filtered here. A rule names one template,
     * and an administrator who picked the Dutch one meant the Dutch one;
     * quietly substituting another because the requester reads English would
     * be the automation deciding what the desk says. `locale` narrows what an
     * agent is *offered* — see {@see scopeForLocale()} — and a desk that
     * wants a rule to answer in the requester's language writes one rule per
     * language with a `requester` or `organization` condition on it.
     *
     * @param  Builder<ReplyTemplate>  $query
     */
    public function scopeSendableOn(Builder $query, Ticket $ticket): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $scoped) use ($ticket): void {
                $scoped->whereNull('team_id');

                if ($ticket->team_id !== null) {
                    $scoped->orWhere('team_id', $ticket->team_id);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'body' => $this->body,
            'team_id' => $this->team_id,
            'team' => $this->relationLoaded('team') ? $this->team?->only('id', 'name') : null,
            'locale' => $this->locale,
            'is_internal' => $this->is_internal,
            'is_active' => $this->is_active,
            'position' => $this->position,
        ];
    }

    /**
     * What an agent's template picker needs: enough to choose by, and the body
     * already filled in for the ticket in front of them.
     *
     * The rendering happens on the server because the placeholder vocabulary
     * lives there. Sending the raw template to the browser and substituting in
     * React would be a second implementation of the one thing this feature is
     * for.
     *
     * @return array<string, mixed>
     */
    public function toPickerArray(string $body): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_internal' => $this->is_internal,
            'body' => $body,
        ];
    }
}
