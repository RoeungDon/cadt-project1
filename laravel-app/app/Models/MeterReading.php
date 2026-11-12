<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReading extends Model
{
    protected $fillable = [
        'room_id',
        'tenant_id',
        'period',
        'electric_previous',
        'electric_current',
        'electric_usage',
        'electric_rate',
        'electric_amount',
        'electric_method',
        'water_previous',
        'water_current',
        'water_usage',
        'water_rate',
        'water_amount',
        'water_method',
        'total_amount',
        'read_at',
        'notes',
        'electric_photo_path',
        'water_photo_path',
        'locked_at',
    ];

    protected $casts = [
        'electric_previous' => 'decimal:2',
        'electric_current'  => 'decimal:2',
        'electric_usage'    => 'decimal:2',
        'water_previous'    => 'decimal:2',
        'water_current'     => 'decimal:2',
        'water_usage'       => 'decimal:2',
        'electric_rate'     => 'integer',
        'water_rate'        => 'integer',
        'electric_amount'   => 'integer',
        'water_amount'      => 'integer',
        'total_amount'      => 'integer',
        'read_at'           => 'date',
        'locked_at'         => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    // Locked readings were used in an invoice (or the month was closed) and cannot change
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}