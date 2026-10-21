import type { FormField } from '../Components/FormDialog.vue'

export type LivestockOptions = {
  farms: Array<{ id: string; name: string; status: string; fields: Array<{ id: string; code: string; name: string }> }>
  species: Array<{ id: number; name: string; tracking: string }>
  breeds: Array<{ id: number; species_id: number; name: string }>
  groups: Array<{ id: string; code: string; name: string; farm_id: string; species_id: number }>
  animals: Array<{ id: string; tag_number: string; farm_id: string; species_id: number; sex: string }>
  stock_items: Array<{ id: number; name: string; uom: string }>
  purposes: string[]; sexes: string[]; acquisitions: string[]; exits: string[]; health_types: string[]; event_types: string[]; count_reasons: string[]
  ownership?: string[]; programmes?: Array<{ id: string; name: string; species_id: number; withdrawal_days: number | null }>; rations?: Array<{ id: string; name: string }>
}

const activeFarms = (o: LivestockOptions) => o.farms.filter(f => f.status === 'active').map(f => ({ title: f.name, value: f.id }))
const camps = (o: LivestockOptions, farmId: string) => (o.farms.find(f => f.id === farmId)?.fields ?? []).map(f => ({ title: `${f.code} · ${f.name}`, value: f.id }))

export const groupFields = (o: LivestockOptions): FormField[] => [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: activeFarms(o) },
  { key: 'species_id', label: 'Species', type: 'select', required: true, items: o.species.map(s => ({ title: `${s.name}${s.tracking === 'group' ? ' (counted as a flock)' : ''}`, value: s.id })) },
  { key: 'code', label: 'Group code', required: true, cols: 4 },
  { key: 'name', label: 'Name', required: true, cols: 8 },
  { key: 'purpose', label: 'Purpose', type: 'select', required: true, items: o.purposes },
  { key: 'field_id', label: 'Camp / paddock / house', type: 'select', items: (f: any) => camps(o, f.farm_id) },
  { key: 'head_count', label: 'Opening head count', type: 'number', hint: 'For flocks counted as a group', show: (f: any) => o.species.find(s => s.id === f.species_id)?.tracking === 'group' },
  { key: 'started_on', label: 'Started on', type: 'date' },
  { key: 'batch_code', label: 'Batch code', hint: 'For a production batch: broilers, a feedlot intake' }, { key: 'target_head', label: 'Target head', type: 'number', hint: 'Herd size to keep, for replacement planning' },
  { key: 'budget_amount', label: 'Budget (total costs)', type: 'number' },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const animalFields = (o: LivestockOptions): FormField[] => [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: activeFarms(o) },
  { key: 'species_id', label: 'Species', type: 'select', required: true, items: o.species.filter(s => s.tracking === 'individual').map(s => ({ title: s.name, value: s.id })) },
  { key: 'tag_number', label: 'Tag / brand number', required: true, cols: 4 },
  { key: 'electronic_id', label: 'RFID', cols: 4 },
  { key: 'name', label: 'Name', cols: 4 },
  { key: 'breed_id', label: 'Breed', type: 'select', items: (f: any) => o.breeds.filter(b => b.species_id === f.species_id).map(b => ({ title: b.name, value: b.id })) },
  { key: 'sex', label: 'Sex', type: 'select', required: true, items: o.sexes },
  { key: 'group_id', label: 'Herd / group', type: 'select', items: (f: any) => o.groups.filter(g => g.farm_id === f.farm_id && g.species_id === f.species_id).map(g => ({ title: `${g.code} · ${g.name}`, value: g.id })) },
  { key: 'field_id', label: 'Camp', type: 'select', items: (f: any) => camps(o, f.farm_id) },
  { key: 'acquisition', label: 'Came from', type: 'select', required: true, items: o.acquisitions, default: 'born', cols: 4 },
  { key: 'source', label: 'Source (breeder, auction, supplier)', cols: 8, show: (f: any) => f.acquisition !== 'born' },
  { key: 'birth_date_estimated', label: 'Birth date is an estimate', type: 'switch', cols: 4 },
  { key: 'ownership', label: 'Ownership', type: 'select', items: (o.ownership ?? ['owned']).map(x => ({ title: x[0].toUpperCase() + x.slice(1), value: x })), default: 'owned', cols: 4 },
  { key: 'owner_name', label: 'Owner', cols: 8, show: (f: any) => f.ownership && f.ownership !== 'owned' },
  { key: 'birth_date', label: 'Birth date', type: 'date', cols: 4 },
  { key: 'acquired_on', label: 'Acquired on', type: 'date', cols: 4, show: (f: any) => f.acquisition !== 'born' },
  { key: 'acquisition_cost', label: 'Purchase price', type: 'number', cols: 4, show: (f: any) => f.acquisition === 'purchased' },
  { key: 'dam_tag', label: 'Dam tag', cols: 4 },
  { key: 'sire_tag', label: 'Sire tag', cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const healthFields = (o: LivestockOptions, fixed: { farm_id?: string; animal_id?: string; group_id?: string } = {}): FormField[] => [
  ...(fixed.farm_id ? [] : [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: activeFarms(o) }]),
  ...(fixed.animal_id || fixed.group_id ? [] : [
    { key: 'group_id', label: 'Herd / flock', type: 'select' as const, items: (f: any) => o.groups.filter(g => g.farm_id === f.farm_id).map(g => ({ title: `${g.code} · ${g.name}`, value: g.id })) },
    { key: 'animal_id', label: '…or one animal', type: 'autocomplete' as const, items: (f: any) => o.animals.filter(a => a.farm_id === f.farm_id).map(a => ({ title: a.tag_number, value: a.id })) },
  ]),
  { key: 'event_type', label: 'Type', type: 'select', required: true, items: o.health_types, cols: 4 },
  { key: 'treated_on', label: 'Date', type: 'date', required: true, cols: 4 },
  { key: 'head_count', label: 'Head treated', type: 'number', cols: 4, show: () => !fixed.animal_id },
  { key: 'condition', label: 'Condition / reason', cols: 6 },
  { key: 'vet_name', label: 'Vet', cols: 6 },
  { key: 'stock_item_id', label: 'Product from the farm store', type: 'autocomplete', items: o.stock_items.map(i => ({ title: i.name, value: i.id })), cols: 6 },
  { key: 'quantity', label: 'Quantity used', type: 'number', cols: 3, show: (f: any) => !!f.stock_item_id },
  { key: 'product_name', label: 'Product (if not from the store)', cols: 6, show: (f: any) => !f.stock_item_id },
  { key: 'dose', label: 'Dose', cols: 3 },
  { key: 'withdrawal_days', label: 'Withdrawal period (days)', type: 'number', cols: 4, hint: 'No sale or slaughter until it ends' },
  { key: 'programme_id', label: 'Under vaccination programme', type: 'select', items: (o.programmes ?? []).map(p => ({ title: p.name, value: p.id })), cols: 8, show: (f: any) => f.event_type === 'vaccination' },
  { key: 'requires_clearance', label: 'Sale waits for a verified release (residue test, vet sign-off)', type: 'switch', cols: 12 },
  { key: 'notifiable', label: 'Notifiable / controlled disease', type: 'switch', cols: 6, show: (f: any) => ['diagnosis', 'vet_visit', 'treatment'].includes(f.event_type) },
  { key: 'quarantine_days', label: 'Quarantine (days)', type: 'number', cols: 6, hint: 'No movement off the farm or sale until it ends' },
  { key: 'next_due_on', label: 'Next due (booster)', type: 'date', cols: 4 },
  { key: 'cost', label: 'Cost (if not from the store)', type: 'number', cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const exitFields = (o: LivestockOptions): FormField[] => [
  { key: 'status', label: 'How it left', type: 'select', required: true, items: o.exits },
  { key: 'exited_on', label: 'Date', type: 'date', required: true },
  { key: 'value', label: 'Value / sale price', type: 'number', show: (f: any) => ['sold', 'slaughtered', 'culled'].includes(f.status) },
  { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }], default: 'receivable', show: (f: any) => f.status === 'sold' },
  { key: 'exit_cause', label: 'Cause of death', show: (f: any) => f.status === 'died', cols: 12, hint: 'Write "unknown" if it is not known' },
  { key: 'reason', label: 'Reason / buyer', type: 'textarea', cols: 12 },
]

