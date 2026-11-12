<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\DepositSettlement;
use App\Models\DepositTransaction;
use App\Models\Announcement;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\MeterReading;
use App\Models\MoveInPayment;
use App\Models\MoveOutNotice;
use App\Models\PaymentProof;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\ContractService;
use App\Services\DepositService;
use App\Services\PaymentProofService;
use App\Services\RepairService;
use App\Support\PrivateFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenant portal. Every query is limited to the logged-in tenant,
 * so a tenant can never see another tenant's data.
 */
class PortalController extends Controller
{
    public function __construct(
        private DepositService $depositService,
        private RepairService $repairService,
        private PaymentProofService $proofService,
        private ContractService $contracts,
    ) {
    }

    // GET /api/portal/home
    public function home(Request $request)
    {
        $tenant = $this->tenant($request);
        $open = $this->invoicesOf($tenant)->whereIn('status', ['unpaid', 'partial'])->orderBy('due_date')->get();
        $next = $open->first();

        return response()->json(['data' => [
            'tenant_name'   => $tenant->full_name,
            'room_number'   => $tenant->room?->room_number,
            'status'        => $tenant->status,
            'move_in_date'  => $tenant->move_in_date?->toDateString(),
            'move_out_date' => $tenant->move_out_date?->toDateString(),
            'rent_usd'      => (float) ($tenant->room?->rent_price ?? 0),
            'exchange_rate' => (int) Setting::getValue('exchange_rate'),
            'balance_riel'  => (int) $open->sum(fn (Invoice $invoice) => $invoice->balance()),
            'open_count'    => $open->count(),
            'next_invoice'  => $next ? [
                'id'             => $next->id,
                'invoice_number' => $next->invoice_number,
                'due_date'       => $next->due_date?->toDateString(),
                'balance_riel'   => $next->balance(),
                'is_overdue'     => $next->isOverdue(),
            ] : null,
            'deposit_usd'   => $this->depositService->balance($tenant),
            'notice'        => MoveOutNotice::activeFor($tenant->id)?->present(),
            'notice_days'   => MoveOutNotice::NOTICE_DAYS,
            'announcements' => Announcement::current()->latest('starts_on')->limit(5)->get()->map->present(),
            'payment'       => self::paymentInfo(),
        ]]);
    }

    // GET /api/portal/invoices   with their lines and payments
    public function invoices(Request $request)
    {
        $invoices = $this->invoicesOf($this->tenant($request))
            ->with(['items', 'payments'])
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return InvoiceResource::collection($invoices);
    }

    // GET /api/portal/meter-readings   the last 12 months, with photos
    public function meterReadings(Request $request)
    {
        $tenant = $this->tenant($request);
        $photo = fn (?string $path) => PrivateFile::url($path);

        $readings = MeterReading::where('tenant_id', $tenant->id)
            ->orderByDesc('period')
            ->limit(12)
            ->get()
            ->map(fn (MeterReading $reading) => [
                'period'   => $reading->period,
                'electric' => [
                    'previous'  => $reading->electric_previous === null ? null : (float) $reading->electric_previous,
                    'current'   => $reading->electric_current === null ? null : (float) $reading->electric_current,
                    'usage'     => (float) $reading->electric_usage,
                    'amount'    => (int) $reading->electric_amount,
                    'photo_url' => $photo($reading->electric_photo_path),
                ],
                'water'    => [
                    'previous'  => $reading->water_previous === null ? null : (float) $reading->water_previous,
                    'current'   => $reading->water_current === null ? null : (float) $reading->water_current,
                    'usage'     => (float) $reading->water_usage,
                    'amount'    => (int) $reading->water_amount,
                    'photo_url' => $photo($reading->water_photo_path),
                ],
            ]);

        return response()->json(['data' => $readings]);
    }

