<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $directoryManaged = (bool) ($this->user()->ldap_dn ?? false);

        return [
            'name' => [$directoryManaged ? 'nullable' : 'required', 'string', 'max:255'],
            'email' => [
                $directoryManaged ? 'nullable' : 'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('ticktz.locales')))],
        ];
    }

    /**
     * Directory-managed identity fields are never written back from the UI.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        if ($this->user()->ldap_dn ?? false) {
            unset($validated['name'], $validated['email']);
        }

        return $validated;
    }
}
