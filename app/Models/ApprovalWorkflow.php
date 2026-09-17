<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasRichText;
use App\Services\RichText\RichTextAttribute;
use Database\Factories\ApprovalWorkflowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who has to say yes, and in what order.
 *
 * A workflow is an ordered list of steps. One step with one approver is a
 * single approval; one step with several approvers is a parallel one; several
 * steps is a sequential one. There is no `mode` on the workflow itself,
 * because the shape of the steps already says which of the three it is.
 */
class ApprovalWorkflow extends Model
{
    /** @use HasFactory<ApprovalWorkflowFactory> */
    use Auditable, HasFactory, HasRichText;

    protected $fillable = [
        'name', 'name_translations', 'slug', 'description', 'instructions', 'is_active',
    ];

    /**
     * @return array<string, RichTextAttribute>
     */
    protected static function richTextAttributes(): array
    {
        return [
            'instructions' => new RichTextAttribute,
        ];
    }

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ApprovalStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<RequestType, $this> */
    public function requestTypes(): HasMany
    {
        return $this->hasMany(RequestType::class);
    }

    public function translatedName(?string $locale = null): string
    {
        return $this->name_translations[$locale ?? app()->getLocale()] ?? $this->name;
    }

    /**
     * How this workflow reads at a glance — the word the admin screen prints
     * next to it. Derived rather than stored: a workflow whose label and steps
     * could disagree is a workflow whose label is a lie.
     */
    public function shape(): string
    {
        $steps = $this->relationLoaded('steps') ? $this->steps : $this->steps()->get();

        if ($steps->count() > 1) {
            return 'sequential';
        }

        /** @var ApprovalStep|null $step */
        $step = $steps->first();

        if ($step === null) {
            return 'empty';
        }

        return $step->mode === ApprovalStep::ALL ? 'parallel' : 'single';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName(),
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'shape' => $this->shape(),
            'step_count' => $this->relationLoaded('steps')
                ? $this->steps->count()
                : ($this->steps_count ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toSummaryArray() + [
            'name_translations' => $this->name_translations ?? [],
            'instructions' => $this->instructions,
            'request_type_count' => $this->request_types_count ?? null,
            'steps' => $this->relationLoaded('steps')
                ? $this->steps->map(fn (ApprovalStep $step) => $step->toAdminArray())->all()
                : [],
        ];
    }
}
