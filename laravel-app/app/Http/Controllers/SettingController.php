<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\UtilityTariff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    // GET /api/settings
    public function index()
    {
        return response()->json(['data' => self::withQrUrl(Setting::allValues())]);
    }

    // PUT /api/settings
    public function update(Request $request)
    {
        $data = $request->validate([
            'exchange_rate'          => ['sometimes', 'required', 'integer', 'min:1000', 'max:10000'],
            'electric_tier_mode'     => ['sometimes', 'required', Rule::in(UtilityTariff::MODES)],
            'water_tier_mode'        => ['sometimes', 'required', Rule::in(UtilityTariff::MODES)],
            'electric_tiers'         => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'electric_tiers.*.up_to' => ['nullable', 'numeric', 'min:1'],
            'electric_tiers.*.rate'  => ['required', 'integer', 'min:0', 'max:100000'],
            'water_tiers'            => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'water_tiers.*.up_to'    => ['nullable', 'numeric', 'min:1'],
            'water_tiers.*.rate'     => ['required', 'integer', 'min:0', 'max:100000'],
            'electric_min_charge'    => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
            'water_min_charge'       => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
            'usage_warning_percent'  => ['sometimes', 'required', 'integer', 'min:10', 'max:1000'],
            'payment_account'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'landlord_name'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'landlord_gender'        => ['sometimes', 'required', Rule::in(['male', 'female'])],
            'landlord_nationality'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'landlord_id_card'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'landlord_phone'         => ['sometimes', 'nullable', 'string', 'max:30'],
            'property_address'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'contract_term_months'   => ['sometimes', 'required', 'integer', 'min:1', 'max:60'],
            'payment_due_days'       => ['sometimes', 'required', 'integer', 'min:1', 'max:31'],
            'contract_extra_terms'   => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        foreach (['electric_tiers', 'water_tiers'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = $this->checkTiers($key, $data[$key]);
            }
        }

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json([
            'data'    => self::withQrUrl(Setting::allValues()),
            'message' => 'Settings saved successfully.',
        ]);
    }

    // POST /api/settings/payment-qr   { image }   the owner's ABA KHQR picture, shown to tenants
    public function uploadPaymentQr(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'max:2048']]);

        $old = Setting::getValue('payment_qr_path');
        Setting::setValue('payment_qr_path', $request->file('image')->store('payment-qr', 'public'));

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['data' => self::withQrUrl(Setting::allValues()), 'message' => 'KHQR picture saved.']);
    }

    // DELETE /api/settings/payment-qr
    public function removePaymentQr()
    {
        if ($old = Setting::getValue('payment_qr_path')) {
            Storage::disk('public')->delete($old);
        }

        Setting::where('key', 'payment_qr_path')->delete();

        return response()->json(['data' => self::withQrUrl(Setting::allValues()), 'message' => 'KHQR picture removed.']);
    }

    public static function withQrUrl(array $settings): array
    {
        $path = $settings['payment_qr_path'] ?? null;

        return $settings + ['payment_qr_url' => $path ? Storage::disk('public')->url($path) : null];
    }

    // Tiers must go up step by step and end with one tier that has no upper limit
    private function checkTiers(string $key, array $tiers): array
    {
        $tiers = array_map(fn ($tier) => [
            'up_to' => isset($tier['up_to']) && $tier['up_to'] !== '' ? (float) $tier['up_to'] : null,
            'rate'  => (int) $tier['rate'],
        ], $tiers);

        $unlimited = array_filter($tiers, fn ($tier) => $tier['up_to'] === null);
        if (count($unlimited) !== 1) {
            throw ValidationException::withMessages([
                $key => 'Exactly one tier must have no upper limit (the last tier, e.g. "> 200").',
            ]);
        }

        $limits = array_column(array_filter($tiers, fn ($tier) => $tier['up_to'] !== null), 'up_to');
        if (count($limits) !== count(array_unique($limits))) {
            throw ValidationException::withMessages([
                $key => 'Two tiers cannot have the same upper limit.',
            ]);
        }

        return UtilityTariff::sorted($tiers);
    }
}
