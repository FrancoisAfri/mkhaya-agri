import type { FormField } from '../Components/FormDialog.vue'

/** Form definitions shared by the crop register and the crop plan page. */
export type CropOptions = {
  farms: Array<{ id: string; name: string; status: string; fields: Array<{ id: string; code: string; name: string; hectares: number | null }> }>
  seasons: Array<{ id: number; name: string; status: string }>
  crop_types: Array<{ id: number; name: string; yield_unit: string }>
  varieties: Array<{ id: number; crop_type_id: number; name: string }>
  plans: Array<{ id: string; code: string; farm_id: string; field_id: string }>
  groups: Array<{ id: string; code: string; name: string; farm_id: string }>
  machines: Array<{ id: string; code: string; name: string; farm_id: string }>
  stock_items: Array<{ id: number; name: string; uom: string }>
  people: Array<{ id: number; name: string }>
  activity_types: string[]; kinds: string[]; severities: string[]; destinations: string[]; yield_units: string[]; area_units: string[]
}

const fieldsOf = (o: CropOptions, farmId: string) =>
  (o.farms.find(f => f.id === farmId)?.fields ?? []).map(f => ({ title: `${f.code} · ${f.name}${f.hectares ? ` (${f.hectares} ha)` : ''}`, value: f.id }))