    // GET /api/portal/deposit
    public function deposit(Request $request)
    {
        $tenant = $this->tenant($request);
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->first();
        $settlement = DepositSettlement::where('tenant_id', $tenant->id)->first();

        return response()->json(['data' => [
            'balance_usd' => $this->depositService->balance($tenant),
            'move_in'     => $moveIn ? [
                'paid_at'          => $moveIn->paid_at->toDateString(),
                'deposit_usd'      => (float) $moveIn->deposit_usd,
                'prepaid_usd'      => (float) $moveIn->prepaid_usd,
                'covers_period'    => $moveIn->covers_period,
                'covers_days'      => $moveIn->covers_days,
                'days_in_month'    => $moveIn->days_in_month,
                'rent_covered_usd' => (float) $moveIn->rent_covered_usd,
                'credit_usd'       => (float) $moveIn->credit_usd,
            ] : null,
            'transactions' => DepositTransaction::where('tenant_id', $tenant->id)
                ->orderBy('occurred_on')
                ->orderBy('id')
                ->get(['type', 'amount_usd', 'occurred_on', 'description'])
                ->map(fn ($item) => [
                    'type'        => $item->type,
                    'amount_usd'  => (float) $item->amount_usd,
                    'occurred_on' => $item->occurred_on->toDateString(),
                    'description' => $item->description,
                ]),
            'settlement' => $settlement ? [
                'settled_on'        => $settlement->settled_on->toDateString(),
                'deposit_usd'       => (float) $settlement->deposit_usd,
                'unused_rent_usd'   => (float) $settlement->unused_rent_usd,
                'unused_credit_usd' => (float) $settlement->unused_credit_usd,
                'invoices_usd'      => (float) $settlement->invoices_usd,
                'damage_usd'        => (float) $settlement->damage_usd,
                'overstay_days'     => $settlement->overstay_days,
                'overstay_usd'      => (float) $settlement->overstay_usd,
                'other_usd'         => (float) $settlement->other_usd,
                'refund_usd'        => (float) $settlement->refund_usd,
                'owed_usd'          => (float) $settlement->owed_usd,
            ] : null,
        ]]);
    }

    // POST /api/portal/notice   { planned_move_out_date, reason }
    public function storeNotice(Request $request)
    {
        $tenant = $this->tenant($request);

        if ($tenant->status !== 'active') {
            throw ValidationException::withMessages(['planned_move_out_date' => 'You have already moved out.']);
        }

        if (MoveOutNotice::activeFor($tenant->id)) {
            throw ValidationException::withMessages(['planned_move_out_date' => 'You have already given notice. Cancel it first to change the date.']);
        }

        $data = $request->validate([
            'planned_move_out_date' => ['required', 'date', 'after:today', 'before:+1 year'],
            'reason'                => ['nullable', 'string', 'max:500'],
        ]);

        $notice = MoveOutNotice::create([
            'tenant_id'             => $tenant->id,
            'given_on'              => now()->toDateString(), // the server date, not one the tenant can choose
            'planned_move_out_date' => $data['planned_move_out_date'],
            'reason'                => $data['reason'] ?? null,
            'created_by'            => $request->user()->id,
        ]);

        return response()->json(['message' => 'Your notice is sent to the owner.', 'data' => $notice->present()], 201);
    }

    // DELETE /api/portal/notice
    public function cancelNotice(Request $request)
    {
        $notice = MoveOutNotice::activeFor($this->tenant($request)->id);

        if (! $notice) {
            throw ValidationException::withMessages(['notice' => 'There is no notice to cancel.']);
        }

        $notice->update(['cancelled_at' => now()]);

        return response()->json(['message' => 'Your notice is cancelled.']);
    }

    // GET /api/portal/repairs
    public function repairs(Request $request)
    {
        $repairs = MaintenanceRequest::with(['invoice', 'approver'])
            ->where('tenant_id', $this->tenant($request)->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (MaintenanceRequest $item) => $item->present(false));

        return response()->json(['data' => $repairs]);
    }

