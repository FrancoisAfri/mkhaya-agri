<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPriceList;

/**
 * The price a customer pays for something on a day, and which list it came from.
 *
 * A list can belong to one customer, be the customer's assigned list, or be general; the customer's
 * own list wins, then the assigned one, then the general one, newest first. A list can be dated, and
 * an entry can price a catalogue item or a product by name — with a grade and unit when it matters
 * (an entry with no grade prices every grade). The answer always says where the price came from.
 */
class PriceListService
{
    /** @return array{price: ?float, basis: string} */
    public function priceFor(?FinParty $party, ?int $itemId, ?string $product, ?string $grade = null, ?string $unit = null, ?string $on = null): array
    {
        $on ??= today()->toDateString();
        $lists = FinPriceList::query()->with('items')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $on))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))
            ->where(fn ($q) => $q->whereNull('party_id')->when($party, fn ($q) => $q->orWhere('party_id', $party->id)))
            ->get()
            ->sortBy(fn (FinPriceList $l) => [$party && $l->party_id === $party->id ? 0 : ($party && $party->price_list_id === $l->id ? 1 : 2), -($l->valid_from?->timestamp ?? 0)]);

        foreach ($lists as $l) {
            if ($l->party_id === null && $party?->price_list_id && $party->price_list_id !== $l->id && $lists->contains('id', $party->price_list_id)) {
                continue; // the customer's assigned list stands in for the general ones
            }
            $entry = $l->items->first(fn ($i) => ($itemId && (int) $i->item_id === $itemId
                    || ($product !== null && $i->description !== null && mb_strtolower($i->description) === mb_strtolower($product)))
                && ($i->unit === null || $unit === null || $i->unit === $unit)
                && ($i->grade === null || $i->grade === $grade));
            if ($entry) {
                $whose = $l->party_id ? "this customer's list" : ($party?->price_list_id === $l->id ? "the customer's assigned list" : 'general list');

                return ['price' => (float) $entry->price, 'basis' => "{$l->name} ({$whose})".($l->valid_from ? ', from '.$l->valid_from->toDateString() : '')];
            }
        }

        return ['price' => null, 'basis' => 'No price list covers it; price it by hand.'];
    }
}
