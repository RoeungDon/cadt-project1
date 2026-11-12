<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_number' => [
                'required', 'string', 'max:20',
                Rule::unique('rooms', 'room_number')->whereNull('deleted_at'),
            ],
            'floor'       => ['nullable', 'integer', 'min:0', 'max:255'],
            'type'        => ['nullable', 'string', 'max:50'],
            'rent_price'  => ['required', 'numeric', 'min:0'],
            'status'      => ['sometimes', Rule::in(Room::STATUSES)],
            'edc_account' => ['nullable', 'string', 'max:30'],
            'electric_paid_to' => ['sometimes', Rule::in(Room::ELECTRIC_PAID_TO)],
            'electric_main_meter_id' => ['nullable', Rule::exists('main_meters', 'id')->where('utility', 'electric')],
            'water_main_meter_id'    => ['nullable', Rule::exists('main_meters', 'id')->where('utility', 'water')],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}