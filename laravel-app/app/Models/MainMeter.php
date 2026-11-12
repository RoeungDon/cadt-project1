<?php

namespace App\Models;

use App\Services\UtilityTariff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MainMeter extends Model
{
    public const UTILITIES = ['electric', 'water'];

    protected $fillable = ['utility', 'name', 'account', 'tier_mode', 'extra_percent', 'monthly_fee', 'notes'];

    protected $casts = [
        'extra_percent' => 'float',
        'monthly_fee'   => 'integer',
    ];

    public function readings(): HasMany
    {
        return $this->hasMany(MainMeterReading::class);
    }

    // Rooms whose meter sits behind this main meter
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, "{$this->utility}_main_meter_id");
    }

    /**
     * What the supplier charges for this usage in one month, with the tiers from Settings.
     * Same rounding as the room invoices (100 riel); the supplier may round to 50.
     */
    public function cost(float $usage, array $settings): int
    {
        if ($usage <= 0) {
            return $this->monthly_fee;
        }

        $base = UtilityTariff::calculate($usage, $settings["{$this->utility}_tiers"], $this->tier_mode)['amount'];

        return UtilityTariff::roundRiel($base * (1 + $this->extra_percent / 100) + $this->monthly_fee);
    }

    // Riel the next unit costs at this usage (the rate of its tier, plus the extra percent)
    public function nextUnitCost(float $usage, array $settings): int
    {
        $tiers = UtilityTariff::sorted($settings["{$this->utility}_tiers"]);
        $rate = end($tiers)['rate'];

        foreach ($tiers as $tier) {
            if ($tier['up_to'] === null || $usage < $tier['up_to']) {
                $rate = $tier['rate'];
                break;
            }
        }

        return (int) round($rate * (1 + $this->extra_percent / 100));
    }

    public function present(): array
    {
        return [
            'id'            => $this->id,
            'utility'       => $this->utility,
            'name'          => $this->name,
            'account'       => $this->account,
            'tier_mode'     => $this->tier_mode,
            'extra_percent' => $this->extra_percent,
            'monthly_fee'   => $this->monthly_fee,
            'notes'         => $this->notes,
            'room_numbers'  => $this->rooms()->orderBy('room_number')->pluck('room_number'),
        ];
    }
}
