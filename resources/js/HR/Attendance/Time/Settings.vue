<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, money, statusColor, today } from '@/Modules/Agri/Components/agri'

const props = defineProps<{
  rule_sets: any[]; templates: any[]; rates: any[]
  devices: Array<{ uuid: string; name: string; kind: string; farm: string; is_active: boolean; last_seen: string | null; events: number; endpoint: string }>
  options: { farms: Array<{ id: string; name: string }>; kinds: string[]; activities: string[]; units: string[]; categories: string[]; crop_types: Array<{ id: number; name: string }>; competencies: any[] }
  can: { manage: boolean }
}>()
const tab = ref('rules')
const dialog = ref<null | { kind: string; row: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const hm = (m: number) => `${Math.floor(m / 60)} h${m % 60 ? ' ' + (m % 60) + ' min' : ''}`

const ruleFields: FormField[] = [
  { key: 'name', label: 'Name', required: true, cols: 8 }, { key: 'is_default', label: 'Default', type: 'switch', cols: 4 },
  { key: 'applies_to', label: 'For categories', type: 'multiselect', items: props.options.categories, cols: 12, hint: 'Blank = everybody not covered by another set' },
  { key: 'daily_ot_after_minutes', label: 'Overtime after (min/day)', type: 'number', required: true, cols: 4 },
  { key: 'weekly_ot_after_minutes', label: 'Overtime after (min/week)', type: 'number', required: true, cols: 4 },
  { key: 'late_grace_minutes', label: 'Late after (grace, min)', type: 'number', required: true, cols: 4 },
  { key: 'ot_multiplier', label: 'Overtime ×', type: 'number', required: true, cols: 4 },
  { key: 'sunday_multiplier', label: 'Sunday ×', type: 'number', required: true, cols: 4 },
  { key: 'holiday_multiplier', label: 'Public holiday ×', type: 'number', required: true, cols: 4 },
  { key: 'night_starts', label: 'Night from', required: true, cols: 3 }, { key: 'night_ends', label: 'Night to', required: true, cols: 3 },
  { key: 'night_allowance_per_hour', label: 'Night allowance / h', type: 'number', cols: 6 },
  { key: 'min_rest_minutes', label: 'Rest between shifts (min)', type: 'number', required: true, cols: 4 },
  { key: 'max_shift_minutes', label: 'Maximum shift (min)', type: 'number', required: true, cols: 4 },
  { key: 'fatigue_warn_minutes', label: 'Fatigue warning (min)', type: 'number', required: true, cols: 4 },
  { key: 'overlap_policy', label: 'Overlapping shifts / leave', type: 'select', required: true, items: ['warn', 'block'], cols: 6 },
  { key: 'certification_policy', label: 'Missing certification', type: 'select', required: true, items: ['warn', 'block'], cols: 6 },
  { key: 'reviewed', label: 'I have checked these against the BCEA, the sectoral determination, any collective agreement and contracts', type: 'switch', cols: 12 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]
const templateFields: FormField[] = [
  { key: 'code', label: 'Code', required: true, cols: 3 }, { key: 'name', label: 'Name', required: true, cols: 5 },
  { key: 'kind', label: 'Kind', type: 'select', required: true, items: props.options.kinds, cols: 4 },
  { key: 'starts', label: 'Starts (HH:MM)', required: true, cols: 3 }, { key: 'ends', label: 'Ends (HH:MM)', required: true, cols: 3, hint: 'Earlier than start = next day' },
  { key: 'break_minutes', label: 'Break (min)', type: 'number', required: true, cols: 3 }, { key: 'paid_break', label: 'Paid break', type: 'switch', cols: 3 },
  { key: 'min_staff', label: 'Minimum staff', type: 'number', required: true, cols: 4 },
  { key: 'required_certifications', label: 'Required competencies', type: 'multiselect', items: props.options.competencies, cols: 8 },
  { key: 'is_active', label: 'In use', type: 'switch', default: true, cols: 12 },
]
const rateFields: FormField[] = [
  { key: 'activity', label: 'Activity', type: 'select', required: true, items: props.options.activities },
  { key: 'unit', label: 'Per', type: 'select', required: true, items: props.options.units },
  { key: 'rate', label: 'Rate', type: 'number', required: true }, { key: 'daily_target', label: 'Daily target (units)', type: 'number' },
  { key: 'farm_id', label: 'Farm (blank = all)', type: 'select', items: props.options.farms.map(f => ({ title: f.name, value: f.id })) },
  { key: 'crop_type_id', label: 'Crop (blank = any)', type: 'select', items: props.options.crop_types.map(c => ({ title: c.name, value: c.id })) },
  { key: 'valid_from', label: 'Valid from', type: 'date', required: true, default: today() }, { key: 'valid_to', label: 'Valid to', type: 'date' },
]
const urlFor = (d: { kind: string; row: any }) => ({
  rules: d.row ? route('attendance.time-settings.rules.update', d.row.uuid) : route('attendance.time-settings.rules.store'),
  template: d.row ? route('attendance.time-settings.templates.update', d.row.uuid) : route('attendance.time-settings.templates.store'),
  rate: route('attendance.time-settings.rates.store'),
} as Record<string, string>)[d.kind]
// ── Clock devices: the secret comes back once, straight to this screen, and is never shown again.
const deviceOpen = ref(false)
const deviceForm = ref<{ farm_id: string | null; name: string; kind: string }>({ farm_id: props.options.farms[0]?.id ?? null, name: '', kind: 'biometric' })
const deviceErrors = ref<Record<string, string[]>>({})
const issued = ref<null | { name: string; secret: string; endpoint: string }>(null)
async function saveDevice() {
  deviceErrors.value = {}
  try {
    issued.value = (await axios.post(route('attendance.time-settings.devices.store'), deviceForm.value)).data
    deviceOpen.value = false
  } catch (e: any) { deviceErrors.value = e.response?.data?.errors ?? { name: ['Could not register the device.'] } }
}
async function renewDevice(d: any) {
  if (!confirm(`Issue a new secret for ${d.name}? The old one stops working at once.`)) return
  issued.value = (await axios.post(route('attendance.time-settings.devices.renew', d.uuid))).data
}
const toggleDevice = (d: any) => router.post(route('attendance.time-settings.devices.toggle', d.uuid), {}, { preserveScroll: true })
const closeIssued = () => { issued.value = null; router.reload({ only: ['devices'] }) }
const DEVICE_KINDS = [{ title: 'Biometric reader', value: 'biometric' }, { title: 'Access-control gate', value: 'access_control' }, { title: 'Kiosk / tablet', value: 'kiosk' }]
const example = (endpoint: string, secret: string) => `curl -X POST ${endpoint} \\
  -H "Authorization: Bearer ${secret}" -H "Content-Type: application/json" \\
  -d '{"events":[{"event_id":"<uuid>","employee_number":"T1234","kind":"auto","occurred_at":"2026-10-19T06:02:00+02:00"}]}'`

const approve = (r: any) => router.post(route('attendance.time-settings.rates.approve', r.uuid), {}, { preserveScroll: true })
const fieldsFor = (k: string) => ({ rules: ruleFields, template: templateFields, rate: rateFields } as Record<string, FormField[]>)[k]
const valuesFor = (d: { kind: string; row: any }) => d.row ? { ...d.row, starts: d.row.starts?.slice(0, 5), ends: d.row.ends?.slice(0, 5), night_starts: d.row.night_starts?.slice(0, 5), night_ends: d.row.night_ends?.slice(0, 5), reviewed: d.row.needs_review === false } : {}
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-4">
      <h4 class="text-h4 mb-1">Labour rules</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Overtime, Sunday, public-holiday and night rules, shift templates and piece rates. All configurable — the system assumes no law.</p>
    </div>
    <WorkforceTabs current="settings" />
    <VCard>
      <VTabs v-model="tab"><VTab value="rules">Rule sets</VTab><VTab value="templates">Shift templates</VTab><VTab value="rates">Piece rates</VTab><VTab value="devices">Clock devices</VTab></VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="rules">
          <VCardText class="d-flex justify-end"><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('rules')">Rule set</VBtn></VCardText>
          <VCard v-for="r in rule_sets" :key="r.id" variant="outlined" class="mx-4 mb-4">
            <VCardItem>
              <VCardTitle>{{ r.name }} <VChip v-if="r.is_default" size="x-small" color="primary" class="ms-1">Default</VChip></VCardTitle>
              <VCardSubtitle>{{ r.applies_to?.length ? r.applies_to.map(human).join(', ') : 'Everybody else' }}</VCardSubtitle>
              <template #append><VBtn v-if="can.manage" size="small" variant="text" prepend-icon="tabler-edit" @click="open('rules', r)">Edit</VBtn></template>
            </VCardItem>
            <VCardText>
              <VAlert v-if="r.needs_review" type="warning" variant="tonal" density="compact" class="mb-3">Not yet checked against the law, sectoral determination and agreements. Review before relying on it for pay.</VAlert>
              <div class="d-flex flex-wrap gap-4 text-body-2">
                <span>Overtime after <strong>{{ hm(r.daily_ot_after_minutes) }}</strong>/day or <strong>{{ hm(r.weekly_ot_after_minutes) }}</strong>/week at ×{{ r.ot_multiplier }}</span>
                <span>Sunday ×{{ r.sunday_multiplier }}</span><span>Public holiday ×{{ r.holiday_multiplier }}</span>
                <span>Night {{ r.night_starts?.slice(0, 5) }}–{{ r.night_ends?.slice(0, 5) }}<template v-if="Number(r.night_allowance_per_hour)"> + {{ money(Number(r.night_allowance_per_hour)) }}/h</template></span>
                <span>Rest {{ hm(r.min_rest_minutes) }}</span><span>Max shift {{ hm(r.max_shift_minutes) }}</span>
                <span>Overlaps: {{ r.overlap_policy }}</span><span>Certifications: {{ r.certification_policy }}</span>
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>
        <VWindowItem value="templates">
          <VCardText class="d-flex justify-end"><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('template')">Template</VBtn></VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Code</th><th>Shift</th><th>Hours</th><th>Break</th><th>Staff</th><th>Competencies</th><th /></tr></thead>
            <tbody>
              <tr v-for="t in templates" :key="t.id">
                <td><strong>{{ t.code }}</strong></td><td>{{ t.name }}<div class="text-caption">{{ human(t.kind) }}</div></td>
                <td>{{ t.starts?.slice(0, 5) }}–{{ t.ends?.slice(0, 5) }}<span v-if="t.ends < t.starts" class="text-caption"> (+1)</span></td>
                <td>{{ t.break_minutes }} min {{ t.paid_break ? 'paid' : 'unpaid' }}</td><td>{{ t.min_staff }}</td>
                <td><VChip v-for="c in t.required_certifications ?? []" :key="c" size="x-small" class="me-1">{{ c }}</VChip></td>
                <td class="text-end"><VBtn v-if="can.manage" icon size="small" variant="text" @click="open('template', t)"><VIcon icon="tabler-edit" /></VBtn></td>
              </tr>
              <tr v-if="!templates.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No shift templates yet — add Day, Night and so on to build rosters quickly.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
        <VWindowItem value="rates">
          <VCardText class="d-flex justify-end"><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('rate')">Piece rate</VBtn></VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Activity</th><th class="text-end">Rate</th><th>Target</th><th>Valid</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="r in rates" :key="r.id">
                <td>{{ human(r.activity) }} <span class="text-caption">per {{ r.unit }}</span></td>
                <td class="text-end">{{ money(r.rate) }}</td><td>{{ r.daily_target ?? '—' }}</td>
                <td class="text-caption">{{ r.valid_from?.slice(0, 10) }} → {{ r.valid_to?.slice(0, 10) ?? 'open' }}</td>
                <td><VChip :color="statusColor(r.status === 'draft' ? 'draft' : r.status)" size="small" variant="tonal">{{ human(r.status) }}</VChip></td>
                <td class="text-end"><VBtn v-if="can.manage && r.status === 'draft'" size="small" color="success" variant="tonal" @click="approve(r)">Approve</VBtn></td>
              </tr>
              <tr v-if="!rates.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No piece rates yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
        <VWindowItem value="devices">
          <VCardText class="d-flex justify-space-between align-center flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">Biometric readers, access-control gates and kiosks that send attendance on their own. Matching happens on the device; only the person's badge or employee number reaches Mkhaya Agri. Biometric events are refused for anyone without biometric consent.</span>
            <VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="deviceOpen = true">Device</VBtn>
          </VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Device</th><th>Site</th><th>Last heard from</th><th class="text-end">Events</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="d in devices" :key="d.uuid">
                <td><strong>{{ d.name }}</strong><div class="text-caption">{{ DEVICE_KINDS.find(k => k.value === d.kind)?.title }}</div></td>
                <td>{{ d.farm ?? 'Organisation' }}</td><td>{{ d.last_seen ?? 'Never' }}</td><td class="text-end">{{ d.events }}</td>
                <td><VChip :color="d.is_active ? 'success' : undefined" size="small" variant="tonal">{{ d.is_active ? 'Active' : 'Off' }}</VChip></td>
                <td class="text-end text-no-wrap">
                  <VBtn v-if="can.manage" size="small" variant="text" @click="renewDevice(d)">New secret</VBtn>
                  <VBtn v-if="can.manage" size="small" variant="text" :color="d.is_active ? 'error' : 'success'" @click="toggleDevice(d)">{{ d.is_active ? 'Switch off' : 'Switch on' }}</VBtn>
                </td>
              </tr>
              <tr v-if="!devices.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No devices yet. Badges, the kiosk screen and phones work without one.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>
    <VDialog v-model="deviceOpen" max-width="520">
      <VCard title="Register a clock device">
        <VCardText>
          <VSelect v-model="deviceForm.farm_id" :items="[{ title: 'Organisation staff (no site)', value: null }, ...options.farms.map(f => ({ title: f.name, value: f.id }))]" label="Site" class="mb-3" :error-messages="deviceErrors.farm_id" />
          <VTextField v-model="deviceForm.name" label="Name *" placeholder="Main gate reader" class="mb-3" :error-messages="deviceErrors.name" />
          <VSelect v-model="deviceForm.kind" :items="DEVICE_KINDS" label="Type *" :error-messages="deviceErrors.kind" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deviceOpen = false">Cancel</VBtn><VBtn color="primary" @click="saveDevice">Register</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog :model-value="!!issued" max-width="720" persistent>
      <VCard v-if="issued" :title="`Secret for ${issued.name}`">
        <VCardText>
          <VAlert type="warning" variant="tonal" class="mb-3">Copy this now and put it in the device's settings. It is shown once; if it is lost, issue a new one.</VAlert>
          <VTextField :model-value="issued.secret" label="Secret" readonly class="mb-2" />
          <VTextField :model-value="issued.endpoint" label="Endpoint" readonly class="mb-2" />
          <pre class="text-caption pa-3 rounded" style="background: rgba(var(--v-theme-on-surface), 0.05); white-space: pre-wrap">{{ example(issued.endpoint, issued.secret) }}</pre>
        </VCardText>
        <VCardActions><VSpacer /><VBtn color="primary" @click="closeIssued">I have copied it</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <FormDialog v-if="dialog" :model-value="!!dialog" :title="(dialog.row ? 'Edit ' : 'New ') + ({ rules: 'rule set', template: 'shift template', rate: 'piece rate' } as any)[dialog.kind]"
      :fields="fieldsFor(dialog.kind)" :values="valuesFor(dialog)" :url="urlFor(dialog)" :method="dialog.row ? 'patch' : 'post'"
      @update:model-value="v => !v && (dialog = null)" />
  </div>
</template>
