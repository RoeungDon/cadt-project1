<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    // Used when a key has not been saved yet
    public const DEFAULTS = [
        'exchange_rate'         => 4100,          // riel per 1 USD
        'electric_tier_mode'    => 'whole',       // A = "whole", B = "progressive"
        'water_tier_mode'       => 'progressive',
        'electric_tiers'        => [              // riel per kWh
            ['up_to' => 10, 'rate' => 380],
            ['up_to' => 50, 'rate' => 480],
            ['up_to' => 200, 'rate' => 610],
            ['up_to' => null, 'rate' => 730],
        ],
        'water_tiers'           => [              // riel per m³
            ['up_to' => 7, 'rate' => 400],
            ['up_to' => 15, 'rate' => 720],
            ['up_to' => 25, 'rate' => 960],
            ['up_to' => 50, 'rate' => 1250],
            ['up_to' => 100, 'rate' => 1900],
            ['up_to' => null, 'rate' => 2200],
        ],
        'electric_min_charge'   => 0,             // riel, 0 = no minimum
        'water_min_charge'      => 0,
        'usage_warning_percent' => 50,            // warn when usage changes more than this % from last month
        'payment_account'       => '',            // shown under the KHQR, e.g. "Demo Owner · ABA 000 123 456"
        'payment_qr_path'       => null,          // the owner's ABA KHQR picture (public disk)
        // Rental contract: the landlord (party A) and the standard terms
        'landlord_name'         => '',
        'landlord_gender'       => 'male',
        'landlord_nationality'  => 'ខ្មែរ',
        'landlord_id_card'      => '',
        'landlord_phone'        => '',
        'property_address'      => '',
        'contract_term_months'  => 12,
        'payment_due_days'      => 5,             // days to pay after the invoice is issued
        'contract_extra_terms'  => '',            // extra clauses, one per line
    ];

    public static function getValue(string $key): mixed
    {
        $raw = static::where('key', $key)->value('value');

        return $raw === null ? (self::DEFAULTS[$key] ?? null) : self::decode($raw);
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value],
        );
    }

    // All known settings, with defaults for anything missing
    public static function allValues(): array
    {
        $saved = static::pluck('value', 'key')->map(fn ($raw) => self::decode($raw))->all();

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    // "4100" → 4100, "[...]" → array, "whole" → "whole"
    private static function decode(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
