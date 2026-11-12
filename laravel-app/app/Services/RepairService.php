<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Support\PrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A repair: open → (cost estimate → tenant agrees) → in progress → done.
 * When the tenant pays, it is charged in one of three ways:
 *   prepay         a repair invoice as soon as the tenant agrees, paid before the work
 *   on_completion  a repair invoice when the work is done, paid right away
 *   next_invoice   a line on the next monthly invoice
 */
class RepairService
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * Cost estimate: materials + labor, who pays and how.
     * When the tenant pays, the tenant must agree again.
     */
    public function quote(MaintenanceRequest $repair, array $data): MaintenanceRequest
    {
        $this->assertNotClosed($repair, ['open', 'in_progress', 'done']);

        if ($repair->isCharged()) {
            throw ValidationException::withMessages([
                'cost_usd' => 'This repair is already on an invoice. Void that invoice (or remove the line) first.',
            ]);
        }

        $materials = round((float) ($data['materials_usd'] ?? 0), 2);
        $labor = round((float) ($data['labor_usd'] ?? 0), 2);
        $tenantPays = $data['paid_by'] === 'tenant' && $materials + $labor > 0;

        $repair->update([
            'materials_usd'  => $materials,
            'labor_usd'      => $labor,
            'cost_usd'       => $materials + $labor,
            'paid_by'        => $data['paid_by'],
            'charge_mode'    => $tenantPays ? $data['charge_mode'] : null,
            'quoted_at'      => now(),
            'approval'       => $tenantPays ? 'pending' : null,
            'approved_at'    => null,
            'approved_by'    => null,
            'decline_reason' => null,
            'staff_note'     => $data['staff_note'] ?? $repair->staff_note,
        ]);

        return $repair;
    }

    // The tenant agrees in the portal, or staff records that the tenant agreed in person
    public function approve(MaintenanceRequest $repair, User $by): MaintenanceRequest
    {
        if ($repair->approval !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'There is no cost estimate waiting for approval.']);
        }

        return DB::transaction(function () use ($repair, $by) {
            $repair->update(['approval' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id, 'decline_reason' => null]);

            return $this->issueInvoiceIfDue($repair);
        });
    }

    public function decline(MaintenanceRequest $repair, ?string $reason): MaintenanceRequest
    {
        if ($repair->approval !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'There is no cost estimate waiting for approval.']);
        }

        $repair->update(['approval' => 'declined', 'decline_reason' => $reason]);

        return $repair;
    }

    // Work starts. Allowed even before payment, so urgent repairs never wait.
    public function start(MaintenanceRequest $repair): MaintenanceRequest
    {
        $this->assertNotClosed($repair, ['open']);
        $repair->update(['status' => 'in_progress']);

        return $repair;
    }

    // Work is done: photo after the repair and the shop receipt
    public function complete(MaintenanceRequest $repair, ?UploadedFile $after, ?UploadedFile $receipt, ?string $note): MaintenanceRequest
    {
        $this->assertNotClosed($repair, ['open', 'in_progress']);

        if ($repair->tenantPays() && $repair->approval !== 'approved') {
            throw ValidationException::withMessages([
                'approval' => 'The tenant has not agreed to pay this cost. Get approval, or change it to "Owner pays".',
            ]);
        }

        return DB::transaction(function () use ($repair, $after, $receipt, $note) {
            $repair->update([
                'status'             => 'done',
                'resolved_on'        => now(),
                'after_photo_path'   => $after ? PrivateFile::store($after, 'repair-photos') : $repair->after_photo_path,
                'receipt_photo_path' => $receipt ? PrivateFile::store($receipt, 'repair-photos') : $repair->receipt_photo_path,
                'staff_note'         => $note ?? $repair->staff_note,
            ]);

            return $this->issueInvoiceIfDue($repair);
        });
    }

    // Cancel: an unpaid repair invoice is voided with it
    public function cancel(MaintenanceRequest $repair): MaintenanceRequest
    {
        $this->assertNotClosed($repair, ['open', 'in_progress']);

        return DB::transaction(function () use ($repair) {
            if ($repair->invoice) {
                if ($repair->invoice->payments()->exists()) {
                    throw ValidationException::withMessages([
                        'status' => 'The tenant has paid this repair invoice. Remove (refund) the payments first.',
                    ]);
                }

                $this->invoiceService->void($repair->invoice, "Repair #{$repair->id} cancelled");
            }

            if ($repair->charged_item_id) {
                throw ValidationException::withMessages(['status' => 'This repair is on a monthly invoice. Remove that line first.']);
            }

            $repair->refresh()->update(['status' => 'cancelled']);

            return $repair;
        });
    }

    // Make the repair invoice again, e.g. after the old one was voided by mistake
    public function issueInvoice(MaintenanceRequest $repair): MaintenanceRequest
    {
        if ($repair->isCharged()) {
            throw ValidationException::withMessages(['invoice' => 'This repair is already on an invoice.']);
        }

        $this->issueInvoiceIfDue($repair);

        if ($repair->invoice_id === null) {
            throw ValidationException::withMessages(['invoice' => 'This repair does not need a repair invoice now.']);
        }

        return $repair;
    }

    private function issueInvoiceIfDue(MaintenanceRequest $repair): MaintenanceRequest
    {
        $due = $repair->tenantPays()
            && $repair->approval === 'approved'
            && ! $repair->isCharged()
            && $repair->status !== 'cancelled'
            && ($repair->charge_mode === 'prepay' || ($repair->charge_mode === 'on_completion' && $repair->status === 'done'));

        if ($due) {
            $invoice = $this->invoiceService->createRepairInvoice($repair);
            $repair->update(['invoice_id' => $invoice->id]);
        }

        return $repair;
    }

    private function assertNotClosed(MaintenanceRequest $repair, array $allowed): void
    {
        if (! in_array($repair->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "This cannot be done while the repair is {$repair->status}."]);
        }
    }
}
