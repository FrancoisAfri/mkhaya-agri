<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class FinanceItemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/Items', [
            'items' => FinItem::with(['salesAccount:id,code,name', 'purchaseAccount:id,code,name'])
                ->orderBy('code')->get()
                ->map(fn (FinItem $i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description,
                    'image' => $i->image, 'unit' => $i->unit, 'type' => $i->type,
                    'sales_price' => (float) $i->sales_price, 'cost_price' => (float) $i->cost_price,
                    'tax_rate' => (float) $i->tax_rate,
                    'sales_account_id' => $i->sales_account_id, 'purchase_account_id' => $i->purchase_account_id,
                    'sales_account' => $i->salesAccount ? "{$i->salesAccount->code} · {$i->salesAccount->name}" : null,
                    'purchase_account' => $i->purchaseAccount ? "{$i->purchaseAccount->code} · {$i->purchaseAccount->name}" : null,
                    'is_active' => $i->is_active,
                ]),
            'incomeAccounts'  => $this->accounts('income'),
            'expenseAccounts' => $this->accounts('expense'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        FinItem::create($this->rules($request));

        return back()->with('success', 'Item saved.');
    }

    public function update(Request $request, FinItem $item): RedirectResponse
    {
        $item->update($this->rules($request));

        return back()->with('success', 'Item updated.');
    }

    private function accounts(string $type)
    {
        return FinAccount::postable()->where('type', $type)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]);
    }

    private function rules(Request $request): array
    {
        return $this->letDefaultsStand($request->validate([
            'code'                => 'nullable|string|max:60', // auto-generated when blank
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string|max:1000',
            'image'               => 'nullable|string|max:3000000', // base64 data URI (~2MB image)
            'unit'                => 'nullable|string|max:30',
            'type'                => 'required|in:service,product',
            'sales_price'         => 'nullable|numeric|min:0',
            'cost_price'          => 'nullable|numeric|min:0',
            'sales_account_id'    => 'nullable|exists:fin_accounts,id',
            'purchase_account_id' => 'nullable|exists:fin_accounts,id',
            'tax_rate'            => 'nullable|numeric|min:0|max:100',
            'is_active'           => 'boolean',
        ]));
    }

    /**
     * Drop the blanks that would be written as null over a column that refuses one.
     *
     * A field the form did not fill arrives as null, and null is not the same as absent: passing
     * the key hands the insert an explicit null, which overrides the column's own default and
     * trips NOT NULL. Quick-adding an item from the quote screen sends no unit, so `unit` took the
     * form down with a 500 — but `sales_price`, `cost_price`, `tax_rate` and `is_active` are the
     * same shape and were only saved by happening to be filled in.
     *
     * Read off the schema rather than listed here, because a list of columns is right on the day
     * it is written and wrong the first time somebody adds one.
     *
     * Only NOT NULL columns are dropped. A null on a nullable column is a real instruction —
     * "clear this description" — and removing it from an update would silently ignore the user.
     * A null on a NOT NULL column can never be that instruction; it is only ever a crash.
     */
    private function letDefaultsStand(array $data): array
    {
        $table = (new FinItem)->getTable();

        $mandatory = once(fn () => collect(Schema::getColumns($table))
            ->reject(fn (array $column) => $column['nullable'])
            ->pluck('name')
            ->all());

        foreach ($mandatory as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
