import type { FormField } from '../Components/FormDialog.vue'

export type LivestockOptions = {
  farms: Array<{ id: string; name: string; status: string; fields: Array<{ id: string; code: string; name: string }> }>
  species: Array<{ id: number; name: string; tracking: string }>
  breeds: Array<{ id: number; species_id: number; name: string }>
  groups: Array<{ id: string; code: string; name: string; farm_id: string; species_id: number }>
  animals: Array<{ id: string; tag_number: string; farm_id: string; species_id: number; sex: string }>
  stock_items: Array<{ id: number; name: string; uom: string }>
  purposes: string[]; sexes: string[]; acquisitions: string[]; exits: string[]; health_types: string[]; event_types: string[]; count_reasons: string[]
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
  { key: 'next_due_on', label: 'Next due (booster)', type: 'date', cols: 4 },
  { key: 'cost', label: 'Cost (if not from the store)', type: 'number', cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const exitFields = (o: LivestockOptions): FormField[] => [
  { key: 'status', label: 'How it left', type: 'select', required: true, items: o.exits },
  { key: 'exited_on', label: 'Date', type: 'date', required: true },
  { key: 'value', label: 'Value / sale price', type: 'number', show: (f: any) => ['sold', 'slaughtered', 'culled'].includes(f.status) },
  { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }], default: 'receivable', show: (f: any) => f.status === 'sold' },
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
