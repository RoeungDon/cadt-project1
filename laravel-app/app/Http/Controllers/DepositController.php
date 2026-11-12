<?php

namespace App\Http\Controllers;

use App\Models\DepositSettlement;
use App\Models\MoveInPayment;
use App\Models\Tenant;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepositController extends Controller
{
    public function __construct(private DepositService $depositService)
    {
    }

    // GET /api/tenants/{tenant}/deposit?notice_given=1
    public function show(Request $request, Tenant $tenant)
    {
        return response()->json([
            'data' => $this->depositService->summary($tenant, $request->boolean('notice_given', true)),
        ]);
    }

    // POST /api/tenants/{tenant}/move-in-payment
    public function storeMoveIn(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'deposit_usd' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'prepaid_usd' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'paid_at'     => ['required', 'date'],
            'method'      => ['required', Rule::in(MoveInPayment::METHODS)],
            'reference'   => ['nullable', 'string', 'max:100'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ]);

        $this->depositService->recordMoveIn($tenant, $data);

        return response()->json([
            'message' => 'Move-in payment saved.',
            'data'    => $this->depositService->summary($tenant->fresh()),
        ], 201);
    }

    // DELETE /api/move-in-payments/{moveInPayment}
    public function destroyMoveIn(MoveInPayment $moveInPayment)
    {
        $tenant = $moveInPayment->tenant;
        $this->depositService->deleteMoveIn($moveInPayment);

        return response()->json([
            'message' => 'Move-in payment removed.',
            'data'    => $this->depositService->summary($tenant),
        ]);
    }

    // POST /api/tenants/{tenant}/settlement
    public function settle(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'settled_on'                => ['nullable', 'date'],
            'overstay_days'             => ['nullable', 'integer', 'min:0', 'max:' . DepositService::MAX_OVERSTAY_DAYS],
            'notice_given'              => ['nullable', 'boolean'],
            'notes'                     => ['nullable', 'string', 'max:500'],
            'deductions'                => ['nullable', 'array'],
            'deductions.*.type'         => ['required', Rule::in(['damage', 'other'])],
            'deductions.*.description'  => ['required', 'string', 'max:255'],
            'deductions.*.amount_usd'   => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ]);

        $this->depositService->settle($tenant, $data);

        return response()->json([
            'message' => 'Deposit settled.',
            'data'    => $this->depositService->summary($tenant->fresh()),
        ], 201);
    }

    // DELETE /api/deposit-settlements/{depositSettlement}
    public function cancelSettlement(DepositSettlement $depositSettlement)
    {
        $tenant = $depositSettlement->tenant;
        $this->depositService->cancelSettlement($depositSettlement);

        return response()->json([
            'message' => 'Settlement cancelled.',
            'data'    => $this->depositService->summary($tenant),
        ]);
    }
}