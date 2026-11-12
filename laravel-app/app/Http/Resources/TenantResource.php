<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\PrivateFile;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Co-occupant count comes from withCount() in the list, or from the loaded relation
        $occupantsCount = $this->occupants_count
            ?? ($this->relationLoaded('occupants') ? $this->occupants->count() : 0);

        return [
            'id'                      => $this->id,
            'full_name'               => $this->full_name,
            'gender'                  => $this->gender,
            'nationality'             => $this->nationality,
            'phone'                   => $this->phone,
            'email'                   => $this->email,
            'id_card_number'          => $this->id_card_number,
            'address'                 => $this->address,
            'photo_url'               => PrivateFile::url($this->photo_path), // signed, expires
            'emergency_contact_name'  => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'room_id'                 => $this->room_id,
            'room'                    => $this->whenLoaded('room', fn () => $this->room ? [
                'id'          => $this->room->id,
                'room_number' => $this->room->room_number,
                'floor'       => $this->room->floor,
            ] : null),
            'move_in_date'            => $this->move_in_date?->toDateString(),
            'start_electric_meter'    => (float) $this->start_electric_meter,
            'start_water_meter'       => (float) $this->start_water_meter,
            'move_out_date'           => $this->move_out_date?->toDateString(),
            'status'                  => $this->status,
            'move_out_notice'         => $this->status === 'active'
                ? \App\Models\MoveOutNotice::activeFor($this->id)?->present()
                : null,
            'notes'                   => $this->notes,
            'occupants'               => $this->whenLoaded('occupants', fn () => $this->occupants->map(fn ($person) => [
                'id'           => $person->id,
                'full_name'    => $person->full_name,
                'relationship' => $person->relationship,
                'occupation'   => $person->occupation,
                'phone'        => $person->phone,
            ])),
            'occupants_count'         => $occupantsCount,
            'total_people'            => $occupantsCount + 1, // co-occupants + the main tenant
            'documents'               => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id'        => $doc->id,
                'name'      => $doc->original_name,
                'url'       => PrivateFile::url($doc->path, $doc->original_name),
                'mime_type' => $doc->mime_type,
                'size'      => $doc->size,
            ])),
            'created_at'              => $this->created_at?->toDateTimeString(),
        ];
    }
}