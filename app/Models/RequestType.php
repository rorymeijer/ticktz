<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasRichText;
use App\Services\RichText\RichTextAttribute;
use Database\Factories\RequestTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing a requester can ask for, and the shape of the form that asks it.
 *
 * A request type also carries the routing: which queue, team, workflow and
 * priority a submission lands on. That is what turns "I need a laptop" into a
 * ticket on the right desk without an agent triaging it first.
 *
 * @property array<int, int>|null $organization_ids
 */
class RequestType extends Model
{
    /** @use HasFactory<RequestTypeFactory> */
    use Auditable, HasFactory, HasRichText;

    protected $fillable = [
        'portal_category_id', 'name', 'name_translations', 'slug', 'description',
        'description_translations', 'instructions', 'instructions_translations', 'icon',
        'queue_id', 'team_id', 'workflow_id', 'approval_workflow_id', 'priority_id', 'allow_priority_choice',
        'subject_template', 'visibility', 'organization_ids', 'is_active', 'position',
    ];

    /**
     * @return array<string, RichTextAttribute>
     */
    protected static function richTextAttributes(): array
    {
        return [
            'instructions' => new RichTextAttribute(translations: 'instructions_translations'),
        ];
    }

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'description_translations' => 'array',
            'instructions_translations' => 'array',
            'organization_ids' => 'array',
            'allow_priority_choice' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<PortalCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PortalCategory::class, 'portal_category_id');
    }

    /**
     * @return BelongsToMany<CustomField, $this>
     */
    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'request_type_fields')
            ->withPivot(['is_required', 'help_text', 'position'])
            ->orderBy('request_type_fields.position');
    }

    /**
     * The approval this request type opens on every ticket filed from it.
     *
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /**
     * @return BelongsTo<Queue, $this>
     */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Request types the given user may submit.
     *
     * @param  Builder<RequestType>  $query
     */
    public function scopeAvailableTo(Builder $query, ?User $user): void
    {
        $query->where('is_active', true);

        if ($user?->isAgent()) {
            return;
        }

        $query->where('visibility', '!=', 'agents');

        // An organisation-scoped type is only offered to its own members.
        $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('visibility', 'everyone');

            if ($user?->organization_id) {
                $scoped->orWhere(function (Builder $restricted) use ($user): void {
                    $restricted->where('visibility', 'organizations')
                        ->whereJsonContains('organization_ids', $user->organization_id);
                });
            }
        });
    }

    public function translatedName(?string $locale = null): string
    {
        return $this->name_translations[$locale ?? app()->getLocale()] ?? $this->name;
    }

    public function translatedDescription(?string $locale = null): ?string
    {
        return $this->description_translations[$locale ?? app()->getLocale()] ?? $this->description;
    }

    public function translatedInstructions(?string $locale = null): ?string
    {
        return $this->instructions_translations[$locale ?? app()->getLocale()] ?? $this->instructions;
    }

    /**
     * Render the ticket subject from the template, e.g.
     * "Laptop for :employee" with the answer to the `employee` field.
     *
     * @param  array<string, mixed>  $answers
     */
    public function renderSubject(array $answers, string $fallback): string
    {
        $template = trim((string) $this->subject_template);

        if ($template === '') {
            return $fallback;
        }

        $subject = preg_replace_callback(
            '/:([a-z0-9_]+)/i',
            function (array $matches) use ($answers): string {
                $value = $answers[$matches[1]] ?? null;

                if (is_array($value)) {
                    return implode(', ', $value);
                }

                return $value === null ? '' : (string) $value;
            },
            $template,
        ) ?? $template;

        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);

        return $subject === '' ? $fallback : mb_substr($subject, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPortalArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName(),
            'slug' => $this->slug,
            'description' => $this->translatedDescription(),
            'icon' => $this->icon,
            'category' => $this->relationLoaded('category') ? $this->category?->toPortalArray() : null,
        ];
    }

    /**
     * The form definition the portal renders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFields(): array
    {
        return $this->fields
            ->filter(fn (CustomField $field) => $field->is_active)
            ->map(fn (CustomField $field) => $field->toFormArray(
                $field->pivot->is_required === null ? null : (bool) $field->pivot->is_required,
                $field->pivot->help_text,
            ))
            ->values()
            ->all();
    }
}
