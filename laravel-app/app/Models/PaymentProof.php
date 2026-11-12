<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    public const STATUSES = ['pending', 'confirmed', 'rejected'];

    // Waiting proofs one invoice may have at the same time
    public const MAX_PENDING = 3;

    protected $guarded = ['id'];

    protected $casts = [
        'amount'      => 'decimal:2',
        'paid_on'     => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // "abc 123-45" → "ABC12345", so the same ABA number typed two ways is still found
    public static function normalizeRef(?string $ref): ?string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $ref));

        return $clean === '' ? null : $clean;
    }

    /**
     * $imageUrl: the API address that streams the private picture to a logged-in user
     */
    public function present(string $imageUrl, bool $forStaff = true): array
    {
        return [
            'id'              => $this->id,
            'invoice_id'      => $this->invoice_id,
            'invoice_number'  => $this->invoice?->invoice_number,
            'currency'        => $this->currency,
            'amount'          => (float) $this->amount,
            'paid_on'         => $this->paid_on->toDateString(),
            'transaction_ref' => $this->transaction_ref,
            'image_url'       => $imageUrl,
            'status'          => $this->status,
            'reject_reason'   => $this->reject_reason,
            'reviewed_at'     => $this->reviewed_at?->toDateTimeString(),
            'created_at'      => $this->created_at?->toDateTimeString(),
        ] + ($forStaff ? [
            'tenant_name'     => $this->tenant?->full_name,
            'room_number'     => $this->invoice?->room_number,
            'balance_riel'    => $this->invoice?->balance(),
            'exchange_rate'   => $this->invoice?->exchange_rate,
            'reviewed_by'     => $this->reviewer?->name,
        ] : []);
    }
}
