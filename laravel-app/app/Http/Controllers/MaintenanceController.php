<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Services\RepairService;
use App\Support\PrivateFile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Owner and staff: repair requests
class MaintenanceController extends Controller
{
    public function __construct(private RepairService $repairService)
    {
    }

    // GET /api/maintenance-requests?status=active   urgent first
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_merge(MaintenanceRequest::STATUSES, ['active', 'waiting']))]]);
        $status = $data['status'] ?? 'active';

        $requests = MaintenanceRequest::with(['tenant', 'room', 'invoice', 'approver'])
            ->when($status === 'active', fn ($query) => $query->whereIn('status', ['open', 'in_progress']))
            ->when($status === 'waiting', fn ($query) => $query->whereIn('status', ['open', 'in_progress'])->where('approval', 'pending'))
            ->when(! in_array($status, ['active', 'waiting'], true), fn ($query) => $query->where('status', $status))
            ->orderByRaw("case priority when 'urgent' then 0 else 1 end")
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (MaintenanceRequest $item) => $item->present());

        return response()->json(['data' => $requests]);
    }

    // POST /api/maintenance-requests   staff notes a repair for a tenant's room
    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_id'   => ['required', Rule::exists('tenants', 'id')->where('status', 'active')],
            'title'       => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority'    => ['nullable', Rule::in(MaintenanceRequest::PRIORITIES)],
            'photo'       => ['nullable', 'image', 'max:5120'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $item = MaintenanceRequest::create([
            'tenant_id'   => $tenant->id,
            'room_id'     => $tenant->room_id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'] ?? 'normal',
            'status'      => 'open',
            'photo_path'  => $request->hasFile('photo') ? PrivateFile::store($request->file('photo'), 'repair-photos') : null,
            'created_by'  => $request->user()->id,
        ]);

        return $this->reply('Repair added.', $item, 201);
    }

    // PATCH /api/maintenance-requests/{id}   { priority, staff_note }
    public function update(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $data = $request->validate([
            'priority'   => ['required', Rule::in(MaintenanceRequest::PRIORITIES)],
            'staff_note' => ['nullable', 'string', 'max:500'],
        ]);

        $maintenanceRequest->update($data);

        return $this->reply('Repair updated.', $maintenanceRequest);
    }

    // POST /api/maintenance-requests/{id}/quote   { materials_usd, labor_usd, paid_by, charge_mode, staff_note }
    public function quote(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $data = $request->validate([
            'materials_usd' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'labor_usd'     => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'paid_by'       => ['required', Rule::in(MaintenanceRequest::PAID_BY)],
            'charge_mode'   => ['nullable', 'required_if:paid_by,tenant', Rule::in(MaintenanceRequest::CHARGE_MODES)],
            'staff_note'    => ['nullable', 'string', 'max:500'],
        ], ['charge_mode.required_if' => 'Choose how the tenant pays.']);

        $repair = $this->repairService->quote($maintenanceRequest, $data);
        $message = $repair->approval === 'pending' ? 'Cost saved. Waiting for the tenant to agree.' : 'Cost saved.';

        return $this->reply($message, $repair);
    }

    // POST /api/maintenance-requests/{id}/approve   the tenant agreed in person
    public function approve(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        return $this->reply('Approval recorded.', $this->repairService->approve($maintenanceRequest, $request->user()));
    }

    // POST /api/maintenance-requests/{id}/start
    public function start(MaintenanceRequest $maintenanceRequest)
    {
        return $this->reply('Repair started.', $this->repairService->start($maintenanceRequest));
    }

    // POST /api/maintenance-requests/{id}/complete   { after_photo, receipt_photo, staff_note }
    public function complete(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $data = $request->validate([
            'after_photo'   => ['nullable', 'image', 'max:5120'],
            'receipt_photo' => ['nullable', 'image', 'max:5120'],
            'staff_note'    => ['nullable', 'string', 'max:500'],
        ]);

        $repair = $this->repairService->complete(
            $maintenanceRequest,
            $request->file('after_photo'),
            $request->file('receipt_photo'),
            $data['staff_note'] ?? null,
        );

        return $this->reply('Repair done.', $repair);
    }

    // POST /api/maintenance-requests/{id}/cancel
    public function cancel(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        // Cancelling voids the repair invoice, and only the owner voids invoices
        abort_if($maintenanceRequest->invoice_id && $request->user()->role !== 'owner', 403, 'Only the owner can cancel a repair that has an invoice.');

        return $this->reply('Repair cancelled.', $this->repairService->cancel($maintenanceRequest));
    }

    // POST /api/maintenance-requests/{id}/invoice   make the repair invoice again
    public function issueInvoice(MaintenanceRequest $maintenanceRequest)
    {
        return $this->reply('Repair invoice made.', $this->repairService->issueInvoice($maintenanceRequest));
    }

    private function reply(string $message, MaintenanceRequest $repair, int $status = 200)
    {
        $repair->refresh()->load(['tenant', 'room', 'invoice', 'approver']);

        return response()->json(['message' => $message, 'data' => $repair->present()], $status);
    }
}
