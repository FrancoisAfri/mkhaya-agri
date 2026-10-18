<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Op = {
  id: string; sequence: number; code: string | null; name: string; instructions: string | null
  work_centre_id: number | null; work_centre: string | null; machine_type: string | null
  setup_minutes: number; run_minutes: number; run_basis: string; move_minutes: number
  labour_headcount: number; labour_percent_of_time: number; expected_yield_percent: number
  parallel_group: string | null; is_mandatory: boolean; is_inspection_point: boolean
  inspection_plan_reference: string | null; is_approval_gate: boolean; gate_permission: string | null
  is_subcontracted: boolean; subcontract_lead_days: number | null; subcontract_cost_per_unit: number | null
  requires: number[]
  alternates: Array<{ id: string; work_centre: string | null; priority: number; run_minutes: number | null }>
}

const props = defineProps<{
  routing: {
    id: string; revision: number; name: string | null; status: string; is_default: boolean
    product_code: string | null; product_name: string | null; product_id: string | null
    min_batch_size: number | null; max_batch_size: number | null; standard_yield_percent: number
    yield_tolerance_percent: number | null; effective_from: string | null; effective_to: string | null
    is_editable: boolean; notes: string | null; superseded_by: { id: string; revision: number } | null
    operations: Op[]
    history: Array<{ id: string; action: string; acted_at: string | null; comment: string | null }>
  }
  work_centres: Array<{ id: number; code: string; name: string; factory: string | null }>
  factories: Array<{ id: string; code: string; name: string }>
  permissions: string[]
  can: { edit: boolean; approve: boolean; revise: boolean; obsolete: boolean; delete: boolean }
  documents_panel: { available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number; documents: Array<Record<string, any>> }
}>()

const statusColor = (s: string) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s] ?? 'default'
const centreItems = computed(() => props.work_centres.map(c => ({ title: `${c.code} — ${c.name}${c.factory ? ` (${c.factory})` : ''}`, value: c.id })))

// ── Header ──────────────────────────────────────────────────────────────────
const header = useForm({
  name: props.routing.name ?? '', min_batch_size: props.routing.min_batch_size, max_batch_size: props.routing.max_batch_size,
  standard_yield_percent: props.routing.standard_yield_percent, yield_tolerance_percent: props.routing.yield_tolerance_percent,
})
function saveHeader() {
  header.patch(route('manufacturing.engineering.routings.update', props.routing.id), { preserveScroll: true })
}

// ── Operation dialog (create and edit share it) ─────────────────────────────
const blank = () => ({
  sequence: null as number | null, code: '', name: '', instructions: '', work_centre_id: null as number | null,
  machine_type: '', setup_minutes: 0, run_minutes: 0, run_basis: 'PER_UNIT', move_minutes: 0,
  labour_headcount: 1, labour_percent_of_time: 100, expected_yield_percent: 100, parallel_group: '',
  is_mandatory: true, is_inspection_point: false, inspection_plan_reference: '', is_approval_gate: false,
  gate_permission: null as string | null, is_subcontracted: false, subcontract_lead_days: null as number | null,
  subcontract_cost_per_unit: null as number | null,
})
const opDialog = ref(false)
const editing = ref<Op | null>(null)
const opForm = useForm(blank())

function openCreate() {
  editing.value = null
  opForm.defaults(blank()); opForm.reset(); opForm.clearErrors()
  opDialog.value = true
}

