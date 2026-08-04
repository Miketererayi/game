<?php

namespace App\Models;

use App\Enums\PlayerRole;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authenticatable only for broadcast channel authorization — guests hold a
 * GamePlayer identified by session token, never a User account.
 */
class GamePlayer extends Model implements Authenticatable
{
    use AuthenticatableTrait;
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => PlayerRole::class,
            'is_host' => 'boolean',
            'is_bot' => 'boolean',
            'is_ready' => 'boolean',
            'is_connected' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(
            fn () => $this->user?->name ?? $this->guest_name ?? 'Player '.$this->id
        );
    }
}
