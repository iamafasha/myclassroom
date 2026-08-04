<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'invited_at', 'invited_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * An invited person who has not signed up yet: the row exists so they can be put in a
     * class, but there is no password to log in with until they finish registering.
     */
    public function isPendingInvite(): bool
    {
        return $this->password === null;
    }

    public function scopePendingInvites($query)
    {
        return $query->whereNull('password');
    }

    /** Invited people have no name until they register, so fall back to their email. */
    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }

    /** Whoever sent the invitation, while the account is still pending. */
    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class);
    }

    /** Sessions this user asked for, as a student. */
    public function sessionRequests()
    {
        return $this->hasMany(MentorSession::class, 'student_id');
    }

    /** Sessions asked of this user, as the mentor who owns the course. */
    public function mentorSessions()
    {
        return $this->hasMany(MentorSession::class, 'mentor_id');
    }
}
