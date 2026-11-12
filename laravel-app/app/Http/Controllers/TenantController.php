<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Services\MeterReadingService;
use App\Services\TenantService;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private TenantService $tenantService,
        private MeterReadingService $meterReadingService,
    ) {
    }

    public function index(Request $request)
    {
        $tenants = Tenant::query()
            ->with('room')
            ->withCount('occupants')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('id_card_number', 'like', "%{$search}%");
                });
            })
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->room_id, fn ($query, $roomId) => $query->where('room_id', $roomId))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request)
    {
        $tenant = $this->tenantService->create(
            $request->safe()->except(['photo', 'documents', 'occupants']),
            $request->file('photo'),
            $request->file('documents', []),
            $request->validated('occupants'),
        );

        return (new TenantResource($tenant->load(['room', 'documents', 'occupants'])))
            ->additional(['message' => 'Tenant created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant)
    {
        return new TenantResource($tenant->load(['room', 'documents', 'occupants']));
    }

    // Called as POST with _method=PUT, because PHP cannot read files from a real PUT request
    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $tenant = $this->tenantService->update(
            $tenant,
            $request->safe()->except(['photo', 'documents', 'occupants']),
            $request->file('photo'),
            $request->file('documents', []),
            $request->validated('occupants'),
        );

        return (new TenantResource($tenant->load(['room', 'documents', 'occupants'])))
            ->additional(['message' => 'Tenant updated successfully.']);
    }

    // GET /api/tenants/{tenant}/meter-status?date=2026-11-20
    // The lowest meter values allowed for the final reading at move-out
    public function meterStatus(Request $request, Tenant $tenant)
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $period = substr($data['date'] ?? now()->toDateString(), 0, 7);

        return response()->json(['data' => $this->meterReadingService->readingStatus($tenant, $period)]);
    }

    // PATCH /api/tenants/{tenant}/move-out
    public function moveOut(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'move_out_date'  => ['nullable', 'date'],
            'final_electric' => ['nullable', 'numeric', 'min:0'],
            'final_water'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenant = $this->tenantService->moveOut(
            $tenant,
            $data['move_out_date'] ?? null,
            ['electric' => $data['final_electric'] ?? null, 'water' => $data['final_water'] ?? null],
        );

        return (new TenantResource($tenant->load('room')))
            ->additional(['message' => 'Tenant moved out. The room is now available.']);
    }

    public function destroy(Tenant $tenant)
    {
        $this->tenantService->delete($tenant);

        return response()->json(['message' => 'Tenant deleted successfully.']);
    }

    public function destroyDocument(Tenant $tenant, TenantDocument $document)
    {
        $this->tenantService->deleteDocument($document);

        return response()->json(['message' => 'Document removed.']);
    }
}