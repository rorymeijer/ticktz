<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\RichText\RichTextProfile;
use App\Services\RichText\RichTextSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rich text field that has to contain something.
 *
 * `required` is not enough on its own. An editor that has been focused and
 * then emptied posts `<p></p>`, and one that has been focused and never typed
 * into posts `<p><br></p>` — both non-empty strings, both nothing a reader
 * would see. Without this, "send reply" on an empty box files an empty comment
 * and mails it to the customer.
 *
 * Used alongside `required` rather than instead of it, so a missing field and
 * a blank one each get the message that fits.
 */
class RichText implements ValidationRule
{
    public function __construct(
        /**
         * The profile the field will be stored under.
         *
         * Emptiness has to be judged on what survives sanitising, not on what
         * arrived: `<script>alert(1)</script>` is a non-empty string with
         * non-empty text inside it, and passes any check made on the raw
         * value — and then stores as nothing at all.
         */
        private readonly RichTextProfile $profile = RichTextProfile::Basic,
        /** The largest the stored markup may be. */
        private readonly int $max = 100_000,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (mb_strlen($value) > $this->max) {
            $fail('validation.max.string')->translate(['max' => $this->max]);

            return;
        }

        if (app(RichTextSanitizer::class)->clean($value, $this->profile) === '') {
            $fail('validation.required')->translate();
        }
    }
}
