<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    protected $fillable = [
        'user_id',
        'funnel_id',
        'email',
        'name',
        'phone',
        'source',
        'status',
        'tags',
        'metadata',
        'ip_address',
        'user_agent',
        'last_submitted_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'last_submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Contact $contact): void {
            $contact->automationRuns()->update([
                'contact_id' => null,
                'context' => '[]',
            ]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ContactSubmission::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function emailPreference(): HasOne
    {
        return $this->hasOne(ContactEmailPreference::class);
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
