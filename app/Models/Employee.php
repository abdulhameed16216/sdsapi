<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Employee extends Authenticatable implements JWTSubject
{
    use HasFactory, HasApiTokens, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_code',
        'name',
        'mobile_number',
        'email',
        'blood_group',
        'date_of_birth',
        'date_of_joining',
        'address',
        'city',
        'role_id',
        'id_proof',
        'employee_image',
        'profile_photo',
        'status',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // No hidden fields for employees table
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'date_of_joining' => 'date',
    ];

    /**
     * Get the role that owns the employee.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the user account for this employee.
     */
    public function user()
    {
        return $this->hasOne(User::class);
    }

    /**
     * Check if employee is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active employees
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for employees by role
     */
    public function scopeByRole($query, $roleId)
    {
        return $query->where('role_id', $roleId);
    }

    /**
     * Get the tokenable model that the access token belongs to.
     */
    public function tokenable()
    {
        return $this->morphMany(\Laravel\Sanctum\PersonalAccessToken::class, 'tokenable');
    }

    /**
     * Create a new personal access token for the user.
     */
    public function createToken(string $name, array $abilities = ['*'], \DateTimeInterface $expiresAt = null)
    {
        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken = \Laravel\Sanctum\Str::random(40)),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        // Add employee data to the token payload
        $token->forceFill([
            'tokenable_id' => $this->id,
            'tokenable_type' => static::class,
        ])->save();

        return new \Laravel\Sanctum\NewAccessToken($token, $plainTextToken);
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [
            'employee' => [
                'id' => $this->id,
                'name' => $this->name,
                'username' => $this->username,
                'email' => $this->email,
                'mobile_number' => $this->mobile_number,
                'blood_group' => $this->blood_group,
                'date_of_birth' => $this->date_of_birth,
                'date_of_joining' => $this->date_of_joining,
                'address' => $this->address,
                'city' => $this->city,
                'role' => [
                    'id' => $this->role->id ?? null,
                    'name' => $this->role->name ?? null,
                    'slug' => $this->role->slug ?? null,
                    'display_name' => $this->role->display_name ?? null,
                ],
                'status' => $this->status,
                'last_login_at' => $this->last_login_at,
            ]
        ];
    }
}
