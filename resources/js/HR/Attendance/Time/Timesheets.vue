<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog from '@/Modules/Agri/Components/FormDialog.vue'
import FarmFilter from '@/Modules/Agri/Components/FarmFilter.vue'
import RowMenu from '@/Modules/Agri/Components/RowMenu.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, money, statusColor, today } from '@/Modules/Agri/Components/agri'

const props = defineProps<{
  timesheets: any[]; corrections: any[]; overtime: any[]
  summary: null | { planned_hours: number; actual_hours: number; overtime_hours: number; late_count: number; missing_out: number; shifts_planned: number; shifts_worked: number }
  filters: { farm: string | null; from: string; to: string; status: string }
  options: { farms: Array<{ id: string; name: string }>; workers: Array<{ id: number; name: string }> }
  can: { approve: boolean; record: boolean }
}>()
const keep = { preserveScroll: true }
const from = ref(props.filters.from)
const to = ref(props.filters.to)
const reload = (extra: Record<string, any> = {}) => router.get(route('attendance.timesheets.index'), { farm: props.filters.farm || undefined, from: from.value, to: to.value, status: props.filters.status, ...extra }, { preserveState: false })
const FLAG: Record<string, string> = { late: 'Late', early: 'Left early', missing_out: 'No clock-out', out_without_in: 'No clock-in', unplanned_overtime: 'Unplanned overtime',
  over_max_shift: 'Over max shift', offline: 'Captured offline', unverified: 'Needs a look', needs_confirmation: 'Needs supervisor', no_shift: 'No shift' }