    // POST /api/portal/repairs   { title, description, priority, photo }
    public function storeRepair(Request $request)
    {
        $tenant = $this->tenant($request);

        if ($tenant->status !== 'active') {
            throw ValidationException::withMessages(['title' => 'You have already moved out.']);
        }

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority'    => ['nullable', Rule::in(MaintenanceRequest::PRIORITIES)],
            'photo'       => ['nullable', 'image', 'max:5120'], // 5 MB
        ]);

        // At most 10 new requests a day, so the list cannot be flooded
        $today = MaintenanceRequest::where('tenant_id', $tenant->id)->whereDate('created_at', today())->count();

        if ($today >= 10) {
            throw ValidationException::withMessages(['title' => 'Too many requests today. Please call the owner.']);
        }

        $repair = MaintenanceRequest::create([
            'tenant_id'   => $tenant->id,
            'room_id'     => $tenant->room_id,
            'status'      => 'open',
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'] ?? 'normal',
            'photo_path'  => $request->hasFile('photo') ? PrivateFile::store($request->file('photo'), 'repair-photos') : null,
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['message' => 'Your request is sent.', 'data' => $repair->present(false)], 201);
    }

    // DELETE /api/portal/repairs/{maintenanceRequest}   cancel a request that is not started yet
    public function cancelRepair(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        abort_if($maintenanceRequest->tenant_id !== $this->tenant($request)->id, 404);

        if ($maintenanceRequest->status !== 'open' || $maintenanceRequest->isCharged()) {
            throw ValidationException::withMessages(['repair' => 'This repair has started and cannot be cancelled.']);
        }

        $maintenanceRequest->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled.']);
    }

    // POST /api/portal/repairs/{maintenanceRequest}/approve   agree to pay the cost estimate
    public function approveRepair(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        abort_if($maintenanceRequest->tenant_id !== $this->tenant($request)->id, 404);
        $repair = $this->repairService->approve($maintenanceRequest, $request->user());

        return response()->json(['message' => 'Thank you. You agreed to the cost.', 'data' => $repair->refresh()->load(['invoice', 'approver'])->present(false)]);
    }

    // POST /api/portal/repairs/{maintenanceRequest}/decline   { reason }
    public function declineRepair(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        abort_if($maintenanceRequest->tenant_id !== $this->tenant($request)->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $repair = $this->repairService->decline($maintenanceRequest, $data['reason'] ?? null);

        return response()->json(['message' => 'The owner will see that you did not agree.', 'data' => $repair->refresh()->load(['invoice', 'approver'])->present(false)]);
    }

    // GET /api/portal/proofs   the tenant's own KHQR receipts
    public function proofs(Request $request)
    {
        $proofs = PaymentProof::with('invoice')
            ->where('tenant_id', $this->tenant($request)->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PaymentProof $proof) => $proof->present(url("/api/portal/proofs/{$proof->id}/image"), false));

        return response()->json(['data' => $proofs, 'payment' => self::paymentInfo()]);
    }

    // POST /api/portal/invoices/{invoice}/proofs   { image, amount, currency, paid_on, transaction_ref }
    public function storeProof(Request $request, Invoice $invoice)
    {
        $tenant = $this->tenant($request);
        abort_if($invoice->tenant_id !== $tenant->id, 404);

        // Cambodia time (UTC+7): a payment at 6 AM in Phnom Penh is still "yesterday" in UTC
        $today = now('Asia/Phnom_Penh')->toDateString();

        $data = $request->validate([
            'image'           => ['required', 'image', 'max:5120'], // 5 MB
            'currency'        => ['required', Rule::in(['KHR', 'USD'])],
            'amount'          => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'paid_on'         => ['required', 'date', 'before_or_equal:' . $today, 'after:-60 days'],
            'transaction_ref' => ['nullable', 'string', 'max:60'],
        ]);

        $proof = $this->proofService->submit($tenant, $invoice, $data, $request->file('image'));

        return response()->json([
            'message' => 'Your receipt is sent. The owner will check it.',
            'data'    => $proof->load('invoice')->present(url("/api/portal/proofs/{$proof->id}/image"), false),
        ], 201);
    }

    // GET /api/portal/proofs/{paymentProof}/image   only the tenant's own receipt
    public function proofImage(Request $request, PaymentProof $paymentProof)
    {
        abort_if($paymentProof->tenant_id !== $this->tenant($request)->id, 404);

        return $this->proofService->imageResponse($paymentProof);
    }

    // The owner's ABA KHQR, shown to tenants who still owe money
    private static function paymentInfo(): array
    {
        $path = Setting::getValue('payment_qr_path');

        return [
            'qr_url'  => $path ? Storage::disk('public')->url($path) : null,
            'account' => Setting::getValue('payment_account') ?: null,
        ];
    }

    // GET /api/portal/contact   the owner's name and phone for the help page (from the contract settings)
    public function contact()
    {
        return response()->json(['data' => [
            'name'  => Setting::getValue('landlord_name') ?: null,
            'phone' => Setting::getValue('landlord_phone') ?: null,
        ]]);
    }

    // GET /api/portal/contract   the tenant's own rental contract, read only
    public function contract(Request $request)
    {
        return response()->json(['data' => $this->contracts->build($this->tenant($request))]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;

        abort_if(! $tenant, 403, 'This login is not linked to a tenant.');

        return $tenant->loadMissing('room');
    }

    private function invoicesOf(Tenant $tenant)
    {
        return Invoice::valid()->where('tenant_id', $tenant->id);
    }
}
