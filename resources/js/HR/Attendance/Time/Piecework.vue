<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog from '@/Modules/Agri/Components/FormDialog.vue'
import FarmFilter from '@/Modules/Agri/Components/FarmFilter.vue'
import RowMenu from '@/Modules/Agri/Components/RowMenu.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, money, num, statusColor, today } from '@/Modules/Agri/Components/agri'

const props = defineProps<{
  entries: any[]; summary: any[]
  filters: { farm: string | null; from: string; to: string }
  options: { farms: Array<{ id: string; name: string; fields: any[] }>; activities: string[]; units: string[]; workers: any[]; crews: any[]; plans: any[]; work_orders: any[]; rates: any[] }
  can: { approve: boolean; record: boolean }
}>()
const keep = { preserveScroll: true }
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const open = (k: string, e: any = null) => { target.value = e; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }
const act = (e: any, action: 'reject' | 'query') => {
  const note = prompt(action === 'reject' ? 'Why is this rejected? It earns nothing.' : 'What needs checking?')
  if (note) router.post(route('attendance.piecework.review', e.uuid), { action, note }, keep)
}
const rateFor = (a: string, u: string) => props.options.rates.find(r => r.activity === a && r.unit === u)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Piecework</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Production-based pay. Measured quantities are verified before they earn; rejected and reworked quantities are never paid.</p>
      </div>
      <div class="d-flex gap-2"><FarmFilter :farms="options.farms" :model-value="filters.farm" organisation />
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="open('submit')">Record production</VBtn></div>
    </div>
    <WorkforceTabs current="piecework" />

    <VCard v-if="summary.length" title="Output and cost per unit (approved)" class="mb-4">
      <VTable density="compact"><thead><tr><th>Activity</th><th class="text-end">Measured</th><th class="text-end">Approved</th><th class="text-end">Rejected</th><th class="text-end">Rework</th><th class="text-end">Earnings</th><th class="text-end">Cost / unit</th></tr></thead>
        <tbody><tr v-for="s in summary" :key="s.activity + s.unit"><td>{{ human(s.activity) }} <span class="text-caption">per {{ s.unit }}</span></td>
          <td class="text-end">{{ num(s.measured) }}</td><td class="text-end">{{ num(s.approved) }}</td><td class="text-end text-error">{{ num(s.rejected) }}</td><td class="text-end">{{ num(s.rework) }}</td>
          <td class="text-end">{{ money(s.earnings) }}</td><td class="text-end">{{ s.cost_per_unit !== null ? 'R ' + s.cost_per_unit : '—' }}</td></tr></tbody></VTable>
    </VCard>

    <VCard>
      <VTable density="comfortable">
        <thead><tr><th>Date</th><th>Who</th><th>Activity</th><th class="text-end">Measured</th><th class="text-end">Approved</th><th class="text-end">Rejected</th><th class="text-end">Rate</th><th class="text-end">Earnings</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td>{{ e.date }}</td>
            <td><strong>{{ e.who }}</strong><div v-if="e.shares.length" class="text-caption">{{ human(e.allocation) }}: {{ e.shares.map((s: any) => `${s.name} ${s.amount ? money(s.amount) : ''}`).join(', ') }}</div></td>
            <td>{{ human(e.activity) }} <span class="text-caption">({{ e.unit }})</span></td>
            <td class="text-end">{{ num(e.measured) }}</td><td class="text-end">{{ e.approved !== null ? num(e.approved) : '—' }}</td>
            <td class="text-end" :class="e.rejected ? 'text-error' : ''">{{ e.rejected ? num(e.rejected) : '' }}</td>
            <td class="text-end">{{ e.rate ? 'R ' + e.rate : '' }}</td><td class="text-end">{{ e.amount !== null ? money(e.amount) : '' }}</td>
            <td><VChip :color="statusColor(e.status === 'queried' ? 'warning' : e.status)" size="small" variant="tonal">{{ human(e.status) }}</VChip><div v-if="e.note" class="text-caption">{{ e.note }}</div></td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Verify & approve', icon: 'tabler-check', show: can.approve && ['submitted', 'queried'].includes(e.status), onClick: () => open('approve', e) },
              { title: 'Query', icon: 'tabler-help', show: can.approve && e.status === 'submitted', onClick: () => act(e, 'query') },
              { title: 'Reject', icon: 'tabler-x', color: 'error', show: can.approve && ['submitted', 'queried'].includes(e.status), onClick: () => act(e, 'reject') },
            ]" /></td>
          </tr>
          <tr v-if="!entries.length"><td colspan="10" class="text-center text-medium-emphasis py-6">No production recorded in this period.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog :model-value="dialog === 'submit'" title="Record production" :url="route('attendance.piecework.store')" :values="{ farm_id: filters.farm === 'organisation' ? null : (filters.farm ?? options.farms[0]?.id ?? null), work_date: today(), activity: 'harvesting', unit: 'kg', allocation: 'equal' }" :fields="[
      { key: 'farm_id', label: 'Site', type: 'select', items: [{ title: 'Organisation staff (no site)', value: null }, ...options.farms.map(f => ({ title: f.name, value: f.id }))] },
      { key: 'work_date', label: 'Date', type: 'date', required: true },
      { key: 'activity', label: 'Activity', type: 'select', required: true, items: options.activities }, { key: 'unit', label: 'Unit', type: 'select', required: true, items: options.units },
      { key: 'worker_id', label: 'Worker', type: 'autocomplete', items: options.workers.map(w => ({ title: w.name, value: w.id })), hint: 'Or a crew below' },
      { key: 'crew_id', label: 'Crew', type: 'select', items: options.crews.map(c => ({ title: c.code + ' · ' + c.name, value: c.id })), show: (f: any) => !f.worker_id },
      { key: 'allocation', label: 'Share the crew\'s earnings', type: 'select', items: [{ title: 'Equally', value: 'equal' }], show: (f: any) => !!f.crew_id },
      { key: 'measured_quantity', label: 'Quantity measured', type: 'number', required: true },
      { key: 'crop_plan_id', label: 'Crop plan', type: 'select', items: options.plans.map(p => ({ title: p.code, value: p.id })) },
      { key: 'work_order_id', label: 'Work order', type: 'select', items: options.work_orders.map(o => ({ title: o.reference + ' · ' + o.title, value: o.id })) },
    ]" intro="Paid at the approved rate once a supervisor verifies the quantity." @update:model-value="close" />
    <FormDialog v-if="target" :model-value="dialog === 'approve'" :title="`Verify ${target.who} · ${target.date}`" :url="route('attendance.piecework.review', target.uuid)"
      :values="{ action: 'approve', approved_quantity: target.measured, rework_quantity: 0 }" :intro="`Measured ${target.measured} ${target.unit}. ` + (rateFor(target.activity, target.unit) ? `Approved rate R ${rateFor(target.activity, target.unit).rate} per ${target.unit}.` : 'No approved rate yet — approval will fail until one is set.')" :fields="[
        { key: 'approved_quantity', label: 'Approved quantity', type: 'number', required: true }, { key: 'rework_quantity', label: 'To be reworked (unpaid)', type: 'number' },
        { key: 'note', label: 'Note', cols: 12 },
      ]" submit-label="Approve" @update:model-value="close" />
  </div>
</template>
