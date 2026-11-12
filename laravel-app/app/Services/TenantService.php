<?php

namespace App\Services;

use App\Models\MeterReading;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Support\PrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantService
{
    public function __construct(
        private MeterReadingService $meterReadingService,
        private UserService $userService,
        private RoomItemService $roomItems,
    ) {
    }

    /**
     * @param  UploadedFile[]  $documents
     * @param  array|null  $occupants  null = not sent, [] = no co-occupants
     */
    public function create(
        array $data,
        ?UploadedFile $photo = null,
        array $documents = [],
        ?array $occupants = null,
    ): Tenant {
        return DB::transaction(function () use ($data, $photo, $documents, $occupants) {
            if (! empty($data['room_id'])) {
                $this->occupyRoom($data['room_id']);
                $data['move_in_date'] = $data['move_in_date'] ?? now()->toDateString();
                $this->assertRoomFree((int) $data['room_id'], $data['move_in_date'], null);
                $data = $this->defaultStartMeters($data, (int) $data['room_id']);
            }

            $data = $this->normalizeMeters($data);
            $data['status'] = 'active';

            if ($photo) {
                $data['photo_path'] = PrivateFile::store($photo, 'tenants/photos');
            }

            $tenant = Tenant::create($data);
            $this->storeDocuments($tenant, $documents);
            $this->syncOccupants($tenant, $occupants);

            // The things in the room on the handover day, for the contract
            if ($tenant->room_id) {
                $this->roomItems->saveHandover($tenant);
            }

            return $tenant;
        });
    }

    /**
     * @param  UploadedFile[]  $documents
     * @param  array|null  $occupants  null = keep current list, array = replace the list
     */
    public function update(
        Tenant $tenant,
        array $data,
        ?UploadedFile $photo = null,
        array $documents = [],
        ?array $occupants = null,
    ): Tenant {
        return DB::transaction(function () use ($tenant, $data, $photo, $documents, $occupants) {
            $roomChanged = array_key_exists('room_id', $data)
                && (int) $data['room_id'] !== (int) $tenant->room_id;

            if ($roomChanged) {
                if ($tenant->status !== 'active') {
                    throw ValidationException::withMessages([
                        'room_id' => 'Cannot change the room of a tenant who has moved out.',
                    ]);
                }

                if (! empty($data['room_id'])) {
                    $this->occupyRoom($data['room_id']);
                    $data['move_in_date'] = $data['move_in_date'] ?? now()->toDateString();
                }

                if ($tenant->room_id) {
                    $this->releaseRoom($tenant->room_id, $tenant->id);
                }
            }

            // Another tenant cannot be in the same room on the same days
            $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : $tenant->room_id;
            $moveIn = $data['move_in_date'] ?? $tenant->move_in_date?->toDateString();

            if ($roomId && $moveIn) {
                $this->assertRoomFree((int) $roomId, $moveIn, $tenant->move_out_date?->toDateString(), $tenant->id);
            }

            $data = $this->normalizeMeters($data);

            if ($photo) {
                PrivateFile::delete($tenant->photo_path);
                $data['photo_path'] = PrivateFile::store($photo, 'tenants/photos');
            }

            if (count($documents) > 0
                && $tenant->documents()->count() + count($documents) > Tenant::MAX_DOCUMENTS) {
                throw ValidationException::withMessages([
                    'documents' => 'A tenant can have at most ' . Tenant::MAX_DOCUMENTS . ' documents.',
                ]);
            }

            $tenant->update($data);
            $this->storeDocuments($tenant, $documents);
            $this->syncOccupants($tenant, $occupants);

            // A new room comes with its own list of things
            if ($roomChanged) {
                $this->roomItems->saveHandover($tenant->refresh());
            }

            return $tenant;
        });
    }

    /**
     * Tenant leaves. If final meter readings are given, they are saved first
     * so the last bill is correct and the next tenant starts from the right numbers.
     *
     * @param  array  $final  ['electric' => 420 or null, 'water' => 75 or null]
     */
    public function moveOut(Tenant $tenant, ?string $date = null, array $final = []): Tenant
    {
        if ($tenant->status === 'moved_out') {
            throw ValidationException::withMessages([
                'status' => 'This tenant has already moved out.',
            ]);
        }

        $date ??= now()->toDateString();

        if ($tenant->move_in_date && $date < $tenant->move_in_date->toDateString()) {
            throw ValidationException::withMessages([
                'move_out_date' => 'The move-out date cannot be before the move-in date (' . $tenant->move_in_date->toDateString() . ').',
            ]);
        }

        if ($tenant->room_id && $tenant->move_in_date) {
            $this->assertRoomFree($tenant->room_id, $tenant->move_in_date->toDateString(), $date, $tenant->id, 'move_out_date');
        }

        return DB::transaction(function () use ($tenant, $date, $final) {
            $hasFinalReading = ($final['electric'] ?? null) !== null || ($final['water'] ?? null) !== null;

            if ($hasFinalReading && $tenant->room_id) {
                $room = Room::withTrashed()->find($tenant->room_id);

                $this->meterReadingService->saveForTenant(
                    $room,
                    $tenant,
                    substr($date, 0, 7), // month of the move-out date, e.g. 2026-11
                    ['electric' => $final['electric'] ?? null, 'water' => $final['water'] ?? null],
                    $date,
                    'Final reading at move-out',
                    null,
                    ['electric' => 'final_electric', 'water' => 'final_water'],
                );
            }

            $tenant->update([
                'status' => 'moved_out',
                'move_out_date' => $date,
            ]);

            if ($tenant->room_id) {
                $this->releaseRoom($tenant->room_id, $tenant->id);
            }

            return $tenant;
        });
    }

    public function delete(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            if ($tenant->status === 'active' && $tenant->room_id) {
                $this->releaseRoom($tenant->room_id, $tenant->id);
            }

            // A deleted tenant cannot log in any more
            $this->userService->disableTenantLogin($tenant, auth()->user());
            $tenant->delete();
        });
    }

    public function deleteDocument(TenantDocument $document): void
    {
        PrivateFile::delete($document->path);
        $document->delete();
    }

    /**
     * A new tenant's starting meters = the room's latest reading (e.g. the last tenant's final reading),
     * unless the form gives other values.
     */
    private function defaultStartMeters(array $data, int $roomId): array
    {
        $last = MeterReading::where('room_id', $roomId)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return $data;
        }

        foreach (['electric', 'water'] as $utility) {
            $key = "start_{$utility}_meter";

            if (! isset($data[$key]) || $data[$key] === '') {
                $data[$key] = (float) $last->{"{$utility}_current"};
            }
        }

        return $data;
    }

    /**
     * Replace the tenant's co-occupants with the given list.
     */
    private function syncOccupants(Tenant $tenant, ?array $occupants): void
    {
        if ($occupants === null) {
            return; // nothing sent: keep the current list
        }

        $tenant->occupants()->delete();

        foreach ($occupants as $person) {
            $tenant->occupants()->create([
                'full_name'    => trim($person['full_name']),
                'relationship' => ($person['relationship'] ?? null) ?: null,
                'occupation'   => ($person['occupation'] ?? null) ?: null,
                'phone'        => ($person['phone'] ?? null) ?: null,
            ]);
        }
    }

    /**
     * @param  UploadedFile[]  $documents
     */
    private function storeDocuments(Tenant $tenant, array $documents): void
    {
        foreach ($documents as $file) {
            $tenant->documents()->create([
                'path'          => PrivateFile::store($file, "tenants/{$tenant->id}/documents"),
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ]);
        }
    }

    private function normalizeMeters(array $data): array
    {
        foreach (['start_electric_meter', 'start_water_meter'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                $data[$key] = 0;
            }
        }

        return $data;
    }

    /**
     * Stop two tenants from being in one room on the same days.
     * The next tenant can move in on the day the last one moves out.
     */
    private function assertRoomFree(int $roomId, string $moveIn, ?string $moveOut, ?int $exceptTenantId = null, string $field = 'move_in_date'): void
    {
        $other = Tenant::where('room_id', $roomId)
            ->when($exceptTenantId, fn ($query) => $query->where('id', '!=', $exceptTenantId))
            ->where(fn ($query) => $query->whereNull('move_in_date')->orWhereDate('move_in_date', '<', $moveOut ?? '9999-12-31'))
            ->where(fn ($query) => $query->whereNull('move_out_date')->orWhereDate('move_out_date', '>', $moveIn))
            ->orderBy('move_in_date')
            ->first();

        if (! $other) {
            return;
        }

        $from = $other->move_in_date?->toDateString() ?? '?';
        $to = $other->move_out_date?->toDateString() ?? 'now';

        throw ValidationException::withMessages([
            $field => "This room is rented by {$other->full_name} from {$from} to {$to}. The dates cannot overlap.",
        ]);
    }

    private function occupyRoom(int $roomId): void
    {
        $room = Room::lockForUpdate()->find($roomId);

        if (! $room || $room->status !== 'available') {
            throw ValidationException::withMessages([
                'room_id' => 'This room is not available.',
            ]);
        }

        $room->update(['status' => 'occupied']);
    }

    private function releaseRoom(int $roomId, int $exceptTenantId): void
    {
        $stillOccupied = Tenant::where('room_id', $roomId)
            ->where('status', 'active')
            ->where('id', '!=', $exceptTenantId)
            ->exists();

        if (! $stillOccupied) {
            Room::whereKey($roomId)
                ->where('status', 'occupied')
                ->update(['status' => 'available']);
        }
    }
}