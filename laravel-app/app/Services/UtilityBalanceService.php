<?php

namespace App\Services;

use App\Models\MainMeter;
use App\Models\MainMeterReading;
use App\Models\MeterReading;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Main meter balance for one month:
 *   main usage   = this month's reading − last month's reading (or the override when the meter was replaced)
 *   room usage   = sum of the room meters behind it
 *   not metered  = main usage − room usage   (owner's own use, shared taps and lights, leaks)
 *   cost         = what the supplier charges for the main usage (estimate from Settings tiers)
 *   collected    = what the rooms behind it were charged for this utility
 *   balance      = collected − cost
 */
class UtilityBalanceService
{
    public const UNMETERED_WARNING = 20; // percent

    public function forPeriod(MainMeter $meter, string $period, ?array $settings = null): array
    {
        $settings ??= Setting::allValues();
        $utility = $meter->utility;

        $reading = MainMeterReading::where('main_meter_id', $meter->id)->where('period', $period)->first();
        $previous = MainMeterReading::where('main_meter_id', $meter->id)->where('period', '<', $period)->orderByDesc('period')->first();

        $usage = null;
        if ($reading) {
            if ($reading->usage_override !== null) {
                $usage = (float) $reading->usage_override;
            } elseif ($previous && $previous->period === self::previousPeriod($period)) {
                $usage = round($reading->reading - $previous->reading, 2);
            }
        }

        $roomIds = $meter->rooms()->pluck('id');
        $rooms = MeterReading::whereIn('room_id', $roomIds)->where('period', $period)->get();
        $roomUsage = round((float) $rooms->sum("{$utility}_usage"), 2);
        $collected = (int) $rooms->sum("{$utility}_amount");

        $cost = $usage === null ? null : $meter->cost($usage, $settings);
        $unmetered = $usage === null ? null : round($usage - $roomUsage, 2);
        $unmeteredPercent = ($usage === null || $usage <= 0) ? null : round($unmetered / $usage * 100, 1);
        $chargedRate = $roomUsage > 0 ? (int) round($collected / $roomUsage) : null;
        $nextUnit = $usage === null ? null : $meter->nextUnitCost($usage, $settings);

        $alerts = [];
        if ($reading && $usage === null) {
            $alerts[] = ['type' => 'no_previous', 'message' => 'No reading for the month before, so the usage is unknown.'];
        }
        if ($unmetered !== null && $unmetered < 0) {
            $alerts[] = ['type' => 'negative', 'message' => 'The rooms used more than the main meter. Check the readings and the reading dates.'];
        } elseif ($unmeteredPercent !== null && $unmeteredPercent > self::UNMETERED_WARNING) {
            $alerts[] = ['type' => 'unmetered', 'message' => "{$unmeteredPercent}% was not metered (owner, shared use or a leak)."];
        }
        if ($cost !== null && $collected < $cost && $roomIds->isNotEmpty() && $rooms->isNotEmpty()) {
            $alerts[] = ['type' => 'loss', 'message' => 'The rooms paid less than the supplier bill.'];
        }
        if ($nextUnit !== null && $chargedRate !== null && $nextUnit > $chargedRate) {
            $alerts[] = ['type' => 'tier', 'message' => "Each extra unit now costs {$nextUnit} riel, more than the {$chargedRate} riel tenants pay."];
        }

        return [
            'period'            => $period,
            'reading'           => $reading?->reading,
            'reading_id'        => $reading?->id,
            'read_on'           => $reading?->read_on?->toDateString(),
            'usage_override'    => $reading?->usage_override,
            'note'              => $reading?->note,
            'previous_reading'  => $previous?->reading,
            'previous_period'   => $previous?->period,
            'usage'             => $usage,
            'room_usage'        => $roomUsage,
            'rooms_recorded'    => $rooms->count(),
            'rooms_linked'      => $roomIds->count(),
            'unmetered'         => $unmetered,
            'unmetered_percent' => $unmeteredPercent,
            'cost'              => $cost,
            'collected'         => $collected,
            'balance'           => $cost === null ? null : $collected - $cost,
            'charged_rate'      => $chargedRate,
            'next_unit_cost'    => $nextUnit,
            'alerts'            => $alerts,
        ];
    }

    /**
     * One row per month for each meter, oldest first.
     */
    public function report(string $from, string $to): array
    {
        $settings = Setting::allValues();
        $periods = [];
        for ($month = CarbonImmutable::createFromFormat('Y-m-d', "{$from}-01"); $month->format('Y-m') <= $to; $month = $month->addMonth()) {
            $periods[] = $month->format('Y-m');
        }

        return MainMeter::orderBy('utility')->orderBy('id')->get()->map(function (MainMeter $meter) use ($periods, $settings) {
            $rows = array_map(fn ($period) => $this->forPeriod($meter, $period, $settings), $periods);
            $known = array_filter($rows, fn ($row) => $row['usage'] !== null);

            return $meter->present() + [
                'months' => $rows,
                'totals' => [
                    'usage'      => round(array_sum(array_column($known, 'usage')), 2),
                    'room_usage' => round(array_sum(array_column($known, 'room_usage')), 2),
                    'unmetered'  => round(array_sum(array_column($known, 'unmetered')), 2),
                    'cost'       => array_sum(array_column($known, 'cost')),
                    'collected'  => array_sum(array_column($known, 'collected')),
                    'balance'    => array_sum(array_column($known, 'balance')),
                    'months'     => count($known),
                ],
            ];
        })->all();
    }

    public static function previousPeriod(string $period): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', "{$period}-01")->subMonth()->format('Y-m');
    }
}
