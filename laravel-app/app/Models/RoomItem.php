<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One thing in a room: "Bed", 1, "Good"
class RoomItem extends Model
{
    protected $fillable = ['room_id', 'name', 'quantity', 'condition', 'note', 'sort_order'];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
