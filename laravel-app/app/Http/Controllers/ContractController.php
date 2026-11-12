<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ContractService;
use App\Services\RoomItemService;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    public function __construct(private ContractService $contracts)
    {
    }

    // GET /api/tenants/{tenant}/contract   the rental contract, filled in from the tenant and the room
    public function show(Tenant $tenant)
    {
        return response()->json(['data' => $this->contracts->build($tenant)]);
    }

    // POST /api/tenants/{tenant}/handover   keep the room's list as it is today on this tenant's contract
    public function saveHandover(Tenant $tenant, RoomItemService $items)
    {
        if ($tenant->status !== 'active') {
            throw ValidationException::withMessages(['tenant' => 'This tenant has moved out. The old list stays as it is.']);
        }

        $items->saveHandover($tenant->loadMissing('room'));

        return response()->json([
            'message' => 'The list of things is kept on the contract.',
            'data'    => $this->contracts->build($tenant),
        ]);
    }
}
