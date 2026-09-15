<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['nullable', Rule::in(array_keys(config('ticktz.locales')))],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')],
        ];
    }
}