function openEdit(op: Op) {
  editing.value = op
  Object.assign(opForm, {
    sequence: op.sequence, code: op.code ?? '', name: op.name, instructions: op.instructions ?? '',
    work_centre_id: op.work_centre_id, machine_type: op.machine_type ?? '', setup_minutes: op.setup_minutes,
    run_minutes: op.run_minutes, run_basis: op.run_basis, move_minutes: op.move_minutes,
    labour_headcount: op.labour_headcount, labour_percent_of_time: op.labour_percent_of_time,
    expected_yield_percent: op.expected_yield_percent, parallel_group: op.parallel_group ?? '',
    is_mandatory: op.is_mandatory, is_inspection_point: op.is_inspection_point,
    inspection_plan_reference: op.inspection_plan_reference ?? '', is_approval_gate: op.is_approval_gate,
    gate_permission: op.gate_permission, is_subcontracted: op.is_subcontracted,
    subcontract_lead_days: op.subcontract_lead_days, subcontract_cost_per_unit: op.subcontract_cost_per_unit,
  })
  opForm.clearErrors()
  opDialog.value = true
}

function saveOp() {
  const payload = (data: any) => ({ ...data, parallel_group: data.parallel_group || null, machine_type: data.machine_type || null })
  const done = { preserveScroll: true, onSuccess: () => { opDialog.value = false } }
  if (editing.value) {
    opForm.transform(payload).patch(route('manufacturing.engineering.routings.operations.update', [props.routing.id, editing.value.id]), done)
  } else {
    opForm.transform(payload).post(route('manufacturing.engineering.routings.operations.store', props.routing.id), done)
  }
}