export const planFields = (o: CropOptions, withFarm = true): FormField[] => [
  ...(withFarm ? [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: o.farms.filter(f => f.status === 'active').map(f => ({ title: f.name, value: f.id })) }] : []),
  { key: 'field_id', label: 'Field', type: 'select', required: true, items: (f: any) => fieldsOf(o, f.farm_id) },
  { key: 'code', label: 'Plan code', required: true, hint: 'e.g. MZ-25-F1' },
  { key: 'season_id', label: 'Season', type: 'select', required: true, items: o.seasons.filter(s => s.status === 'open').map(s => ({ title: s.name, value: s.id })) },
  { key: 'crop_type_id', label: 'Crop', type: 'autocomplete', required: true, items: o.crop_types.map(c => ({ title: c.name, value: c.id })) },
  { key: 'variety_id', label: 'Variety / cultivar', type: 'select', items: (f: any) => o.varieties.filter(v => v.crop_type_id === f.crop_type_id).map(v => ({ title: v.name, value: v.id })) },
  { key: 'planned_area', label: 'Planned area', type: 'number', required: true, cols: 4 },
  { key: 'area_unit', label: 'Unit', type: 'select', items: o.area_units, default: 'ha', cols: 2 },
  { key: 'planned_planting_on', label: 'Planned planting', type: 'date', required: true, cols: 3 },
  { key: 'planned_harvest_on', label: 'Planned harvest', type: 'date', required: true, cols: 3 },
  { key: 'expected_yield', label: 'Expected yield per ha', type: 'number', cols: 4 },
  { key: 'yield_unit', label: 'Yield unit', type: 'select', items: o.yield_units, default: 't', cols: 2 },
  { key: 'expected_price', label: 'Expected price per unit', type: 'number', cols: 3 },
  { key: 'budget_amount', label: 'Budget (total)', type: 'number', cols: 3 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const activityFields = (o: CropOptions, fixed: { farm_id?: string; crop_plan_id?: string } = {}): FormField[] => [
  ...(fixed.farm_id ? [] : [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: o.farms.filter(f => f.status === 'active').map(f => ({ title: f.name, value: f.id })) }]),
  { key: 'activity_type', label: 'Activity', type: 'select', required: true, items: o.activity_types },
  { key: 'title', label: 'Description', required: true },
  ...(fixed.crop_plan_id ? [] : [
    { key: 'crop_plan_id', label: 'Crop plan', type: 'select' as const, items: (f: any) => o.plans.filter(p => p.farm_id === f.farm_id).map(p => ({ title: p.code, value: p.id })), hint: 'Leave blank for farm or livestock work' },
    { key: 'field_id', label: 'Field', type: 'select' as const, items: (f: any) => fieldsOf(o, f.farm_id), show: (f: any) => !f.crop_plan_id },
    { key: 'livestock_group_id', label: 'Livestock group', type: 'select' as const, items: (f: any) => o.groups.filter(g => g.farm_id === f.farm_id).map(g => ({ title: `${g.code} · ${g.name}`, value: g.id })), show: (f: any) => !f.crop_plan_id },
  ]),
  { key: 'planned_on', label: 'Planned date', type: 'date', required: true, cols: 4 },
  { key: 'assigned_to', label: 'Assigned to', type: 'autocomplete', items: o.people.map(p => ({ title: p.name, value: p.id })), cols: 4 },
  { key: 'machine_id', label: 'Machine', type: 'select', items: (f: any) => o.machines.filter(m => m.farm_id === (fixed.farm_id ?? f.farm_id)).map(m => ({ title: `${m.code} ${m.name}`, value: m.id })), cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const inputFields = (o: CropOptions): FormField[] => [
  { key: 'stock_item_id', label: 'Item from the farm store', type: 'autocomplete', required: true, items: o.stock_items.map(i => ({ title: i.name, value: i.id })), cols: 12 },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 },
  { key: 'rate_per_ha', label: 'Rate per ha', type: 'number', cols: 4 },
  { key: 'withholding_days', label: 'Withholding period (days)', type: 'number', cols: 4, hint: 'Chemicals: days before harvest is allowed' },
]

export const completeFields: FormField[] = [
  { key: 'completed_at', label: 'Completed on', type: 'date', cols: 4 },
  { key: 'area_covered', label: 'Area covered (ha)', type: 'number', cols: 4 },
  { key: 'labour_hours', label: 'Labour hours', type: 'number', cols: 4 },
  { key: 'labour_cost', label: 'Labour cost', type: 'number', cols: 4 },
  { key: 'machine_hours', label: 'Machine hours', type: 'number', cols: 4 },
  { key: 'weather', label: 'Weather', cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]

export const observationFields = (o: CropOptions, fixed: { farm_id?: string; crop_plan_id?: string } = {}): FormField[] => [
  ...(fixed.farm_id ? [] : [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: o.farms.filter(f => f.status === 'active').map(f => ({ title: f.name, value: f.id })) }]),
  ...(fixed.crop_plan_id ? [] : [
    { key: 'crop_plan_id', label: 'Crop plan', type: 'select' as const, items: (f: any) => o.plans.filter(p => p.farm_id === f.farm_id).map(p => ({ title: p.code, value: p.id })) },
    { key: 'field_id', label: 'Field', type: 'select' as const, items: (f: any) => fieldsOf(o, f.farm_id), show: (f: any) => !f.crop_plan_id },
  ]),
  { key: 'observed_on', label: 'Observed on', type: 'date', required: true, cols: 4 },
  { key: 'kind', label: 'What was seen', type: 'select', required: true, items: o.kinds, cols: 4 },
  { key: 'severity', label: 'Severity', type: 'select', required: true, items: o.severities, default: 'low', cols: 4 },
  { key: 'growth_stage', label: 'Growth stage', cols: 6, hint: 'e.g. V6, tasseling, flowering' },
  { key: 'action_required', label: 'Action required', type: 'switch', cols: 6 },
  { key: 'description', label: 'Description', type: 'textarea', required: true, cols: 12 },
]

export const harvestFields = (o: CropOptions, yieldUnit: string): FormField[] => [
  { key: 'harvested_on', label: 'Harvested on', type: 'date', required: true, cols: 4 },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 },
  { key: 'unit', label: 'Unit', type: 'select', items: o.yield_units, default: yieldUnit, cols: 4 },
  { key: 'grade', label: 'Grade', cols: 4 },
  { key: 'moisture_pct', label: 'Moisture %', type: 'number', cols: 4 },
  { key: 'area_harvested', label: 'Area harvested (ha)', type: 'number', cols: 4 },
  { key: 'destination', label: 'Goes to', type: 'select', required: true, items: o.destinations, default: 'store', cols: 6 },
  { key: 'stock_item_id', label: 'Stored as stock item', type: 'autocomplete', items: o.stock_items.map(i => ({ title: i.name, value: i.id })), cols: 6, show: (f: any) => f.destination === 'store' },
  { key: 'unit_value', label: 'Value per unit', type: 'number', cols: 6, hint: 'Fair value at harvest, or the sale price', show: (f: any) => ['store', 'sold'].includes(f.destination) },
  { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }], default: 'receivable', cols: 6, show: (f: any) => f.destination === 'sold' },
  { key: 'override_reason', label: 'Withholding override reason', type: 'textarea', cols: 12, hint: 'Only if harvesting inside a withholding period, and only by someone who approves crop work' },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]
