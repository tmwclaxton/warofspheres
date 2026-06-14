<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'game_display_name', 'email', 'workos_id', 'avatar', 'profile_uuid'])]
#[Hidden(['workos_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! is_string($user->profile_uuid) || $user->profile_uuid === '') {
                $user->profile_uuid = (string) Str::uuid();
            }

            if (! is_string($user->game_display_name) || $user->game_display_name === '') {
                $user->game_display_name = self::generatePlayerTag();
            }
        });
    }

    public static function generatePlayerTag(): string
    {
        return 'Commander#'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @var list<string>
     */
    private const ADMIN_EMAILS = [
        'tmwclaxton@gmail.com',
        'toby@grantgunner.org',
    ];

    public function isAdmin(): bool
    {
        return in_array($this->email, self::ADMIN_EMAILS, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Map, $this>
     */
    public function maps(): HasMany
    {
        return $this->hasMany(Map::class);
    }

    /**
     * @return HasMany<GamePlayer, $this>
     */
    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function gamesWon(): HasMany
    {
        return $this->hasMany(Game::class, 'winner_user_id');
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function gamesHosted(): HasMany
    {
        return $this->hasMany(Game::class, 'host_user_id');
    }
}
