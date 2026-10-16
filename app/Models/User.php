<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Mirrors the column defaults so new instances have a role before refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'player',
        'status' => 'active',
    ];

    /**
     * The attributes that are mass assignable. Role and status are changed
     * only through explicit, authorized admin actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pending_email',
        'status_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Staff permissions apply only to fully active (and, when required,
     * verified) accounts, so restricting a staff member removes admin access.
     */
    public function hasPermission(Permission $permission): bool
    {
        return $this->role->grants($permission) && $this->canParticipate();
    }

    public static function requiresEmailVerification(): bool
    {
        return (bool) config('competition.accounts.email_verification');
    }

    /**
     * True when the account may enter competitions and move money: an active
     * status and, when verification is required, a verified email address.
     */
    public function canParticipate(): bool
    {
        return $this->status->canParticipate()
            && (! static::requiresEmailVerification() || $this->hasVerifiedEmail());
    }

    /**
     * Verification mail is only sent while verification is enabled.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (static::requiresEmailVerification()) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * @return HasOne<Wallet, $this>
     */
    /**
     * Unread notification count, computed once per request (the header bell and the
     * page body both show it). Request-scoped, so it is never stale across requests.
     */
    public function unreadNotificationCount(): int
    {
        return $this->perRequest('unread-notifications', fn () => $this->unreadNotifications()->count());
    }

    /**
     * The latest notifications, loaded once per request.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function recentNotifications(int $limit = 5): Collection
    {
        return $this->perRequest('recent-notifications:'.$limit, fn () => $this->notifications()->latest()->limit($limit)->get());
    }

    /**
     * Memoizes a value on the current request (never across requests or users).
     */
    protected function perRequest(string $key, \Closure $compute): mixed
    {
        $attributes = request()->attributes;
        $key = 'user:'.$this->id.':'.$key;
        if (! $attributes->has($key)) {
            $attributes->set($key, $compute());
        }

        return $attributes->get($key);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->where('type', WalletType::User);
    }

    /**
     * Active payout accounts.
     *
     * @return HasMany<PayoutDestination, $this>
     */
    public function payoutDestinations(): HasMany
    {
        return $this->hasMany(PayoutDestination::class)->where('status', 'active');
    }

    /**
     * @return HasMany<RoomParticipant, $this>
     */
    public function roomParticipations(): HasMany
    {
        return $this->hasMany(RoomParticipant::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Name safe to show to other players.
     */
    public function publicName(): string
    {
        return $this->display_name ?: 'Player '.$this->id;
    }
}
