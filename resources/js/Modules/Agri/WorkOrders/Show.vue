<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, statusColor } from '../Components/agri'
import { workOrderFields } from './fields'

const props = defineProps<{
  order: any; workers: any[]; materials: any[]; machines: any[]; logs: any[]; history: any[]
  labour: { timesheets: number; time_cost: number; piecework: number }
  options: any
  can: { manage: boolean; approve: boolean; verify: boolean; work: boolean }
  safety: Array<{ code: string; title: string; reference: string | null; passed: boolean | null; url: string }>
}>()
const keep = { preserveScroll: true }
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const s = computed(() => props.order.status)
const canWork = computed(() => props.can.manage || props.can.work)
function go(to: string, needsReason = false, extra: Record<string, any> = {}) {
  const reason = needsReason ? prompt('Reason?') : null
  if (needsReason && !reason) return
  router.post(route('agri.work-orders.transition', props.order.id), { to, reason, ...extra }, keep)
}
const farmWorkers = computed(() => props.options.workers.filter((w: any) => w.farm_id === props.order.farm_id))
const farmMachines = computed(() => props.options.machines.filter((m: any) => m.farm_id === props.order.farm_id))
const photo = (l: any) => route('agri.work-orders.photo', [props.order.id, l.uuid])
const mapUrl = computed(() => props.order.map ? `https://www.google.com/maps/dir/?api=1&destination=${props.order.map.lat},${props.order.map.lng}` : null)
const ISSUES = [{ title: 'Incident (someone hurt, near miss)', value: 'incident' }, { title: 'Hazard', value: 'hazard' }, { title: 'Crop disease', value: 'disease' },
  { title: 'Pest', value: 'pest' }, { title: 'Equipment failure', value: 'equipment_failure' }, { title: 'Other problem', value: 'other' }]
