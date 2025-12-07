<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'password' => 'hashed',
        ];
    }

    /**
     * Get the credential assigned to this user
     */
    public function credentionalPool()
    {
        return $this->hasOne(CredentionalPool::class);
    }

    /**
     * Get the accounts for this user
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Get the access keys for this user
     */
    public function accessKeys()
    {
        return $this->hasMany(AccessKey::class);
    }

    /**
     * Get the primary account for this user
     */
    public function primaryAccount()
    {
        return $this->hasOne(Account::class)->where('is_active', true)->oldest();
    }
}
