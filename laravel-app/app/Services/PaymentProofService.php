<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentProof;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ABA KHQR receipts sent by tenants. One transfer = one receipt = one payment:
 *  - the same picture (SHA-256) or the same ABA transaction ID cannot be sent twice
 *  - a receipt is confirmed only once, and never for more than the invoice still owes
 *  - removing the payment puts the receipt back to "pending" (see InvoiceService::deletePayment)
 */
class PaymentProofService
{
    public const DISK = 'local'; // private: storage/app/private

    public function __construct(private InvoiceService $invoiceService)
    {
    }

    public function submit(Tenant $tenant, Invoice $invoice, array $data, UploadedFile $image): PaymentProof
    {
        if ($invoice->isVoid() || $invoice->balance() <= 0) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has nothing left to pay.']);
        }

        $pending = PaymentProof::where('invoice_id', $invoice->id)->where('status', 'pending')->count();

        if ($pending >= PaymentProof::MAX_PENDING) {
            throw ValidationException::withMessages(['image' => 'You already sent ' . PaymentProof::MAX_PENDING . ' receipts for this invoice. Please wait for the owner to check them.']);
        }

        $hash = hash_file('sha256', $image->getRealPath());
        $ref = PaymentProof::normalizeRef($data['transaction_ref'] ?? null);

        // A rejected receipt may be sent again (e.g. with the right amount); a waiting or confirmed one may not
        $active = PaymentProof::with('invoice')->whereIn('status', ['pending', 'confirmed']);

        if ($same = (clone $active)->where('image_hash', $hash)->first()) {
            throw ValidationException::withMessages([
                'image' => "This picture was already sent on {$same->created_at->toDateString()} ({$same->invoice?->invoice_number}).",
            ]);
        }

        if ($ref && ($same = (clone $active)->where('transaction_ref', $ref)->first())) {
            throw ValidationException::withMessages([
                'transaction_ref' => "This ABA transaction was already sent on {$same->created_at->toDateString()} ({$same->invoice?->invoice_number}).",
            ]);
        }

        return PaymentProof::create([
            'invoice_id'      => $invoice->id,
            'tenant_id'       => $tenant->id,
            'currency'        => $data['currency'],
            'amount'          => $data['amount'],
            'paid_on'         => $data['paid_on'],
            'transaction_ref' => $ref,
            'image_path'      => $image->store('payment-proofs', self::DISK),
            'image_hash'      => $hash,
            'status'          => 'pending',
        ]);
    }

    /**
     * Records the payment. $amount lets staff correct what the tenant typed (in the receipt's currency).
     */
    public function confirm(PaymentProof $proof, User $by, ?float $amount = null): PaymentProof
    {
        return DB::transaction(function () use ($proof, $by, $amount) {
            // Lock the row: two people pressing "Confirm" at the same moment record it once
            $proof = PaymentProof::lockForUpdate()->findOrFail($proof->id);

            if ($proof->status !== 'pending') {
                throw ValidationException::withMessages(['proof' => "This receipt is already {$proof->status}."]);
            }

            $reference = trim('KHQR #' . $proof->id . ' ' . ($proof->transaction_ref ?? ''));

            // Refuses more than the balance, like any payment
            $invoice = $this->invoiceService->addPayment($proof->invoice, [
                'paid_at'   => $proof->paid_on->toDateString(),
                'currency'  => $proof->currency,
                'amount'    => $amount ?? (float) $proof->amount,
                'method'    => 'bank',
                'reference' => mb_substr($reference, 0, 100),
                'notes'     => 'ABA KHQR receipt sent by the tenant',
            ]);

            $proof->update([
                'status'      => 'confirmed',
                'payment_id'  => $invoice->payments()->latest('id')->value('id'),
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ]);

            return $proof;
        });
    }

    public function reject(PaymentProof $proof, User $by, string $reason): PaymentProof
    {
        return DB::transaction(function () use ($proof, $by, $reason) {
            $proof = PaymentProof::lockForUpdate()->findOrFail($proof->id);

            if ($proof->status !== 'pending') {
                throw ValidationException::withMessages(['proof' => "This receipt is already {$proof->status}."]);
            }

            $proof->update(['status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);

            return $proof;
        });
    }

    public function imageResponse(PaymentProof $proof)
    {
        abort_unless(Storage::disk(self::DISK)->exists($proof->image_path), 404);

        return Storage::disk(self::DISK)->response($proof->image_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
