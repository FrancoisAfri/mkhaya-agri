<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetCard;
use App\Modules\Fleet\Models\FleetCardTransaction;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FleetCardController extends Controller
{
    public function index(): Response
    {
        $cards = FleetCard::with(['vehicle', 'driver.user'])
            ->withCount('transactions')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => [
                'id'                   => $c->id,
                'masked_number'        => $c->masked_number,
                'card_number_last4'    => $c->card_number_last4,
                'card_type'            => $c->card_type,
                'provider'             => $c->provider,
                'vehicle'              => $c->vehicle?->registration_number,
                'driver'               => $c->driver?->user?->name ?? $c->driver?->user?->email,
                'expiry_month'         => $c->expiry_month,
                'expiry_year'          => $c->expiry_year,
                'status'               => $c->status,
                'daily_limit'          => $c->daily_limit,
                'monthly_limit'        => $c->monthly_limit,
                'current_month_spend'  => $c->current_month_spend,
                'transactions_count'   => $c->transactions_count,
            ]);

$vehicles = FleetVehicle::whereNotIn('status', ['disposed'])
    ->orderBy('registration_number')
    ->get(['id', 'registration_number', 'make', 'model'])
    ->map(fn($v) => [
        'id'           => $v->id,
        'registration' => $v->registration_number,
        'make'         => $v->make,
        'model'        => $v->model,
    ]);
        $drivers  = FleetDriver::active()->with('user')->get()
            ->map(fn($d) => ['id' => $d->id, 'name' => $d->user?->name ?? $d->user?->email]);

        $recentTransactions = FleetCardTransaction::with(['card', 'vehicle'])
            ->orderByDesc('transaction_at')
            ->limit(20)
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'card'             => $t->card?->masked_number,
                'vehicle'          => $t->vehicle?->registration_number,
                'transaction_type' => $t->transaction_type,
                'transaction_at'   => $t->transaction_at?->format('d M Y H:i'),
                'merchant_name'    => $t->merchant_name,
                'amount'           => $t->amount,
                'litres'           => $t->litres,
                'is_flagged'       => $t->is_flagged,
                'reconciled'       => $t->reconciled,
            ]);

        return Inertia::render('Modules/Fleet/Cards/Index', [
            'cards'               => $cards,
            'vehicles'            => $vehicles,
            'drivers'             => $drivers,
            'recentTransactions'  => $recentTransactions,
        ]);
    }

   public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'card_number_last4' => 'required|digits:4',
        'card_type'         => 'required|in:fuel,toll,multi',
        'provider'          => 'nullable|string|max:60',
        'vehicle_id'        => 'nullable|exists:fleet_vehicles,id',
        'driver_id'         => 'nullable|exists:fleet_drivers,id',
        'expiry_month'      => 'nullable|integer|min:1|max:12',
        'expiry_year'       => 'nullable|integer|min:' . date('Y'),
        'daily_limit'       => 'nullable|numeric|min:0',
        'monthly_limit'     => 'nullable|numeric|min:0',
        'status'            => 'required|in:active,blocked,cancelled',
        'notes'             => 'nullable|string',
    ]);

    $validated['uuid']       = (string) Str::uuid();
    $validated['card_token'] = hash('sha256', $validated['card_number_last4'] . Str::random(32));
    $validated['created_by'] = Auth::id();

    FleetCard::create($validated);

    return redirect()->back()->with('success', 'Fleet card registered successfully.');
}

    public function update(Request $request, FleetCard $card): RedirectResponse
    {
        $data = $request->validate([
            'card_type'    => 'required|in:fuel,toll,multi',
            'provider'     => 'nullable|string|max:60',
            'vehicle_id'   => 'nullable|exists:fleet_vehicles,id',
            'driver_id'    => 'nullable|exists:fleet_drivers,id',
            'status'       => 'required|in:active,blocked,expired,cancelled',
            'daily_limit'  => 'nullable|numeric|min:0',
            'monthly_limit'=> 'nullable|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        $data['updated_by'] = Auth::id();
        $data['version']    = $card->version + 1;
        $card->update($data);

        return back()->with('success', 'Fleet card updated.');
    }

    public function destroy(FleetCard $card): RedirectResponse
    {
        $card->update(['status' => 'cancelled', 'updated_by' => Auth::id()]);
        $card->delete();
        return back()->with('success', 'Fleet card cancelled.');
    }

    public function storeTransaction(Request $request, FleetCard $card): RedirectResponse
    {
        $data = $request->validate([
            'transaction_type' => 'required|in:fuel,toll,oil,service,other',
            'transaction_at'   => 'required|date',
            'merchant_name'    => 'nullable|string|max:100',
            'merchant_city'    => 'nullable|string|max:60',
            'amount'           => 'required|numeric|min:0',
            'litres'           => 'nullable|numeric|min:0',
            'price_per_litre'  => 'nullable|numeric|min:0',
            'odometer_reading' => 'nullable|numeric|min:0',
            'fuel_type'        => 'nullable|string|max:20',
            'vehicle_id'       => 'nullable|exists:fleet_vehicles,id',
            'driver_id'        => 'nullable|exists:fleet_drivers,id',
        ]);

        $data['fleet_card_id'] = $card->id;
        $data['created_by']    = Auth::id();

        $isFlagged = $card->daily_limit && $data['amount'] > $card->daily_limit;
        $data['is_flagged']  = $isFlagged;
        $data['flag_reason'] = $isFlagged ? 'Transaction exceeds daily card limit' : null;

        FleetCardTransaction::create($data);
        $card->increment('current_month_spend', $data['amount']);

        return back()->with('success', 'Transaction recorded.' . ($isFlagged ? ' ⚠️ Flagged: exceeds daily limit.' : ''));
    }

    public function flagTransaction(Request $request, FleetCardTransaction $transaction): RedirectResponse
    {
        $request->validate(['flag_reason' => 'required|string']);
        $transaction->update(['is_flagged' => true, 'flag_reason' => $request->flag_reason]);
        return back()->with('success', 'Transaction flagged.');
    }

    public function reconcileTransaction(FleetCardTransaction $transaction): RedirectResponse
    {
        $transaction->update(['reconciled' => true]);
        return back()->with('success', 'Transaction reconciled.');
    }
}
