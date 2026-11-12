<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReplacement extends Model
{
    public const UTILITIES = ['electric', 'water'];

    protected $fillable = [
        'room_id',
        'tenant_id',
        'utility',
        'period',
        'old_meter_final',
        'new_meter_start',
        'replaced_at',
        'notes',
    ];

    protected $casts = [
        'old_meter_final' => 'decimal:2',
        'new_meter_start' => 'decimal:2',
        'replaced_at'     => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }
}
