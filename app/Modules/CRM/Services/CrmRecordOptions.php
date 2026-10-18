<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;

/**
 * Builds the "pick a record" option lists used by activity / discussion forms,
 * so users choose an actual record (e.g. "Apolo · Acme") from a searchable
 * dropdown instead of typing a raw numeric Record ID.
 */
class CrmRecordOptions
{
    /**
     * @param  array<int,string>  $types  any of lead|contact|account|deal
     * @return array<string, array<int, array{value:int, title:string}>>
     */
    public function forTypes(array $types = ['lead', 'contact', 'account', 'deal'], int $limit = 500): array
    {
        $out = [];
        foreach ($types as $type) {
            $out[$type] = match ($type) {
                'lead' => CrmLead::latest()->limit($limit)->get()->map(fn ($l) => [
                    'value' => $l->id,
                    'title' => trim($l->first_name.' '.$l->last_name).($l->company ? " · {$l->company}" : '') ?: "Lead #{$l->id}",
                ])->all(),
                'contact' => CrmContact::latest()->limit($limit)->get()->map(fn ($c) => [
                    'value' => $c->id,
                    'title' => trim($c->first_name.' '.$c->last_name) ?: "Contact #{$c->id}",
                ])->all(),
                'account' => CrmAccount::latest()->limit($limit)->get()->map(fn ($a) => [
                    'value' => $a->id,
                    'title' => $a->name ?: "Account #{$a->id}",
                ])->all(),
                'deal' => CrmDeal::latest()->limit($limit)->get()->map(fn ($d) => [
                    'value' => $d->id,
                    'title' => $d->title ?: "Deal #{$d->id}",
                ])->all(),
                default => [],
            };
        }
        return $out;
    }

    /** Flatten options into type => [id => title] for fast labelling. */
    public function labels(array $options): array
    {
        $lookup = [];
        foreach ($options as $type => $records) {
            foreach ($records as $r) {
                $lookup[$type][$r['value']] = $r['title'];
            }
        }
        return $lookup;
    }
}
