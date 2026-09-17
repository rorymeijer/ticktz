<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of agents. Queues and assignment target teams so that staffing
 * changes do not require reconfiguring the service desk.
 *
 * @property string $name
 * @property string $slug
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'email', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'lead');
    }
}
