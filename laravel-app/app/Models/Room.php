<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    // owner_use: the owner lives in or uses the room; no tenant, no invoices
    public const STATUSES = ['available', 'occupied', 'maintenance', 'owner_use'];
    public const RATE_TYPES = ['per_unit', 'fixed'];
    // owner: the owner bills the electricity (sub-meter). edc: the room has its own EDC meter and the tenant pays EDC
    public const ELECTRIC_PAID_TO = ['owner', 'edc'];

    protected $fillable = [
        'room_number',
        'floor',
        'type',
        'rent_price',
        'electric_rate_type',
        'electric_custom_rate',
        'water_rate_type',
        'water_custom_rate',
        'edc_account',
        'electric_paid_to',
        'electric_main_meter_id',
        'water_main_meter_id',
        'status',
        'description',
        'key_count',
    ];

    protected $attributes = [
        'status' => 'available',
    ];

    protected $casts = [
        'floor' => 'integer',
        'rent_price' => 'decimal:2',
        'electric_custom_rate' => 'integer',
        'water_custom_rate' => 'integer',
        'key_count' => 'integer',
    ];

    // What is in the room, in the order the owner listed it
    public function items(): HasMany
    {
        return $this->hasMany(RoomItem::class)->orderBy('sort_order')->orderBy('id');
    }

    // All tenants who have ever rented this room
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function electricMainMeter(): BelongsTo
    {
        return $this->belongsTo(MainMeter::class, 'electric_main_meter_id');
    }

    public function waterMainMeter(): BelongsTo
    {
        return $this->belongsTo(MainMeter::class, 'water_main_meter_id');
    }

    // The tenant currently living in this room
    public function activeTenant(): HasOne
    {
        return $this->hasOne(Tenant::class)->where('status', 'active');
    }
}