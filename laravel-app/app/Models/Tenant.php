<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    public const STATUSES = ['active', 'moved_out'];
    public const GENDERS = ['male', 'female', 'other'];
    public const MAX_DOCUMENTS = 5;
    public const MAX_OCCUPANTS = 10;

    protected $fillable = [
        'full_name',
        'gender',
        'nationality',
        'phone',
        'email',
        'id_card_number',
        'address',
        'photo_path',
        'emergency_contact_name',
        'emergency_contact_phone',
        'room_id',
        'move_in_date',
        'start_electric_meter',
        'start_water_meter',
        'handover_items',
        'handover_key_count',
        'handover_saved_on',
        'move_out_date',
        'status',
        'notes',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'move_in_date' => 'date',
        'move_out_date' => 'date',
        'start_electric_meter' => 'decimal:2',
        'start_water_meter' => 'decimal:2',
        'handover_items' => 'array',
        'handover_key_count' => 'integer',
        'handover_saved_on' => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class);
    }

    // People living in the room together with this tenant
    public function occupants(): HasMany
    {
        return $this->hasMany(TenantOccupant::class);
    }
}