<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // The form sends occupants as a JSON string because the request also contains files
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('occupants'))) {
            $this->merge([
                'occupants' => json_decode($this->input('occupants'), true) ?? [],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name'                => ['required', 'string', 'max:100'],
            'gender'                   => ['nullable', Rule::in(Tenant::GENDERS)],
            'nationality'              => ['nullable', 'string', 'max:50'],
            'phone'                    => ['required', 'string', 'max:20'],
            'email'                    => ['nullable', 'email', 'max:100'],
            'id_card_number'           => ['nullable', 'string', 'max:50'],
            'address'                  => ['nullable', 'string', 'max:255'],
            'emergency_contact_name'   => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone'  => ['nullable', 'string', 'max:20'],
            'room_id'                  => ['nullable', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'move_in_date'             => ['nullable', 'date'],
            'start_electric_meter'     => ['nullable', 'numeric', 'min:0'],
            'start_water_meter'        => ['nullable', 'numeric', 'min:0'],
            'notes'                    => ['nullable', 'string', 'max:1000'],
            'photo'                    => ['nullable', 'image', 'max:5120'],
            'documents'                => ['nullable', 'array', 'max:' . Tenant::MAX_DOCUMENTS],
            'documents.*'              => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'occupants'                => ['nullable', 'array', 'max:' . Tenant::MAX_OCCUPANTS],
            'occupants.*.full_name'    => ['required', 'string', 'max:100'],
            'occupants.*.relationship' => ['nullable', 'string', 'max:50'],
            'occupants.*.occupation'   => ['nullable', 'string', 'max:100'],
            'occupants.*.phone'        => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'occupants.*.full_name'    => 'occupant name',
            'occupants.*.relationship' => 'relationship',
            'occupants.*.occupation'   => 'occupation',
            'occupants.*.phone'        => 'occupant phone',
        ];
    }
}