const flagColor = (f: string) => ['no_shift', 'offline'].includes(f) ? 'secondary' : ['unverified', 'missing_out', 'out_without_in', 'needs_confirmation'].includes(f) ? 'error' : 'warning'
const selected = ref<number[]>([])
const clean = computed(() => props.timesheets.filter(t => t.status === 'complete' && !(t.flags ?? []).some((f: string) => f !== 'no_shift')))
const decide = (t: any, action: string) => {
  const reason = action === 'reject' ? prompt('Why is this time rejected?') : null
  if (action === 'reject' && !reason) return
  router.post(route('attendance.timesheets.decide', t.uuid), { action, reason }, keep)
}
const approveMany = () => router.post(route('attendance.timesheets.approve-many'), { ids: selected.value.length ? selected.value : clean.value.map(t => t.id) }, { ...keep, onSuccess: () => { selected.value = [] } })
const reviewCorrection = (c: any, approve: boolean) => router.post(route('attendance.timesheets.corrections.review', c.uuid), { approve }, keep)
const reviewOvertime = (o: any, approve: boolean) => router.post(route('attendance.timesheets.overtime.review', o.uuid), { approve }, keep)
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const events = ref<any[]>([])
async function showEvents(t: any) { target.value = t; dialog.value = 'events'; events.value = (await axios.get(route('attendance.timesheets.events', t.uuid))).data }
const open = (k: string, t: any = null) => { target.value = t; dialog.value = k }
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Attendance</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Clock events become timesheets measured against the roster. Corrections keep the original; approval makes time payable and costed.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" organisation />
        <VTextField v-model="from" type="date" label="From" density="compact" hide-details style="max-width: 160px" />
        <VTextField v-model="to" type="date" label="To" density="compact" hide-details style="max-width: 160px" />
        <VBtn variant="tonal" @click="reload()">Apply</VBtn>
        <Link v-if="can.record" :href="route('attendance.badge-kiosk', { farm: filters.farm || undefined })"><VBtn color="primary" prepend-icon="tabler-device-tablet">Kiosk</VBtn></Link>
      </div>
    </div>
    <WorkforceTabs current="attendance" />

    <VRow v-if="summary" class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Planned / worked</div><div class="text-h5">{{ summary.planned_hours }} / {{ summary.actual_hours }} h</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Overtime</div><div class="text-h5">{{ summary.overtime_hours }} h</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Late arrivals</div><div class="text-h5">{{ summary.late_count }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Missing clock-outs</div><div class="text-h5" :class="summary.missing_out ? 'text-error' : ''">{{ summary.missing_out }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="corrections.length || overtime.length" class="mb-4" title="Waiting for you">
      <VList density="compact">
        <VListItem v-for="c in corrections" :key="'c' + c.id" :title="`${c.worker} · ${c.date}: ${c.field === 'clock_in' ? 'clock-in' : 'clock-out'} ${c.original_at ?? 'none'} → ${c.corrected_at}`" :subtitle="c.reason">
          <template #append><template v-if="can.approve && !c.mine"><VBtn size="small" color="success" variant="tonal" class="me-1" @click="reviewCorrection(c, true)">Approve</VBtn><VBtn size="small" variant="text" @click="reviewCorrection(c, false)">Reject</VBtn></template>
            <VChip v-else size="x-small">{{ c.mine ? 'Yours — somebody else approves' : 'Pending' }}</VChip></template>
        </VListItem>
        <VListItem v-for="o in overtime" :key="'o' + o.id" :title="`Overtime: ${o.worker} · ${o.date} · ${o.hours} h${o.emergency ? ' (emergency)' : ''}`" :subtitle="o.reason">
          <template #append><template v-if="can.approve"><VBtn size="small" color="success" variant="tonal" class="me-1" @click="reviewOvertime(o, true)">Approve</VBtn><VBtn size="small" variant="text" @click="reviewOvertime(o, false)">Reject</VBtn></template></template>
        </VListItem>
      </VList>
    </VCard>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-2 align-center">
        <VChip v-for="s in ['all', 'flagged', 'open', 'complete', 'approved', 'rejected']" :key="s" size="small" :variant="filters.status === s ? 'flat' : 'tonal'" :color="filters.status === s ? 'primary' : undefined" @click="reload({ status: s })">{{ human(s) }}</VChip>
        <VSpacer />
        <VBtn v-if="can.record" size="small" variant="text" prepend-icon="tabler-clock-plus" @click="open('overtime')">Request overtime</VBtn>
        <VBtn v-if="can.approve" size="small" color="success" variant="tonal" :disabled="!clean.length && !selected.length" @click="approveMany">Approve {{ selected.length || clean.length }} clean</VBtn>
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th /><th>Date</th><th>Worker</th><th>Shift</th><th>In / out</th><th class="text-end">Hours</th><th class="text-end">OT</th><th>Flags</th><th>Status</th><th class="text-end">Cost</th><th /></tr></thead>
        <tbody>
          <tr v-for="t in timesheets" :key="t.id">
            <td><VCheckbox v-if="t.status === 'complete'" v-model="selected" :value="t.id" hide-details density="compact" /></td>
            <td>{{ t.date }}</td><td><strong>{{ t.worker }}</strong><div class="text-caption">{{ t.farm ?? 'Organisation' }}</div></td>
            <td class="text-caption">{{ t.scheduled ?? '—' }}</td>
            <td>{{ t.in ?? '—' }} → {{ t.out ?? '—' }}<div v-if="t.by_date && Object.keys(t.by_date).length > 1" class="text-caption">{{ Object.entries(t.by_date).map(([d, m]) => `${d.slice(5)}: ${Math.round((m as number) / 6) / 10} h`).join(' · ') }}</div></td>
            <td class="text-end">{{ t.hours }}</td><td class="text-end">{{ t.overtime || '' }}</td>
            <td><VChip v-for="f in t.flags" :key="f" size="x-small" :color="flagColor(f)" variant="tonal" class="me-1 mb-1">{{ FLAG[f] ?? f }}{{ f === 'late' ? ` ${t.late} min` : '' }}</VChip></td>
            <td><VChip :color="statusColor(t.status)" size="small" variant="tonal">{{ human(t.status) }}</VChip><VIcon v-if="t.sent" icon="tabler-send" size="14" class="ms-1" title="Sent to payroll" /></td>
            <td class="text-end">{{ t.cost !== null ? money(t.cost) : '' }}</td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Clock events (evidence)', icon: 'tabler-list-details', onClick: () => showEvents(t) },
              { title: 'Confirm (supervisor)', icon: 'tabler-user-check', show: can.record && (t.flags ?? []).includes('needs_confirmation'), onClick: () => decide(t, 'confirm') },
              { title: 'Approve', icon: 'tabler-check', show: can.approve && t.status === 'complete', onClick: () => decide(t, 'approve') },
              { title: 'Correct clock-in or out', icon: 'tabler-edit', show: (can.record || can.approve) && !t.sent, onClick: () => open('correct', t) },
              { title: 'Reject', icon: 'tabler-x', color: 'error', show: can.approve && !t.sent && t.status !== 'rejected', onClick: () => decide(t, 'reject') },
            ]" /></td>
          </tr>
          <tr v-if="!timesheets.length"><td colspan="11" class="text-center text-medium-emphasis py-6">No time recorded in this period.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog v-if="target" :model-value="dialog === 'correct'" :title="`Correct ${target.worker} · ${target.date}`" :url="route('attendance.timesheets.corrections.store', target.uuid)"
      :values="{ field: target.out ? 'clock_in' : 'clock_out' }" intro="The original stays on record. Somebody other than you approves the correction." :fields="[
        { key: 'field', label: 'Which', type: 'select', required: true, items: [{ title: 'Clock-in', value: 'clock_in' }, { title: 'Clock-out', value: 'clock_out' }] },
        { key: 'corrected_at', label: 'Correct time', type: 'datetime', required: true },
        { key: 'reason', label: 'Reason', type: 'textarea', required: true, cols: 12 },
      ]" @update:model-value="v => !v && (dialog = null)" />
    <FormDialog :model-value="dialog === 'overtime'" title="Request overtime" :url="route('attendance.timesheets.overtime.store')" :values="{ work_date: today() }" :fields="[
      { key: 'worker_id', label: 'Worker', type: 'autocomplete', required: true, items: options.workers.map(w => ({ title: w.name, value: w.id })), cols: 12 },
      { key: 'work_date', label: 'Date', type: 'date', required: true }, { key: 'hours', label: 'Hours', type: 'number', required: true },
      { key: 'reason', label: 'Reason', required: true, cols: 12 }, { key: 'emergency', label: 'Emergency (approved at once if you may approve)', type: 'switch', cols: 12 },
    ]" @update:model-value="v => !v && (dialog = null)" />
    <VDialog :model-value="dialog === 'events'" max-width="620" @update:model-value="v => !v && (dialog = null)">
      <VCard v-if="target" :title="`Clock events — ${target.worker}`">
        <VTable density="compact"><thead><tr><th>Time</th><th>Event</th><th>How</th><th>Check</th></tr></thead>
          <tbody><tr v-for="e in events" :key="e.id" :class="e.superseded ? 'text-disabled' : ''">
            <td :class="e.superseded ? 'text-decoration-line-through' : ''">{{ e.at }}</td><td>{{ e.kind }}</td><td>{{ e.method }}<span v-if="e.offline"> · offline</span><span v-if="e.confirmed"> · confirmed</span></td>
            <td><VChip size="x-small" :color="e.verification === 'verified' ? 'success' : 'warning'" variant="tonal">{{ e.verification }}</VChip><div v-if="e.note" class="text-caption">{{ e.note }}</div><div v-if="e.superseded" class="text-caption">Superseded by a correction</div></td>
          </tr></tbody></VTable>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = null">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
