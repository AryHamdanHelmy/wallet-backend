<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passkey extends Model
{
    protected $fillable = [
        'name',
        'credential_id',
        'public_key',
        'sign_count',
        'aaguid',
        'backup_eligible',
        'backed_up',
        'last_used_at',
    ];

    /** Tidak perlu dikirim ke frontend. */
    protected $hidden = [
        'public_key',
        'credential_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'backup_eligible' => 'boolean',
            'backed_up' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}