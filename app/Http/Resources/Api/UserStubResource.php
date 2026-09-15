<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person, as referenced from somewhere else.
 *
 * Three fields, on purpose. An integration needs to know who a ticket belongs
 * to; it does not need their phone number, their manager or whether they came
 * from LDAP, and an API that hands out a full staff directory as a side effect
 * of listing tickets is a data export nobody asked for.
 *
 * @mixin User
 */
class UserStubResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