function removeOp(op: Op) {
  confirmer.value.open({
    title: t('manufacturing.routing.remove_operation'),
    message: t('manufacturing.product.remove_named', { name: op.name }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.routings.operations.destroy', [props.routing.id, op.id]), { preserveScroll: true }))
}

// ── Alternatives ────────────────────────────────────────────────────────────
const altFor = ref<Op | null>(null)
const altForm = useForm({ work_centre_id: null as number | null, priority: 1, run_minutes: null as number | null })
function saveAlt() {
  if (!altFor.value) return
  altForm.post(route('manufacturing.engineering.routings.operations.alternates.store', [props.routing.id, altFor.value.id]), {
    preserveScroll: true, onSuccess: () => { altFor.value = null; altForm.reset() },
  })
}

// ── Lifecycle ───────────────────────────────────────────────────────────────
const reasonDialog = ref<null | 'reject' | 'revise' | 'obsolete'>(null)
const reasonForm = useForm({ reason: '' })
function lifecycle(action: 'submit' | 'approve') {
  router.post(route(`manufacturing.engineering.routings.${action}`, props.routing.id), {}, { preserveScroll: true })
}
function sendReason() {
  if (!reasonDialog.value) return
  reasonForm.post(route(`manufacturing.engineering.routings.${reasonDialog.value}`, props.routing.id), {
    preserveScroll: true, onSuccess: () => { reasonDialog.value = null; reasonForm.reset() },
  })
}
function discard() {
  confirmer.value.open({
    title: t('manufacturing.bom.discard'), message: t('manufacturing.routing.discard_body'),
    confirmText: t('manufacturing.bom.discard'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.routings.destroy', props.routing.id)))
}

// ── Lead time and capacity ──────────────────────────────────────────────────
const est = ref({ quantity: props.routing.max_batch_size ?? 100, factory: props.factories[0]?.id ?? null, start: '' })
const estimate = ref<any>(null)
const estError = ref<string | null>(null)
const estimating = ref(false)

async function runEstimate() {
  estimating.value = true
  estError.value = null
  try {
    const { data } = await axios.get(route('manufacturing.engineering.routings.estimate', props.routing.id), {
      params: { quantity: est.value.quantity, factory: est.value.factory || undefined, start: est.value.start || undefined },
    })
    estimate.value = data
  } catch (e: any) {
    estimate.value = null
    estError.value = e?.response?.data?.error ?? e?.response?.data?.message ?? t('manufacturing.routing.estimate_failed')
  } finally {
    estimating.value = false
  }
}

const hours = (m: number) => (m / 60).toFixed(1)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
              :href="route('manufacturing.engineering.routings.index')">{{ t('manufacturing.routing.back') }}</VBtn>
        <h4 class="text-h4">{{ routing.product_code }} — {{ t('manufacturing.routing.revision_n', { n: routing.revision }) }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ routing.product_name }}<template v-if="routing.name"> · {{ routing.name }}</template></div>
        <div class="d-flex gap-2 mt-2">
          <VChip size="small" label variant="tonal" :color="statusColor(routing.status)">{{ t(`manufacturing.status.${routing.status}`) }}</VChip>
          <VChip v-if="routing.is_default" size="small" label color="primary">{{ t('manufacturing.bom.default') }}</VChip>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.edit" variant="tonal" prepend-icon="tabler-send" @click="lifecycle('submit')">{{ t('manufacturing.bom.submit') }}</VBtn>
        <VBtn v-if="can.approve" color="success" prepend-icon="tabler-check" @click="lifecycle('approve')">{{ t('manufacturing.common.approve') }}</VBtn>
        <VBtn v-if="can.approve" variant="tonal" color="error" prepend-icon="tabler-x" @click="reasonDialog = 'reject'">{{ t('manufacturing.bom.reject') }}</VBtn>
        <VBtn v-if="can.revise" color="primary" prepend-icon="tabler-copy" @click="reasonDialog = 'revise'">{{ t('manufacturing.bom.revise') }}</VBtn>
        <VBtn v-if="can.obsolete" variant="text" color="error" @click="reasonDialog = 'obsolete'">{{ t('manufacturing.bom.obsolete') }}</VBtn>
        <VBtn v-if="can.delete" variant="text" color="error" @click="discard">{{ t('manufacturing.bom.discard') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="routing.status === 'APPROVED'" type="info" variant="tonal" class="mb-4">{{ t('manufacturing.routing.locked_approved') }}</VAlert>
    <VAlert v-else-if="routing.status === 'SUPERSEDED'" type="warning" variant="tonal" class="mb-4">
      {{ t('manufacturing.bom.locked_superseded') }}
      <a v-if="routing.superseded_by" :href="route('manufacturing.engineering.routings.show', routing.superseded_by.id)">
        {{ t('manufacturing.routing.revision_n', { n: routing.superseded_by.revision }) }}</a>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.routing.operations') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.routing.operations_hint') }}</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.edit" size="small" color="primary" prepend-icon="tabler-plus" @click="openCreate">
                {{ t('manufacturing.routing.add_operation') }}
              </VBtn>
            </template>
          </VCardItem>
          <VList v-if="routing.operations.length" lines="three">
            <VListItem v-for="op in routing.operations" :key="op.id">
              <template #prepend>
                <VAvatar size="36" :color="op.is_approval_gate || op.is_inspection_point ? 'warning' : 'primary'" variant="tonal">
                  {{ op.sequence }}
                </VAvatar>
              </template>
              <VListItemTitle class="font-weight-medium">{{ op.name }}</VListItemTitle>
              <VListItemSubtitle>
                <template v-if="op.is_subcontracted">{{ t('manufacturing.routing.subcontracted_n_days', { n: op.subcontract_lead_days ?? 0 }) }}</template>
                <template v-else>{{ op.work_centre ?? op.machine_type ?? '—' }}</template>
                · {{ t('manufacturing.routing.setup_run', { setup: op.setup_minutes, run: op.run_minutes, basis: t(`manufacturing.run_basis.${op.run_basis}`) }) }}
                · {{ t('manufacturing.routing.yield_n', { n: op.expected_yield_percent }) }}
              </VListItemSubtitle>
              <div class="d-flex gap-1 flex-wrap mt-1">
                <VChip v-if="op.parallel_group" size="x-small" label color="indigo" variant="tonal">{{ t('manufacturing.routing.parallel_n', { n: op.parallel_group }) }}</VChip>
                <VChip v-if="op.is_inspection_point" size="x-small" label color="warning" variant="tonal">{{ t('manufacturing.routing.inspection') }}</VChip>
                <VChip v-if="op.is_approval_gate" size="x-small" label color="error" variant="tonal">{{ t('manufacturing.routing.gate') }}</VChip>
                <VChip v-if="!op.is_mandatory" size="x-small" label variant="tonal">{{ t('manufacturing.bom.optional') }}</VChip>
                <VChip v-if="op.requires.length" size="x-small" label variant="outlined">{{ t('manufacturing.routing.after_n', { n: op.requires.join(', ') }) }}</VChip>
                <VChip v-for="a in op.alternates" :key="a.id" size="x-small" label variant="tonal">{{ t('manufacturing.routing.or_n', { n: a.work_centre }) }}</VChip>
              </div>
              <template v-if="can.edit" #append>
                <VBtn icon="tabler-switch-horizontal" variant="text" size="small" :aria-label="t('manufacturing.routing.add_alternate')" @click="altFor = op" />
                <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')" @click="openEdit(op)" />
                <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')" @click="removeOp(op)" />
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.routing.no_operations') }}</VCardText>
        </VCard>

        <!-- Lead time and capacity, recalculated in place. -->
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.routing.estimate') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.routing.estimate_hint') }}</VCardSubtitle>
          </VCardItem>
          <VCardText class="d-flex gap-3 flex-wrap align-center">
            <VTextField v-model.number="est.quantity" type="number" min="0" step="any" density="compact" hide-details
                        style="max-width: 160px" :label="t('manufacturing.explode.quantity')" />
            <VSelect v-model="est.factory" :items="factories" item-value="id" :item-title="(f: any) => `${f.code} — ${f.name}`"
                     clearable density="compact" hide-details style="max-width: 240px" :label="t('manufacturing.workcentres.factory')" />
            <VTextField v-model="est.start" type="date" density="compact" hide-details style="max-width: 180px" :label="t('manufacturing.routing.start')" />
            <VBtn color="primary" prepend-icon="tabler-calculator" :loading="estimating" @click="runEstimate">{{ t('manufacturing.explode.calculate') }}</VBtn>
          </VCardText>
          <VAlert v-if="estError" type="error" variant="tonal" class="mx-4 mb-4">{{ estError }}</VAlert>
          <template v-if="estimate">
            <VCardText class="pt-0">
              <VAlert v-for="w in estimate.warnings" :key="w" type="warning" variant="tonal" density="compact" class="mb-2">{{ w }}</VAlert>
              <VRow dense>
                <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.explode.batches') }}</div><div class="text-h6">{{ estimate.batches }} × {{ estimate.batch_size }}</div></VCol>
                <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.routing.start_quantity') }}</div><div class="text-h6">{{ estimate.start_quantity }}</div></VCol>
                <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.routing.lead_time') }}</div><div class="text-h6">{{ hours(estimate.lead_minutes) }} h<template v-if="estimate.subcontract_days"> + {{ estimate.subcontract_days }} d</template></div></VCol>
                <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.routing.finish') }}</div><div class="text-h6">{{ estimate.estimated_finish ?? '—' }}</div></VCol>
              </VRow>
            </VCardText>
            <VTable density="compact">
              <thead><tr>
                <th>{{ t('manufacturing.routing.capacity_at') }}</th>
                <th class="text-end">{{ t('manufacturing.routing.machine_hours') }}</th>
                <th class="text-end">{{ t('manufacturing.routing.labour_hours') }}</th>
              </tr></thead>
              <tbody>
                <tr v-for="c in estimate.capacity" :key="`${c.work_centre_id}-${c.machine_type}`">
                  <td>{{ c.work_centre ?? c.machine_type }}</td>
                  <td class="text-end">{{ c.hours }}</td>
                  <td class="text-end">{{ hours(c.labour_minutes) }}</td>
                </tr>
              </tbody>
            </VTable>
          </template>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.routing.limits') }}</VCardTitle></VCardItem>
          <VCardText>
            <template v-if="can.edit">
              <VTextField v-model="header.name" density="compact" class="mb-2" :label="t('manufacturing.bom.name')" />
              <VTextField v-model.number="header.min_batch_size" type="number" min="0" step="any" density="compact" class="mb-2"
                          :error-messages="header.errors.min_batch_size" :label="t('manufacturing.routing.min_batch')" />
              <VTextField v-model.number="header.max_batch_size" type="number" min="0" step="any" density="compact" class="mb-2"
                          :error-messages="header.errors.max_batch_size" :label="t('manufacturing.routing.max_batch')" />
              <VTextField v-model.number="header.standard_yield_percent" type="number" min="0" max="100" step="any" suffix="%"
                          density="compact" class="mb-2" :label="t('manufacturing.routing.yield')" />
              <VTextField v-model.number="header.yield_tolerance_percent" type="number" min="0" max="100" step="any" suffix="%"
                          density="compact" class="mb-2" :label="t('manufacturing.routing.yield_tolerance')" />
              <VBtn block color="primary" :loading="header.processing" @click="saveHeader">{{ t('manufacturing.common.save') }}</VBtn>
            </template>
            <VList v-else density="compact" class="pa-0">
              <VListItem :title="t('manufacturing.routing.batch_range')">
                <template #append>{{ routing.min_batch_size ?? '—' }} – {{ routing.max_batch_size ?? '∞' }}</template></VListItem>
              <VListItem :title="t('manufacturing.routing.yield')"><template #append>{{ routing.standard_yield_percent }}%</template></VListItem>
              <VListItem :title="t('manufacturing.routing.yield_tolerance')"><template #append>{{ routing.yield_tolerance_percent ?? '—' }}%</template></VListItem>
            </VList>
          </VCardText>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.bom.history') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="h in routing.history" :key="h.id">
              <VListItemTitle>{{ t(`manufacturing.bom_action.${h.action}`) }}</VListItemTitle>
              <VListItemSubtitle>{{ h.acted_at ? new Date(h.acted_at).toLocaleString() : '' }}<template v-if="h.comment"> · {{ h.comment }}</template></VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!routing.history.length"><VListItemTitle class="text-medium-emphasis">{{ t('manufacturing.bom.no_history') }}</VListItemTitle></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Operation -->
    <VDialog v-model="opDialog" max-width="760">
      <VCard :title="editing ? t('manufacturing.routing.edit_operation') : t('manufacturing.routing.add_operation')">
        <VCardText>
          <VRow dense>
            <VCol cols="3"><VTextField v-model.number="opForm.sequence" type="number" min="1" :label="t('manufacturing.routing.sequence')" :hint="t('manufacturing.routing.sequence_hint')" persistent-hint /></VCol>
            <VCol cols="3"><VTextField v-model="opForm.code" :label="t('manufacturing.common.code')" /></VCol>
            <VCol cols="6"><VTextField v-model="opForm.name" :error-messages="opForm.errors.name" :label="t('manufacturing.common.name')" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="opForm.work_centre_id" clearable :items="centreItems" :disabled="opForm.is_subcontracted" :label="t('manufacturing.routing.work_centre')" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="opForm.machine_type" :disabled="opForm.is_subcontracted" :label="t('manufacturing.routing.machine_type')" :hint="t('manufacturing.routing.machine_type_hint')" persistent-hint /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="opForm.setup_minutes" type="number" min="0" step="any" :label="t('manufacturing.routing.setup_minutes')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="opForm.run_minutes" type="number" min="0" step="any" :label="t('manufacturing.routing.run_minutes')" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="opForm.run_basis" :items="['PER_UNIT', 'PER_BATCH'].map(b => ({ title: t(`manufacturing.run_basis.${b}`), value: b }))" :label="t('manufacturing.routing.run_basis')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="opForm.move_minutes" type="number" min="0" step="any" :label="t('manufacturing.routing.move_minutes')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="opForm.labour_headcount" type="number" min="0" step="any" :label="t('manufacturing.routing.headcount')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="opForm.labour_percent_of_time" type="number" min="0" max="100" step="any" suffix="%" :label="t('manufacturing.routing.labour_share')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="opForm.expected_yield_percent" type="number" min="0" max="100" step="any" suffix="%" :label="t('manufacturing.routing.op_yield')" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="opForm.parallel_group" :label="t('manufacturing.routing.parallel_group')" :hint="t('manufacturing.routing.parallel_hint')" persistent-hint /></VCol>
            <VCol cols="12"><VTextarea v-model="opForm.instructions" rows="2" auto-grow :label="t('manufacturing.routing.instructions')" /></VCol>
          </VRow>
          <div class="d-flex flex-wrap gap-6 mt-2">
            <VSwitch v-model="opForm.is_mandatory" color="primary" hide-details :label="t('manufacturing.routing.mandatory')" />
            <VSwitch v-model="opForm.is_inspection_point" color="warning" hide-details :label="t('manufacturing.routing.inspection')" />
            <VSwitch v-model="opForm.is_approval_gate" color="error" hide-details :label="t('manufacturing.routing.gate')" />
            <VSwitch v-model="opForm.is_subcontracted" color="indigo" hide-details :label="t('manufacturing.routing.subcontracted')" />
          </div>
          <VRow dense class="mt-2">
            <VCol v-if="opForm.is_inspection_point" cols="12" md="6"><VTextField v-model="opForm.inspection_plan_reference" :label="t('manufacturing.product.plan_reference')" /></VCol>
            <VCol v-if="opForm.is_approval_gate" cols="12" md="6"><VSelect v-model="opForm.gate_permission" :items="permissions" :error-messages="opForm.errors.gate_permission" :label="t('manufacturing.routing.gate_permission')" /></VCol>
            <VCol v-if="opForm.is_subcontracted" cols="6" md="3"><VTextField v-model.number="opForm.subcontract_lead_days" type="number" min="0" :label="t('manufacturing.routing.lead_days')" /></VCol>
            <VCol v-if="opForm.is_subcontracted" cols="6" md="3"><VTextField v-model.number="opForm.subcontract_cost_per_unit" type="number" min="0" step="any" :label="t('manufacturing.routing.cost_per_unit')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="opDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="opForm.processing" :disabled="!opForm.name" @click="saveOp">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!altFor" max-width="460" @update:model-value="altFor = null">
      <VCard :title="t('manufacturing.routing.add_alternate')">
        <VCardText>
          <VSelect v-model="altForm.work_centre_id" :items="centreItems" :error-messages="altForm.errors.work_centre_id" class="mb-2" :label="t('manufacturing.routing.work_centre')" />
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="altForm.priority" type="number" min="1" :label="t('manufacturing.bom.priority')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="altForm.run_minutes" type="number" min="0" step="any" :label="t('manufacturing.routing.run_minutes')" :hint="t('manufacturing.routing.alt_run_hint')" persistent-hint /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="altFor = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="altForm.processing" :disabled="!altForm.work_centre_id" @click="saveAlt">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!reasonDialog" max-width="480" @update:model-value="reasonDialog = null">
      <VCard :title="reasonDialog ? t(`manufacturing.bom.reason_${reasonDialog}`) : ''">
        <VCardText><VTextarea v-model="reasonForm.reason" rows="3" auto-grow :error-messages="reasonForm.errors.reason" :label="t('manufacturing.bom.reason')" /></VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="reasonDialog = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="reasonForm.processing" :disabled="reasonDialog === 'reject' && !reasonForm.reason" @click="sendReason">{{ t('manufacturing.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
    <RecordDocuments v-if="documents_panel.available" v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.documents.title')" class="mt-4" />
  </div>
</template>
