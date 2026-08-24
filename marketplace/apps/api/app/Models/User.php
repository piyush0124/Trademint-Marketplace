<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function business(): HasOne
    {
        return $this->hasOne(Business::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class, 'customer_id');
    }

    /** Enquiries this user raised as a customer (Phase 5). */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class, 'customer_id');
    }

    /** Quotes received as a customer. A business's own quotes hang off Business. */
    public function receivedQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'customer_id');
    }

    /**
     * Send the reset link through our own notification.
     *
     * Overridden because the inherited implementation (from the CanResetPassword
     * trait on Illuminate\Foundation\Auth\User) links to a `password.reset` Blade
     * route that does not exist in this headless API. See ResetPasswordNotification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }
}
