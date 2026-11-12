<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use App\Services\PaymentProofService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Owner and staff: ABA KHQR receipts sent by tenants
class PaymentProofController extends Controller
{
    public function __construct(private PaymentProofService $proofService)
    {
    }

    // GET /api/payment-proofs?status=pending
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(PaymentProof::STATUSES)]]);
        $status = $data['status'] ?? 'pending';

        $proofs = PaymentProof::with(['invoice', 'tenant', 'reviewer'])
            ->where('status', $status)
            ->when($status === 'pending', fn ($query) => $query->oldest('id'), fn ($query) => $query->latest('reviewed_at')->latest('id'))
            ->limit(100)
            ->get()
            ->map(fn (PaymentProof $proof) => $proof->present(url("/api/payment-proofs/{$proof->id}/image")));

        return response()->json([
            'data'    => $proofs,
            'pending' => PaymentProof::where('status', 'pending')->count(),
        ]);
    }

    // GET /api/payment-proofs/{paymentProof}/image   private: needs a login
    public function image(PaymentProof $paymentProof)
    {
        return $this->proofService->imageResponse($paymentProof);
    }

    // POST /api/payment-proofs/{paymentProof}/confirm   { amount }   checked in ABA first
    public function confirm(Request $request, PaymentProof $paymentProof)
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999']]);
        $proof = $this->proofService->confirm($paymentProof, $request->user(), isset($data['amount']) ? (float) $data['amount'] : null);

        return response()->json(['message' => 'Payment recorded.', 'data' => $this->present($proof)]);
    }

    // POST /api/payment-proofs/{paymentProof}/reject   { reason }   the tenant sees the reason
    public function reject(Request $request, PaymentProof $paymentProof)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $proof = $this->proofService->reject($paymentProof, $request->user(), $data['reason']);

        return response()->json(['message' => 'Receipt rejected.', 'data' => $this->present($proof)]);
    }

    private function present(PaymentProof $proof): array
    {
        return $proof->refresh()->load(['invoice', 'tenant', 'reviewer'])->present(url("/api/payment-proofs/{$proof->id}/image"));
    }
}
