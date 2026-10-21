import type { FormField } from '../Components/FormDialog.vue'

/** The work-order form, shared by the list (create) and the detail page (edit). */
export const workOrderFields = (o: any, withFarm = true, farmId?: string): FormField[] => {
  const farm = (f: any) => farmId ?? f.farm_id
  return [
  ...(withFarm ? [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: o.farms.map((f: any) => ({ title: f.name, value: f.id })) }] : []),
  { key: 'category', label: 'Kind of job', type: 'select', required: true, items: o.categories },
  { key: 'title', label: 'Job', required: true, cols: 12 },
  { key: 'priority', label: 'Priority', type: 'select', required: true, items: ['low', 'normal', 'high', 'urgent'], default: 'normal' },
  { key: 'crop_plan_id', label: 'Crop plan', type: 'select', items: (f: any) => o.plans.filter((p: any) => p.farm_id === farm(f)).map((p: any) => ({ title: p.code, value: p.id })) },
  { key: 'livestock_group_id', label: 'Herd / flock', type: 'select', items: (f: any) => o.groups.filter((g: any) => g.farm_id === farm(f)).map((g: any) => ({ title: `${g.code} · ${g.name}`, value: g.id })) },
  { key: 'field_id', label: 'Field / camp', type: 'select', items: (f: any) => (o.farms.find((x: any) => x.id === farm(f))?.fields ?? []).map((x: any) => ({ title: `${x.code} · ${x.name}`, value: x.id })), show: (f: any) => !f.crop_plan_id },
  { key: 'planned_start', label: 'Start', type: 'datetime', required: true }, { key: 'planned_end', label: 'Finish', type: 'datetime', required: true },
  { key: 'estimated_hours', label: 'Estimated labour hours', type: 'number', cols: 4 },
  { key: 'expected_output', label: 'Expected output', type: 'number', cols: 4 }, { key: 'output_unit', label: 'Unit', cols: 4, hint: 'ha, kg, rows…' },
  { key: 'budget_amount', label: 'Budget', type: 'number' },
  { key: 'required_certifications', label: 'Required competencies', type: 'multiselect', items: o.competencies },
  { key: 'instructions', label: 'Instructions', type: 'textarea', cols: 12 },
]
}