const resolving = ref<any>(null)
const total = computed(() => (props.order.actual_labour_cost ?? 0) + (props.order.actual_material_cost ?? 0) + (props.order.actual_machine_cost ?? 0))
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.work-orders.index')">Work orders</Link> / {{ order.farm }}</div>
        <h4 class="text-h4 mb-1">{{ order.reference }} · {{ order.title }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ human(order.category) }} · {{ order.planned_start }} → {{ order.planned_end }}
          <template v-if="order.crop_plan"> · crop <Link :href="route('agri.crops.plans.show', order.crop_plan_uuid)">{{ order.crop_plan }}</Link></template>
          <template v-if="order.group"> · herd {{ order.group }}</template><template v-if="order.field"> · field {{ order.field }}</template></p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VChip :color="statusColor(s === 'verification' ? 'submitted' : s)" variant="tonal">{{ human(s) }}</VChip>
        <a v-if="mapUrl" :href="mapUrl" target="_blank" rel="noopener"><VBtn variant="tonal" prepend-icon="tabler-navigation">Navigate</VBtn></a>
        <VBtn v-if="can.approve && s === 'draft'" color="success" prepend-icon="tabler-check" @click="go('approved')">Approve</VBtn>
        <VBtn v-if="canWork && ['approved', 'paused', 'rejected'].includes(s)" color="primary" prepend-icon="tabler-player-play" @click="go('in_progress')">{{ s === 'approved' ? 'Start' : 'Resume' }}</VBtn>
        <VBtn v-if="canWork && s === 'in_progress'" color="warning" prepend-icon="tabler-send" @click="dialog = 'submit'">Submit for verification</VBtn>
        <VBtn v-if="can.verify && s === 'verification'" color="success" prepend-icon="tabler-circle-check" @click="dialog = 'verify'">Verify & complete</VBtn>
        <RowMenu size="default" :actions="[
          { title: 'Record progress / materials / machine hours', icon: 'tabler-notes', show: canWork && ['in_progress', 'paused', 'rejected'].includes(s), onClick: () => (dialog = 'log') },
          { title: 'Pause', icon: 'tabler-player-pause', show: canWork && s === 'in_progress', onClick: () => go('paused', true) },
          { title: 'Send back (reject)', icon: 'tabler-arrow-back-up', show: can.verify && s === 'verification', onClick: () => go('rejected', true) },
          { title: 'Edit job', icon: 'tabler-edit', show: can.manage && ['draft', 'approved'].includes(s), onClick: () => (dialog = 'edit') },
          { title: 'Reopen (more work needed)', icon: 'tabler-rotate', show: (can.approve || can.verify) && s === 'completed', onClick: () => go('in_progress', true) },
          { title: 'Back to draft', icon: 'tabler-arrow-back', show: can.manage && ['approved', 'cancelled'].includes(s), onClick: () => go('draft') },
          { title: 'Cancel job', icon: 'tabler-x', color: 'error', show: can.manage && !['completed', 'cancelled', 'verification'].includes(s), onClick: () => go('cancelled', true) },
        ]" />
      </div>
    </div>
    <VAlert v-if="order.reopen_count" type="info" variant="tonal" density="compact" class="mb-4">Reopened {{ order.reopen_count }}×. Materials and machine hours already booked stay booked; completing again adds only what was used since.</VAlert>
    <VAlert v-if="order.overdue" type="error" variant="tonal" class="mb-4">Overdue: planned to finish {{ order.planned_end }}.</VAlert>
    <VAlert v-if="s === 'rejected' && order.verification_note" type="warning" variant="tonal" class="mb-4">Sent back: {{ order.verification_note }}</VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Output</div><div class="text-h5">{{ order.actual_output ?? '—' }} <span class="text-body-2">/ {{ order.expected_output ?? '—' }} {{ order.output_unit }}</span></div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Labour (approved)</div><div class="text-h5">{{ money(labour.time_cost + labour.piecework) }}</div><div class="text-caption">{{ labour.timesheets }} timesheet(s) · {{ num((order.actual_labour_minutes ?? 0) / 60, 1) }} h logged</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Materials · machines</div><div class="text-h5">{{ money(order.actual_material_cost) }}</div><div class="text-caption">{{ money(order.actual_machine_cost) }} machine time</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Actual vs budget</div><div class="text-h5" :class="order.budget_amount && total > order.budget_amount ? 'text-error' : ''">{{ s === 'completed' ? money(total) : '—' }}</div><div class="text-caption">Budget {{ money(order.budget_amount) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="5">
        <VCard v-if="safety.length" title="Safety checks (SHEQ)" subtitle="The job starts once each has passed" class="mb-4">
          <VList density="compact"><VListItem v-for="c in safety" :key="c.code" :title="c.title" :subtitle="c.reference ?? 'Not done yet'">
            <template #append><VChip v-if="c.passed !== null" size="x-small" :color="c.passed ? 'success' : 'error'" class="me-2">{{ c.passed ? 'Passed' : 'Failed' }}</VChip>
              <VBtn v-if="c.passed !== true && can.work" size="small" variant="tonal" color="primary" :href="c.url">{{ c.passed === false ? 'Inspect again' : 'Inspect' }}</VBtn></template>
          </VListItem></VList>
        </VCard>
        <VCard title="Crew" class="mb-4">
          <template #append><VBtn v-if="can.manage && !['completed', 'cancelled'].includes(s)" size="small" variant="tonal" prepend-icon="tabler-user-plus" @click="dialog = 'workers'">Add</VBtn></template>
          <VList density="compact"><VListItem v-for="w in workers" :key="w.id" :title="w.name" :subtitle="w.warnings ?? undefined" /><VListItem v-if="!workers.length" title="Nobody yet" /></VList>
          <VCardText v-if="order.required_certifications.length" class="text-caption">Needs: {{ order.required_certifications.join(', ') }}</VCardText>
        </VCard>
        <VCard title="Machines" class="mb-4">
          <template #append><VBtn v-if="can.manage && !['completed', 'cancelled'].includes(s)" size="small" variant="tonal" prepend-icon="tabler-tractor" @click="dialog = 'machine'">Book</VBtn></template>
          <VList density="compact" lines="two"><VListItem v-for="m in machines" :key="m.id" :title="m.machine" :subtitle="`${m.from} → ${m.to}` + (m.actual_hours ? ` · ${m.actual_hours} h used` : '') + (m.warnings ? ' · ' + m.warnings : '')" /><VListItem v-if="!machines.length" title="None booked" /></VList>
        </VCard>
        <VCard title="History"><VList density="compact"><VListItem v-for="(h, i) in history" :key="i" :title="`${h.at} · ${human(h.to)}`" :subtitle="h.reason ?? undefined" /></VList></VCard>
      </VCol>
      <VCol cols="12" md="7">
        <VCard title="Materials" class="mb-4">
          <template #append><VBtn v-if="can.manage && ['draft', 'approved', 'in_progress', 'paused'].includes(s)" size="small" variant="tonal" prepend-icon="tabler-package" @click="dialog = 'material'">Add</VBtn></template>
          <VTable density="compact"><thead><tr><th>Item</th><th class="text-end">Planned</th><th class="text-end">Used</th><th>Reserved</th><th class="text-end">Cost</th></tr></thead>
            <tbody><tr v-for="m in materials" :key="m.id"><td>{{ m.item }}<span v-if="m.withholding_days" class="text-caption text-warning"> · WHP {{ m.withholding_days }}d</span></td>
              <td class="text-end">{{ num(m.planned) }} {{ m.uom }}</td><td class="text-end">{{ m.actual !== null ? num(m.actual) : '—' }}</td>
              <td><VIcon :icon="m.reserved ? 'tabler-lock' : 'tabler-lock-open'" size="16" /></td><td class="text-end">{{ m.cost ? money(m.cost) : '' }}</td></tr>
              <tr v-if="!materials.length"><td colspan="5" class="text-center text-medium-emphasis py-3">No materials planned.</td></tr></tbody></VTable>
        </VCard>
        <VCard title="From the field">
          <VList lines="two" density="compact">
            <VListItem v-for="l in logs" :key="l.id" :title="`${l.at} · ${l.issue_type ? human(l.issue_type) : human(l.kind)}${l.worker ? ' · ' + l.worker : ''}`"
              :subtitle="[l.minutes ? `${l.minutes} min` : '', l.quantity ? `qty ${l.quantity}` : '', l.machine_hours ? `${l.machine_hours} machine h` : '', l.note, l.resolution ? `Resolved: ${l.resolution}` : ''].filter(Boolean).join(' · ')">
              <template #prepend><VIcon v-if="l.kind === 'issue'" :icon="l.resolved ? 'tabler-circle-check' : 'tabler-alert-triangle'" :color="l.resolved ? 'success' : l.severity === 'high' ? 'error' : 'warning'" class="me-2" /></template>
              <template #append>
                <VBtn v-if="l.kind === 'issue' && !l.resolved && (can.manage || can.verify)" size="x-small" variant="tonal" class="me-2" @click="resolving = l">Resolve</VBtn>
                <a v-if="l.photo" :href="photo(l)" target="_blank" :title="l.attachment"><VIcon :icon="l.is_image ? 'tabler-photo' : 'tabler-file-text'" /></a>
              </template>
            </VListItem>
            <VListItem v-if="!logs.length" title="Nothing recorded yet" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog :model-value="dialog === 'edit'" title="Edit job" method="patch" :url="route('agri.work-orders.update', order.id)" :fields="workOrderFields(options, false, order.farm_id)"
      :values="{ ...order, planned_start: order.planned_start.replace(' ', 'T'), planned_end: order.planned_end.replace(' ', 'T'), crop_plan_id: order.crop_plan_uuid, livestock_group_id: order.group_uuid, field_id: order.field_uuid }" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'workers'" title="Add to the crew" :url="route('agri.work-orders.workers', order.id)" :fields="[
      { key: 'worker_ids', label: 'People', type: 'multiselect', required: true, items: farmWorkers.map((w: any) => ({ title: w.name, value: w.id })), cols: 12 },
      { key: 'accept_warnings', label: 'Assign even with warnings (they are recorded)', type: 'switch', cols: 12 },
    ]" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'material'" title="Plan a material" :url="route('agri.work-orders.materials', order.id)" :fields="[
      { key: 'stock_item_id', label: 'Item from the farm store', type: 'autocomplete', required: true, items: options.stock_items.map((i: any) => ({ title: i.name, value: i.id })), cols: 12 },
      { key: 'planned_quantity', label: 'Quantity', type: 'number', required: true, cols: 4 }, { key: 'rate_per_ha', label: 'Rate / ha', type: 'number', cols: 4 },
      { key: 'withholding_days', label: 'Withholding days', type: 'number', cols: 4 },
    ]" intro="Reserved in the farm store when the job is approved; issued once when it is verified." @update:model-value="close" />
    <FormDialog :model-value="dialog === 'machine'" title="Book a machine" :url="route('agri.work-orders.machines', order.id)"
      :values="{ booked_from: order.planned_start.replace(' ', 'T'), booked_to: order.planned_end.replace(' ', 'T') }" :fields="[
        { key: 'machine_id', label: 'Machine', type: 'select', required: true, items: farmMachines.map((m: any) => ({ title: m.name + (m.status !== 'active' ? ` (${m.status})` : ''), value: m.id })), cols: 12 },
        { key: 'booked_from', label: 'From', type: 'datetime', required: true }, { key: 'booked_to', label: 'To', type: 'datetime', required: true },
        { key: 'planned_hours', label: 'Planned hours', type: 'number' }, { key: 'accept_warnings', label: 'Book even if due for service', type: 'switch' },
      ]" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'log'" title="Record from the field" :url="route('agri.work-orders.log', order.id)" :values="{ kind: 'progress' }" :fields="[
      { key: 'kind', label: 'What', type: 'select', required: true, items: [{ title: 'Progress', value: 'progress' }, { title: 'Material used', value: 'material' }, { title: 'Machine hours', value: 'machine' }, { title: 'Problem / incident', value: 'issue' }, { title: 'Request help or materials', value: 'request' }], cols: 12 },
      { key: 'issue_type', label: 'Kind of problem', type: 'select', items: ISSUES, show: (f: any) => f.kind === 'issue', cols: 8 },
      { key: 'severity', label: 'How serious', type: 'select', items: [{ title: 'Low', value: 'low' }, { title: 'Medium', value: 'medium' }, { title: 'High', value: 'high' }], show: (f: any) => f.kind === 'issue', cols: 4 },
      { key: 'minutes', label: 'Minutes worked', type: 'number', show: (f: any) => f.kind === 'progress' },
      { key: 'quantity', label: 'Quantity', type: 'number', show: (f: any) => ['progress', 'material'].includes(f.kind) },
      { key: 'material_id', label: 'Material', type: 'select', items: materials.map((m: any) => ({ title: m.item, value: m.id })), show: (f: any) => f.kind === 'material' },
      { key: 'machine_id', label: 'Machine', type: 'select', items: machines.map((m: any) => ({ title: m.machine, value: m.id })), show: (f: any) => f.kind === 'machine' || f.issue_type === 'equipment_failure' },
      { key: 'machine_hours', label: 'Hours', type: 'number', show: (f: any) => f.kind === 'machine' },
      { key: 'note', label: 'Note', type: 'textarea', cols: 12 }, { key: 'photo', label: 'Photo or document', type: 'file', cols: 12 },
    ]" intro="Problems are sent to the farm's managers at once. Disease and pests also go on the crop's observations; a failed machine is taken out of service." @update:model-value="close" />
    <FormDialog v-if="resolving" :model-value="!!resolving" :title="`Resolve: ${human(resolving.issue_type ?? 'problem')}`" :url="route('agri.work-orders.resolve', [order.id, resolving.uuid])"
      :fields="[{ key: 'resolution', label: 'What was done', type: 'textarea', required: true, cols: 12 }]" submit-label="Resolve" @update:model-value="v => !v && (resolving = null)" />
    <FormDialog :model-value="dialog === 'submit'" title="Submit for verification" :url="route('agri.work-orders.transition', order.id)" :values="{ to: 'verification', actual_output: order.expected_output }" :fields="[
      { key: 'actual_output', label: `Output achieved${order.output_unit ? ' (' + order.output_unit + ')' : ''}`, type: 'number' }, { key: 'reason', label: 'Notes for the supervisor', type: 'textarea', cols: 12 },
    ]" submit-label="Submit" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'verify'" title="Verify and complete" :url="route('agri.work-orders.transition', order.id)" :values="{ to: 'completed', actual_output: order.actual_output }" :fields="[
      { key: 'actual_output', label: 'Verified output', type: 'number' }, { key: 'reason', label: 'Verification note', type: 'textarea', cols: 12 },
    ]" intro="Completing issues the materials from the farm store, logs machine hours and records the work on the crop or herd — once." submit-label="Complete job" @update:model-value="close" />
  </div>
</template>
