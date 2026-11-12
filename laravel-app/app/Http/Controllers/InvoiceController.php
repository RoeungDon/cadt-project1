<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    // GET /api/invoices?period=2026-11&status=unpaid&search=A1&per_page=20
    public function index(Request $request)
    {
        $data = $request->validate([
            'period'   => ['nullable', 'date_format:Y-m'],
            'status'   => ['nullable', Rule::in(array_merge(Invoice::STATUSES, ['overdue']))],
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $inPeriod = Invoice::query()->when($data['period'] ?? null, fn ($query, $period) => $query->where('period', $period));
        $status = $data['status'] ?? null;

        $invoices = (clone $inPeriod)
            ->when($data['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('room_number', 'like', "%{$search}%")
                        ->orWhere('tenant_name', 'like', "%{$search}%");
                });
            })
            ->when($status === 'overdue', fn ($query) => $query
                ->whereIn('status', ['unpaid', 'partial'])
                ->whereDate('due_date', '<', today()))
            ->when($status && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            ->orderByDesc('period')
            ->orderBy('room_number')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 20);

        $valid = (clone $inPeriod)->valid();

        return InvoiceResource::collection($invoices)->additional([
            'summary' => [
                'count'   => (clone $valid)->count(),
                'billed'  => (int) (clone $valid)->sum('total_riel'),
                'paid'    => (int) (clone $valid)->sum('paid_riel'),
                'overdue' => (clone $valid)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())->count(),
            ],
        ]);
    }

    // GET /api/invoices/preview?period=2026-11
    public function preview(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => $this->invoiceService->preview($data['period'])]);
    }

    // POST /api/invoices/generate   { period, tenant_ids?, issue_date?, due_date? }
    public function generate(Request $request)
    {
        $data = $request->validate([
            'period'       => ['required', 'date_format:Y-m'],
            'tenant_ids'   => ['nullable', 'array', 'min:1'],
            'tenant_ids.*' => ['integer'],
            'issue_date'   => ['nullable', 'date'],
            'due_date'     => ['nullable', 'date', 'after_or_equal:issue_date'],
        ]);

        $invoices = $this->invoiceService->generate(
            $data['period'],
            $data['tenant_ids'] ?? null,
            $data['issue_date'] ?? null,
            $data['due_date'] ?? null,
        );
        $count = count($invoices);

        return response()->json([
            'message' => $count === 1 ? '1 invoice created.' : "{$count} invoices created.",
            'data'    => InvoiceResource::collection(collect($invoices)),
        ], 201);
    }

    // GET /api/invoices/{invoice}
    public function show(Invoice $invoice)
    {
        return new InvoiceResource($invoice->load('payments'));
    }

    // PATCH /api/invoices/{invoice}
    public function update(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'rent_usd'        => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'adjustment_riel' => ['sometimes', 'integer', 'min:-999999999', 'max:999999999'],
            'adjustment_note' => ['nullable', 'string', 'max:255'],
            'issue_date'      => ['sometimes', 'date'],
            'due_date'        => ['nullable', 'date'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice = $this->invoiceService->update($invoice, $data);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Invoice updated.']);
    }

    // POST /api/invoices/{invoice}/void   { reason }
    public function void(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $invoice = $this->invoiceService->void($invoice, $data['reason']);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Invoice voided.']);
    }

    // POST /api/invoices/{invoice}/items
    public function storeItem(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'type'        => ['required', Rule::in(InvoiceItem::TYPES)],
            'description' => ['nullable', 'required_if:type,other', 'string', 'max:255'],
            'quantity'    => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'unit_price'  => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency'    => ['required', Rule::in(InvoicePayment::CURRENCIES)],
        ], [
            'description.required_if' => 'Please describe this charge.',
        ]);

        $invoice = $this->invoiceService->addItem($invoice, $data);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Charge added.']);
    }

    // DELETE /api/invoices/{invoice}/items/{item}
    public function destroyItem(Invoice $invoice, InvoiceItem $item)
    {
        $invoice = $this->invoiceService->deleteItem($invoice, $item);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Charge removed.']);
    }

    // POST /api/invoices/{invoice}/payments
    public function storePayment(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'paid_at'   => ['required', 'date'],
            'currency'  => ['required', Rule::in(InvoicePayment::CURRENCIES)],
            'amount'    => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'method'    => ['required', Rule::in(InvoicePayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->invoiceService->addPayment($invoice, $data);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Payment saved.']);
    }

    // DELETE /api/invoices/{invoice}/payments/{payment}
    public function destroyPayment(Invoice $invoice, InvoicePayment $payment)
    {
        if ($payment->method === 'deposit') {
            throw ValidationException::withMessages(['payment' => 'This payment came from the deposit. Cancel the deposit settlement instead.']);
        }

        $invoice = $this->invoiceService->deletePayment($invoice, $payment);

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => 'Payment removed.']);
    }

    // GET /api/invoices/edc-check   EDC rooms whose bill is not confirmed as paid yet, oldest first
    public function edcCheck()
    {
        $invoices = Invoice::valid()
            ->whereIn('electric_route', ['direct', 'via_owner'])
            ->where('electric_amount', '>', 0)
            ->where(fn ($query) => $query->whereNull('edc_status')->orWhere('edc_status', '!=', 'paid'))
            ->orderBy('period')
            ->orderBy('room_number')
            ->get();

        return InvoiceResource::collection($invoices);
    }

    // POST /api/invoices/{invoice}/electric-route   { route: direct | via_owner }
    public function electricRoute(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['route' => ['required', Rule::in(['direct', 'via_owner'])]]);
        $invoice = $this->invoiceService->setElectricRoute($invoice, $data['route']);
        $message = $data['route'] === 'via_owner'
            ? 'Electricity is on this invoice now. Remember to pay the EDC bill.'
            : 'Electricity is paid to EDC by the tenant.';

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => $message]);
    }

    // POST /api/invoices/{invoice}/edc-status   { status: unchecked | paid | unpaid }  or  { old_balance: 50 }
    public function edcStatus(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'status'      => ['required_without:old_balance', 'nullable', Rule::in(Invoice::EDC_STATUSES)],
            'old_balance' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $invoice = $this->invoiceService->checkEdc($invoice, $data['status'] ?? null, $data['old_balance'] ?? null);
        $message = match ($invoice->edc_status) {
            'paid'   => 'EDC bill marked as paid.',
            'unpaid' => 'EDC bill marked as NOT paid.',
            default  => 'EDC check cleared.',
        };

        return (new InvoiceResource($invoice->load('payments')))->additional(['message' => $message]);
    }
}