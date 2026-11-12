<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\MeterReplacement;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\PrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeterReadingService
{
    public const UTILITIES = ['electric', 'water'];

    private const LABELS = ['electric' => 'electricity', 'water' => 'water'];

    /**
     * One row per occupied room for the given month:
     * the previous reading is filled in automatically, the current reading comes from what was saved.
     */
    public function sheet(string $period): array
    {
        $rooms = Room::with('activeTenant')
            ->where('status', 'occupied')
            ->orderBy('room_number')
            ->get()
            ->filter(fn (Room $room) => $room->activeTenant !== null);

        $saved = MeterReading::where('period', $period)
            ->whereIn('tenant_id', $rooms->map(fn (Room $room) => $room->activeTenant->id))
            ->get()
            ->keyBy('room_id');

        $invoices = Invoice::valid()
            ->whereIn('meter_reading_id', $saved->pluck('id'))
            ->pluck('invoice_number', 'meter_reading_id');

        return $rooms->map(function (Room $room) use ($period, $saved, $invoices) {
            $tenant = $room->activeTenant;
            $reading = $saved->get($room->id);
            $previous = $this->previousReading($room->id, $tenant, $period);
            $roomReplacements = $this->replacementsFor($room->id, $tenant, $period);

            $row = [
                'room_id'         => $room->id,
                'room_number'     => $room->room_number,
                'floor'           => $room->floor,
                'tenant'          => [
                    'id'        => $tenant->id,
                    'full_name' => $tenant->full_name,
                    'phone'     => $tenant->phone,
                ],
                'custom_rates'    => [ // method C (null = use tiers)
                    'electric' => ['type' => $room->electric_rate_type, 'rate' => $room->electric_custom_rate],
                    'water'    => ['type' => $room->water_rate_type, 'rate' => $room->water_custom_rate],
                ],
                'reading_id'      => $reading?->id,
                'read_at'         => $reading?->read_at?->toDateString(),
                'locked'          => (bool) $reading?->isLocked(),
                'invoice_number'  => $reading ? $invoices->get($reading->id) : null,
                'previous_source' => $previous['source'],  // "last_month" or "move_in"
                'previous_period' => $previous['period'],
                'total_amount'    => $reading?->total_amount,
                'notes'           => $reading?->notes,
            ];

            foreach (self::UTILITIES as $utility) {
                $replacement = $roomReplacements->get($utility);
                $photo = $reading?->{"{$utility}_photo_path"};

                $row[$utility] = [
                    'previous'    => $reading ? (float) $reading->{"{$utility}_previous"} : $previous[$utility],
                    'current'     => $reading ? (float) $reading->{"{$utility}_current"} : null,
                    'usage'       => $reading ? (float) $reading->{"{$utility}_usage"} : null,
                    'rate'        => $reading?->{"{$utility}_rate"},   // average riel per unit that was charged
                    'amount'      => $reading?->{"{$utility}_amount"},
                    'method'      => $reading?->{"{$utility}_method"}, // whole, progressive, per_unit or fixed
                    'last_usage'  => $previous['last_usage'][$utility], // usage of last month, for warnings
                    'photo_url'   => PrivateFile::url($photo),
                    'replacement' => $replacement ? [
                        'id'              => $replacement->id,
                        'old_meter_final' => (float) $replacement->old_meter_final,
                        'new_meter_start' => (float) $replacement->new_meter_start,
                        'replaced_at'     => $replacement->replaced_at?->toDateString(),
                        'notes'           => $replacement->notes,
                    ] : null,
                ];
            }

            // More than 50% above or below the last 3 months
            $row['usage_alerts'] = $reading
                ? UsageAlert::check($tenant->id, $room->id, $period, ['electric' => (float) $reading->electric_usage, 'water' => (float) $reading->water_usage])
                : [];
            $row['electric_paid_to'] = $room->electric_paid_to;

            return $row;
        })->values()->all();
    }

    /**
     * Save the readings of many rooms at once. Returns the number of rooms saved.
     */
    public function saveMany(string $period, array $rows, ?string $readAt = null): int
    {
        $settings = Setting::allValues();
        $saved = 0;

        DB::transaction(function () use ($period, $rows, $readAt, $settings, &$saved) {
            foreach ($rows as $index => $row) {
                // Skip rooms where nothing was entered
                if (($row['electric_current'] ?? null) === null && ($row['water_current'] ?? null) === null) {
                    continue;
                }

                $room = Room::with('activeTenant')->find($row['room_id']);
                $tenant = $room?->activeTenant;

                if (! $tenant) {
                    throw ValidationException::withMessages([
                        "readings.{$index}.room_id" => 'This room has no active tenant.',
                    ]);
                }

                $this->saveForTenant(
                    $room,
                    $tenant,
                    $period,
                    ['electric' => $row['electric_current'] ?? null, 'water' => $row['water_current'] ?? null],
                    $readAt,
                    $row['notes'] ?? null,
                    $settings,
                    [
                        'electric' => "readings.{$index}.electric_current",
                        'water'    => "readings.{$index}.water_current",
                    ],
                );

                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Calculate and save one month's reading of one tenant.
     * Also used for the final reading when a tenant moves out.
     *
     * @param  array  $input  ['electric' => 360 or null, 'water' => 70 or null]  null = no change
     * @param  array|null  $errorKeys  field names used in validation errors
     */
    public function saveForTenant(
        Room $room,
        Tenant $tenant,
        string $period,
        array $input,
        ?string $readAt = null,
        ?string $notes = null,
        ?array $settings = null,
        ?array $errorKeys = null,
    ): MeterReading {
        $settings ??= Setting::allValues();
        $errorKeys ??= ['electric' => 'electric_current', 'water' => 'water_current'];

        $existing = MeterReading::where('room_id', $room->id)
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->first();

        if ($existing?->isLocked()) {
            throw ValidationException::withMessages([
                $errorKeys['electric'] => "Room {$room->room_number}: the {$period} reading is locked and cannot be changed.",
            ]);
        }

        $previous = $this->previousReading($room->id, $tenant, $period);
        $replacements = $this->replacementsFor($room->id, $tenant, $period);

        $values = [];
        $total = 0;

        foreach (self::UTILITIES as $utility) {
            $replacement = $replacements->get($utility);

            // With a replaced meter, the new reading is compared with the new meter's start
            $start = $replacement ? (float) $replacement->new_meter_start : $previous[$utility];
            // A blank reading keeps what was already saved this month, otherwise it means no usage
            $current = ($input[$utility] ?? null) === null
                ? (float) ($existing?->{"{$utility}_current"} ?? $start)
                : (float) $input[$utility];

            if ($current < $start) {
                throw ValidationException::withMessages([
                    $errorKeys[$utility] => "Room {$room->room_number}: new " . self::LABELS[$utility]
                        . " reading cannot be less than {$start}" . ($replacement ? ' (new meter start).' : '.'),
                ]);
            }

            if ($replacement && (float) $replacement->old_meter_final < $previous[$utility]) {
                throw ValidationException::withMessages([
                    $errorKeys[$utility] => "Room {$room->room_number}: the old meter's final reading is lower than {$previous[$utility]}. Please fix the meter replacement.",
                ]);
            }

            // Usage = old meter part + new meter part (only the second part when nothing was replaced)
            $usage = $replacement
                ? ((float) $replacement->old_meter_final - $previous[$utility]) + ($current - $start)
                : $current - $previous[$utility];
            $usage = round($usage, 2);

            $charge = UtilityTariff::charge($usage, $utility, $settings, $room);

            $values["{$utility}_previous"] = $previous[$utility];
            $values["{$utility}_current"] = $current;
            $values["{$utility}_usage"] = $usage;
            $values["{$utility}_rate"] = $charge['rate'];
            $values["{$utility}_amount"] = $charge['amount'];
            $values["{$utility}_method"] = $charge['method'];

            $total += $charge['amount'];
        }

        return MeterReading::updateOrCreate(
            ['room_id' => $room->id, 'tenant_id' => $tenant->id, 'period' => $period],
            $values + [
                'total_amount' => $total,
                'read_at'      => $readAt ?? now()->toDateString(),
                'notes'        => $notes ?? $existing?->notes,
            ],
        );
    }

    /**
     * The values a new reading must not go below, e.g. for the move-out form.
     */
    public function readingStatus(Tenant $tenant, string $period): array
    {
        $previous = $this->previousReading((int) $tenant->room_id, $tenant, $period);
        $replacements = $this->replacementsFor((int) $tenant->room_id, $tenant, $period);

        $existing = MeterReading::where('room_id', $tenant->room_id)
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->first();

        $status = ['period' => $period, 'locked' => (bool) $existing?->isLocked()];

        foreach (self::UTILITIES as $utility) {
            $replacement = $replacements->get($utility);
            $status[$utility] = [
                'minimum' => $replacement ? (float) $replacement->new_meter_start : $previous[$utility],
                'current' => $existing ? (float) $existing->{"{$utility}_current"} : null,
            ];
        }

        return $status;
    }

    /**
     * Record that a meter was replaced during a month.
     * The month's saved reading (if any) is removed, because it must be entered again with the new meter.
     */
    public function recordReplacement(Room $room, array $data): MeterReplacement
    {
        $tenant = $room->activeTenant;

        if (! $tenant) {
            throw ValidationException::withMessages(['room_id' => 'This room has no active tenant.']);
        }

        if ($this->isLocked($room->id, $data['period'], $tenant->id)) {
            throw ValidationException::withMessages(['period' => "The {$data['period']} reading of this room is locked."]);
        }

        $previous = $this->previousReading($room->id, $tenant, $data['period']);
        $minimum = $previous[$data['utility']];

        if ((float) $data['old_meter_final'] < $minimum) {
            throw ValidationException::withMessages([
                'old_meter_final' => "The old meter's final reading cannot be less than {$minimum}.",
            ]);
        }

        return DB::transaction(function () use ($room, $tenant, $data) {
            $replacement = MeterReplacement::updateOrCreate(
                ['room_id' => $room->id, 'tenant_id' => $tenant->id, 'utility' => $data['utility'], 'period' => $data['period']],
                [
                    'old_meter_final' => $data['old_meter_final'],
                    'new_meter_start' => $data['new_meter_start'] ?? 0,
                    'replaced_at'     => $data['replaced_at'] ?? now()->toDateString(),
                    'notes'           => $data['notes'] ?? null,
                ],
            );

            $this->removeReadings($room->id, $data['period'], $tenant->id);

            return $replacement;
        });
    }

    public function deleteReplacement(MeterReplacement $replacement): void
    {
        if ($this->isLocked($replacement->room_id, $replacement->period, $replacement->tenant_id)) {
            throw ValidationException::withMessages(['period' => "The {$replacement->period} reading of this room is locked."]);
        }

        // The final bill of a tenant who has left must not be removed
        $tenantIsActive = Tenant::whereKey($replacement->tenant_id)
            ->where('room_id', $replacement->room_id)
            ->where('status', 'active')
            ->exists();

        if (! $tenantIsActive) {
            throw ValidationException::withMessages(['period' => 'This meter replacement belongs to a tenant who has left the room.']);
        }

        DB::transaction(function () use ($replacement) {
            $this->removeReadings($replacement->room_id, $replacement->period, $replacement->tenant_id);
            $replacement->delete();
        });
    }

    public function deleteReading(MeterReading $reading): void
    {
        if ($reading->isLocked()) {
            throw ValidationException::withMessages(['reading' => 'This reading is locked and cannot be deleted.']);
        }

        $this->removeReading($reading);
    }

    /**
     * Save a photo of the meter as proof. Returns the photo's URL.
     */
    public function savePhoto(MeterReading $reading, string $utility, UploadedFile $photo): string
    {
        if ($reading->isLocked()) {
            throw ValidationException::withMessages(['photo' => 'This reading is locked and cannot be changed.']);
        }

        $column = "{$utility}_photo_path";

        PrivateFile::delete($reading->{$column});

        $path = PrivateFile::store($photo, "meter-photos/{$reading->period}");
        $reading->update([$column => $path]);

        return PrivateFile::url($path);
    }

    /**
     * Lock or unlock every reading of a month. Returns the number of readings changed.
     * Readings used by a valid invoice stay locked until that invoice is voided.
     */
    public function setMonthLock(string $period, bool $lock): int
    {
        return MeterReading::where('period', $period)
            ->when($lock, fn ($query) => $query->whereNull('locked_at'))
            ->when(! $lock, fn ($query) => $query
                ->whereNotNull('locked_at')
                ->whereNotIn('id', Invoice::valid()->whereNotNull('meter_reading_id')->select('meter_reading_id')))
            ->update(['locked_at' => $lock ? now() : null]);
    }

    public function isLocked(int $roomId, string $period, ?int $tenantId = null): bool
    {
        return MeterReading::where('room_id', $roomId)
            ->where('period', $period)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereNotNull('locked_at')
            ->exists();
    }

    private function removeReadings(int $roomId, string $period, ?int $tenantId): void
    {
        if (! $tenantId) {
            return;
        }

        MeterReading::where('room_id', $roomId)
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->get()
            ->each(fn (MeterReading $reading) => $this->removeReading($reading));
    }

    private function removeReading(MeterReading $reading): void
    {
        foreach (self::UTILITIES as $utility) {
            PrivateFile::delete($reading->{"{$utility}_photo_path"});
        }

        $reading->delete();
    }

    /**
     * Only this tenant's own replacements affect this tenant's bill.
     */
    private function replacementsFor(int $roomId, Tenant $tenant, string $period)
    {
        return MeterReplacement::where('room_id', $roomId)
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->get()
            ->keyBy('utility');
    }

    /**
     * Last month's reading of the same tenant, or the tenant's starting meter values.
     */
    private function previousReading(int $roomId, Tenant $tenant, string $period): array
    {
        $last = MeterReading::where('room_id', $roomId)
            ->where('tenant_id', $tenant->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        if ($last) {
            return [
                'electric'   => (float) $last->electric_current,
                'water'      => (float) $last->water_current,
                'source'     => 'last_month',
                'period'     => $last->period,
                'last_usage' => [
                    'electric' => (float) $last->electric_usage,
                    'water'    => (float) $last->water_usage,
                ],
            ];
        }

        return [
            'electric'   => (float) $tenant->start_electric_meter,
            'water'      => (float) $tenant->start_water_meter,
            'source'     => 'move_in',
            'period'     => null,
            'last_usage' => ['electric' => null, 'water' => null],
        ];
    }
}