<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MainMeterReading extends Model
{
    protected $fillable = ['main_meter_id', 'period', 'reading', 'read_on', 'usage_override', 'note', 'created_by'];

    protected $casts = [
        'reading'        => 'float',
        'usage_override' => 'float',
        'read_on'        => 'date',
    ];

    public function meter(): BelongsTo
    {
        return $this->belongsTo(MainMeter::class, 'main_meter_id');
    }
}
