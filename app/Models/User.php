<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory,Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['teacher_access_pending' => 'boolean', 'email_verified_at' => 'datetime', 'erp_link_reviewed_at' => 'datetime', 'password' => 'hashed'];
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->name;
    }

    public function greetingName(): string
    {
        return preg_split('/\s+/u', trim($this->displayName()), 2)[0];
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'teacher'], true);
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'enrollments')->withTimestamps();
    }
}
