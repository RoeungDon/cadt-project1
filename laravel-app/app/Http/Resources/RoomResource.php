<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'room_number' => $this->room_number,
            'floor'       => $this->floor,
            'type'        => $this->type,
            'rent_price'  => (float) $this->rent_price,
            'status'      => $this->status,
            'edc_account' => $this->edc_account,
            'electric_paid_to' => $this->electric_paid_to,
            'electric_main_meter_id' => $this->electric_main_meter_id,
            'water_main_meter_id'    => $this->water_main_meter_id,
            'description' => $this->description,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}