<?php

namespace App\Services;

use App\Models\MoveInPayment;
use App\Models\MoveOutNotice;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Everything the rental contract needs, filled from the tenant, the room and the settings.
 * The contract page (staff) and the tenant portal both use this.
 */
class ContractService
{
    public function build(Tenant $tenant): array
    {
        $tenant->loadMissing(['room', 'occupants']);
        $room = $tenant->room;

        if (! $room) {
            throw ValidationException::withMessages(['tenant' => 'This tenant has no room, so there is no contract.']);
        }

        $settings = Setting::allValues();
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->first();
        $start = ($tenant->move_in_date ?? today())->copy();
        $term = max(1, (int) $settings['contract_term_months']);

        return [
            'number'     => sprintf('RTR-%s-%04d', $start->format('Y'), $tenant->id),
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthsNoOverflow($term)->subDay()->toDateString(),
            'term_months' => $term,
            'status'     => $tenant->status,
            'move_out_date' => $tenant->move_out_date?->toDateString(),

            'landlord' => [
                'name'        => $settings['landlord_name'] ?: null,
                'gender'      => $settings['landlord_gender'] ?: 'male',
                'nationality' => $settings['landlord_nationality'] ?: null,
                'id_card'     => $settings['landlord_id_card'] ?: null,
                'phone'       => $settings['landlord_phone'] ?: null,
            ],
            'property_address' => $settings['property_address'] ?: null,

            'tenant' => [
                'name'              => $tenant->full_name,
                'gender'            => $tenant->gender,
                'nationality'       => $tenant->nationality,
                'id_card'           => $tenant->id_card_number,
                'phone'             => $tenant->phone,
                'address'           => $tenant->address,
                'emergency_name'    => $tenant->emergency_contact_name,
                'emergency_phone'   => $tenant->emergency_contact_phone,
            ],
            'occupants' => $tenant->occupants->map(fn ($person) => [
                'name'         => $person->full_name,
                'relationship' => $person->relationship,
            ])->values()->all(),

            'room' => [
                'number'          => $room->room_number,
                'floor'           => $room->floor,
                'type'            => $room->type,
                'rent_usd'        => (float) $room->rent_price,
                'electric_to_edc' => $room->electric_paid_to === 'edc',
                'edc_account'     => $room->edc_account,
            ],
            'start_meters' => [
                'electric' => (float) $tenant->start_electric_meter,
                'water'    => (float) $tenant->start_water_meter,
            ],
            'utilities' => [
                'electric' => $this->tariff('electric', $room, $settings),
                'water'    => $this->tariff('water', $room, $settings),
            ],

            'handover' => $this->handover($tenant, $room),

            'deposit_usd'       => $moveIn ? (float) $moveIn->deposit_usd : null,
            'exchange_rate'     => (int) $settings['exchange_rate'],
            'payment_account'   => $settings['payment_account'] ?: null,
            'payment_due_days'  => (int) $settings['payment_due_days'],
            'notice_days'       => MoveOutNotice::NOTICE_DAYS,
            'max_overstay_days' => DepositService::MAX_OVERSTAY_DAYS,
            'extra_terms'       => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $settings['contract_extra_terms'])))),
        ];
    }

    // The list kept on the handover day, or the room's list today if none was kept yet
    private function handover(Tenant $tenant, $room): array
    {
        if ($tenant->handover_saved_on) {
            return [
                'saved_on'  => $tenant->handover_saved_on->toDateString(),
                'key_count' => $tenant->handover_key_count,
                'items'     => $tenant->handover_items ?? [],
            ];
        }

        return ['saved_on' => null] + app(RoomItemService::class)->show($room);
    }

    // The room's own rate (per unit or fixed), or the tiers from the settings
    private function tariff(string $utility, $room, array $settings): array
    {
        $type = $room->{"{$utility}_rate_type"};
        $rate = $room->{"{$utility}_custom_rate"};

        if ($type !== null && $rate !== null) {
            return ['method' => $type, 'rate' => (int) $rate, 'tiers' => [], 'min_charge' => 0];
        }

        return [
            'method'     => $settings["{$utility}_tier_mode"] ?? 'whole',
            'rate'       => null,
            'tiers'      => $settings["{$utility}_tiers"],
            'min_charge' => (int) ($settings["{$utility}_min_charge"] ?? 0),
        ];
    }
}