export const countFields = (o: LivestockOptions): FormField[] => [
  { key: 'reason', label: 'Reason', type: 'select', required: true, items: o.count_reasons },
  { key: 'delta', label: 'Change (+ or −)', type: 'number', required: true, hint: 'e.g. -12 for 12 deaths, 500 for a delivery' },
  { key: 'changed_on', label: 'Date', type: 'date', required: true },
  { key: 'value', label: 'Value (sales)', type: 'number', show: (f: any) => f.reason === 'sale' },
  { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }], default: 'receivable', show: (f: any) => f.reason === 'sale' },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const weightFields = (fixed: { animal: boolean }): FormField[] => [
  { key: 'weighed_on', label: 'Date', type: 'date', required: true, cols: 4 },
  { key: 'weight_kg', label: fixed.animal ? 'Weight (kg)' : 'Average weight (kg)', type: 'number', required: true, cols: 4 },
  ...(fixed.animal ? [{ key: 'condition_score', label: 'Condition score (1–9)', type: 'number' as const, cols: 4 }] : [{ key: 'head_count', label: 'Head weighed', type: 'number' as const, cols: 4 }]),
]

export const feedFields = (o: LivestockOptions, fixed: { farm_id?: string; group_id?: string }): FormField[] => [
  ...(fixed.farm_id ? [] : [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: activeFarms(o) }]),
  ...(fixed.group_id ? [] : [{ key: 'group_id', label: 'Group', type: 'select' as const, items: (f: any) => o.groups.filter(g => g.farm_id === (fixed.farm_id ?? f.farm_id)).map(g => ({ title: `${g.code} · ${g.name}`, value: g.id })) }]),
  { key: 'ration_id', label: 'Ration', type: 'select', items: (o.rations ?? []).map(r => ({ title: r.name, value: r.id })) },
  { key: 'stock_item_id', label: 'Or a single feed', type: 'autocomplete', items: o.stock_items.map(i => ({ title: i.name, value: i.id })), show: (f: any) => !f.ration_id },
  { key: 'fed_on', label: 'Fed on', type: 'date', required: true }, { key: 'quantity_kg', label: 'Kilograms', type: 'number', required: true },
  { key: 'head_count', label: 'Head fed', type: 'number' },
]

export const produceFields = (o: LivestockOptions, fixed: { farm_id?: string; group_id?: string; animal_id?: string }): FormField[] => [
  ...(fixed.farm_id ? [] : [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: activeFarms(o) }]),
  ...(fixed.group_id || fixed.animal_id ? [] : [{ key: 'group_id', label: 'Group', type: 'select' as const, items: (f: any) => o.groups.filter(g => g.farm_id === (fixed.farm_id ?? f.farm_id)).map(g => ({ title: `${g.code} · ${g.name}`, value: g.id })) }]),
  { key: 'product', label: 'Product', type: 'select', required: true, items: [{ title: 'Milk', value: 'milk' }, { title: 'Eggs', value: 'eggs' }, { title: 'Wool', value: 'wool' }, { title: 'Other', value: 'other' }] },
  { key: 'recorded_on', label: 'Date', type: 'date', required: true },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 }, { key: 'unit', label: 'Unit', required: true, cols: 4, hint: 'l, dozen, kg' }, { key: 'grade', label: 'Grade', cols: 4 },
]
