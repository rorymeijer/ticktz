<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $subject */
        $subject = $this->route('user');

        return $subject !== null && ($this->user()?->can('update', $subject) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($subject->getKey()),
            ],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($subject->getKey())],
            // Blank means "leave the current password alone".
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'locale' => ['nullable', Rule::in(array_keys(config('ticktz.locales')))],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            // Nobody is their own manager. The approval step would drop them
            // anyway — an approver who is also the requester is filtered out —
            // but the step would then resolve to nobody and the approval would
            // quietly not happen, which is a worse way to find out.
            'manager_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$this->route('user')?->getKey()]),
            ],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'signature' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')],
        ];
    }
}
