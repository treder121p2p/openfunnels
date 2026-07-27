<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactEmailPreference extends Model
{
    protected $fillable = [
        'user_id',
        'contact_id',
        'status',
        'source',
        'consented_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
