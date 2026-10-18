<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PersonField from '@/Modules/ProjectManagement/Components/PersonField.vue'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type SI = {
  id: string; number: string; issued_on: string; issued_by: string | null; title: string
  instruction: string; variation: boolean; unconverted: boolean; change: string | null
  status: string; response: string | null
}
type Test = {
  id: string; reference: string; kind: string; element: string | null; sampled_on: string
  result_on: string | null; specified: number | null; value: number | null; unit: string | null
  result: string; lab: string | null; meets_spec: boolean | null
}
type IR = {
  id: string; number: string; title: string; element: string | null; requested_for: string
  notice_hours: number | null; inspector: string | null; status: string; overdue: boolean
  outcome_note: string | null
}
type Completion = {
  id: string; kind: string; section: string | null; certified_on: string; certified_by: string | null
  outstanding: string | null; defects_ends: string | null; in_defects: boolean; released: number | null
}

type Equip = {
  id: string; equipment: string; identifier: string | null; inspected: string
  inspector: string | null; result: string; defects: string | null
  next_due: string | null; overdue: boolean; signed_by: string | null
}

const props = defineProps<{
  project: { id: string; code: string; name: string; currency: string }
  instructions: SI[]
  tests: Test[]
  inspections: IR[]
  completions: Completion[]
  equipment: Equip[]
  tasks: Array<{ id: string; name: string }>
  people: Array<{ id: string; name: string; role: string | null }>
  options: Record<string, string[]>
  can: { record: boolean; costs: boolean; certify: boolean }
}>()

const tab = ref<'instructions' | 'tests' | 'inspections' | 'equipment' | 'completion'>('instructions')

// The three things somebody has to act on today, above the tabs.
const alerts = computed(() => ({
  unconverted: props.instructions.filter(i => i.unconverted).length,
  awaiting: props.tests.filter(x => x.result === 'pending').length,
  overdue: props.inspections.filter(i => i.overdue).length,
}))

function resultColour(r: string) {
  return r === 'pass' ? 'success' : r === 'fail' ? 'error' : 'warning'
}
function statusColour(s: string) {
  return ({
    open: 'warning', actioned: 'info', closed: 'success', disputed: 'error',
    requested: 'info', passed: 'success', failed: 'error', cancelled: 'default',
  } as Record<string, string>)[s] ?? 'default'
}
function when(iso: string) {
  return new Date(iso).toLocaleString()
}

// ── Site instructions ───────────────────────────────────────────────────────
const siDialog = ref(false)
const siForm = useForm({
  issued_on: new Date().toISOString().slice(0, 10), issued_by: '', title: '',
  instruction: '', claimed_as_variation: false,
})

function saveSi() {
  siForm.post(route('project-management.instructions.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { siDialog.value = false; siForm.reset() },
  })
}

function raiseVariation(si: SI) {
  router.post(route('project-management.instructions.variation', [props.project.id, si.id]), {}, { preserveScroll: true })
}

// ── Material tests ──────────────────────────────────────────────────────────
const testDialog = ref(false)
const testForm = useForm({
  kind: 'concrete_cube', element: '', sampled_on: new Date().toISOString().slice(0, 10),
  specified_value: null as number | null, unit: 'MPa', lab: '', notes: '',
})

function saveTest() {
  testForm.post(route('project-management.tests.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { testDialog.value = false; testForm.reset() },
  })
}

const resultDialog = ref(false)
const resultingTest = ref<Test | null>(null)
const resultForm = useForm({ result: 'pass', result_value: null as number | null, result_on: '', lab: '', notes: '' })

function openResult(x: Test) {
  resultingTest.value = x
  resultForm.reset()
  resultForm.clearErrors()
  resultForm.lab = x.lab ?? ''
  resultDialog.value = true
}

function saveResult() {
  if (!resultingTest.value) return
  resultForm.post(route('project-management.tests.result', [props.project.id, resultingTest.value.id]), {
    preserveScroll: true, onSuccess: () => { resultDialog.value = false },
  })
}

// ── Inspection requests ─────────────────────────────────────────────────────
const irDialog = ref(false)
const irForm = useForm({ title: '', element: '', task_id: '', requested_for: '', inspector: '' })

function saveIr() {
  irForm.post(route('project-management.inspections.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { irDialog.value = false; irForm.reset() },
  })
}

const irRecordDialog = ref(false)
const recordingIr = ref<IR | null>(null)
const irRecordForm = useForm({ status: 'passed', inspector: '', outcome_note: '' })

function openIrRecord(x: IR) {
  recordingIr.value = x
  irRecordForm.reset()
  irRecordForm.clearErrors()
  irRecordForm.inspector = x.inspector ?? ''
  irRecordDialog.value = true
}

function saveIrRecord() {
  if (!recordingIr.value) return
  irRecordForm.post(route('project-management.inspections.record', [props.project.id, recordingIr.value.id]), {
    preserveScroll: true, onSuccess: () => { irRecordDialog.value = false },
  })
}

// ── Equipment checks ────────────────────────────────────────────────────────
const equipDialog = ref(false)
const equipForm = useForm({
  equipment: '', identifier: '', inspected_on: new Date().toISOString().slice(0, 10),
  inspector: '', result: 'pass', defects: '', next_due: '', signed_by: '',
})

function saveEquip() {
  equipForm.post(route('project-management.equipment.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { equipDialog.value = false; equipForm.reset() },
  })
}

// ── Completion ──────────────────────────────────────────────────────────────
const completionDialog = ref(false)
const completionForm = useForm({
  kind: 'practical', section: '', certified_on: new Date().toISOString().slice(0, 10),
  certified_by: '', outstanding_work: '', defects_period_days: 365,
  retention_released: null as number | null, notes: '',
})

function saveCompletion() {
  completionForm.post(route('project-management.completion.certify', props.project.id), {
    preserveScroll: true, onSuccess: () => { completionDialog.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.site_records.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.site_records.subtitle') }}</p>
    </div>

    <!-- An instruction claimed as a variation and never raised as one is where money goes
         missing, so it is called out rather than left in a column. -->
    <VAlert v-if="alerts.unconverted" type="warning" variant="tonal" class="mb-3"
            :title="t('project_management.site_records.unconverted_title', { count: alerts.unconverted })">
      {{ t('project_management.site_records.unconverted_body') }}
    </VAlert>
    <VAlert v-if="alerts.overdue" type="error" variant="tonal" class="mb-3"
            :title="t('project_management.site_records.overdue_inspections', { count: alerts.overdue })" />

    <VCard border>
      <VTabs v-model="tab" show-arrows>
        <VTab value="instructions" prepend-icon="tabler-file-text">
          {{ t('project_management.site_records.instructions') }} ({{ instructions.length }})
        </VTab>
        <VTab value="tests" prepend-icon="tabler-flask">
          {{ t('project_management.site_records.tests') }}
          <VChip v-if="alerts.awaiting" size="x-small" color="warning" label class="ms-1">{{ alerts.awaiting }}</VChip>
        </VTab>
        <VTab value="inspections" prepend-icon="tabler-eye-check">
          {{ t('project_management.site_records.inspections') }} ({{ inspections.length }})
        </VTab>
        <VTab value="equipment" prepend-icon="tabler-backhoe">
          {{ t('project_management.equipment.title') }} ({{ equipment.length }})
        </VTab>
        <VTab value="completion" prepend-icon="tabler-certificate">
          {{ t('project_management.site_records.completion') }}
        </VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- ── Site instructions ──────────────────────────────────────────── -->
        <VWindowItem value="instructions">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.site_records.instructions_sub') }}</span>
            <VBtn v-if="can.record" size="small" color="primary" prepend-icon="tabler-plus" @click="siDialog = true">
              {{ t('project_management.site_records.add_instruction') }}
            </VBtn>
          </VCardText>
          <VTable v-if="instructions.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.site_records.col_number') }}</th>
                <th>{{ t('project_management.site_records.col_issued') }}</th>
                <th>{{ t('project_management.site_records.col_instruction') }}</th>
                <th>{{ t('project_management.site_records.col_variation') }}</th>
                <th>{{ t('project_management.registers.col_status') }}</th>
                <th class="text-end">{{ t('project_management.actions.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in instructions" :key="s.id">
                <td class="text-no-wrap">{{ s.number }}</td>
                <td class="text-no-wrap">
                  {{ s.issued_on }}
                  <div v-if="s.issued_by" class="text-caption text-medium-emphasis">{{ s.issued_by }}</div>
                </td>
                <td>
                  <div>{{ s.title }}</div>
                  <div class="text-caption text-medium-emphasis">{{ s.instruction }}</div>
                </td>
                <td>
                  <VChip v-if="s.change" size="x-small" color="success" label>{{ s.change }}</VChip>
                  <VChip v-else-if="s.variation" size="x-small" color="warning" label>
                    {{ t('project_management.site_records.claimed') }}
                  </VChip>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>
                  <VChip size="x-small" label :color="statusColour(s.status)">
                    {{ t(`project_management.site_records.si_status_${s.status}`) }}
                  </VChip>
                </td>
                <td class="text-end text-no-wrap">
                  <VBtn v-if="can.record && s.unconverted" size="small" variant="tonal" color="warning"
                        @click="raiseVariation(s)">
                    {{ t('project_management.site_records.raise_variation') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site_records.no_instructions') }}
            <div class="text-caption mt-1">{{ t('project_management.site_records.no_instructions_hint') }}</div>
          </VCardText>
        </VWindowItem>

        <!-- ── Material tests ─────────────────────────────────────────────── -->
        <VWindowItem value="tests">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.site_records.tests_sub') }}</span>
            <VBtn v-if="can.record" size="small" color="primary" prepend-icon="tabler-plus" @click="testDialog = true">
              {{ t('project_management.site_records.log_test') }}
            </VBtn>
          </VCardText>
          <VTable v-if="tests.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.site_records.col_reference') }}</th>
                <th>{{ t('project_management.site_records.col_what') }}</th>
                <th>{{ t('project_management.site_records.col_sampled') }}</th>
                <th class="text-end">{{ t('project_management.site_records.col_result') }}</th>
                <th>{{ t('project_management.registers.col_status') }}</th>
                <th class="text-end">{{ t('project_management.actions.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="x in tests" :key="x.id">
                <td class="text-no-wrap">{{ x.reference }}</td>
                <td>
                  {{ t(`project_management.site_records.test_${x.kind}`) }}
                  <div v-if="x.element" class="text-caption text-medium-emphasis">{{ x.element }}</div>
                </td>
                <td class="text-no-wrap">{{ x.sampled_on }}</td>
                <td class="text-end text-no-wrap">
                  <template v-if="x.value !== null">
                    {{ x.value }}<template v-if="x.unit"> {{ x.unit }}</template>
                    <div v-if="x.specified !== null" class="text-caption text-medium-emphasis">
                      {{ t('project_management.site_records.specified_n', { n: x.specified }) }}
                    </div>
                  </template>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>
                  <VChip size="x-small" label :color="resultColour(x.result)">
                    {{ t(`project_management.site_records.result_${x.result}`) }}
                  </VChip>
                </td>
                <td class="text-end">
                  <VBtn v-if="can.record && x.result === 'pending'" size="small" variant="tonal"
                        @click="openResult(x)">
                    {{ t('project_management.site_records.record_result') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site_records.no_tests') }}
          </VCardText>
        </VWindowItem>

        <!-- ── Inspection requests ────────────────────────────────────────── -->
        <VWindowItem value="inspections">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.site_records.inspections_sub') }}</span>
            <VBtn v-if="can.record" size="small" color="primary" prepend-icon="tabler-plus" @click="irDialog = true">
              {{ t('project_management.site_records.request_inspection') }}
            </VBtn>
          </VCardText>
          <VTable v-if="inspections.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.site_records.col_number') }}</th>
                <th>{{ t('project_management.site_records.col_what') }}</th>
                <th>{{ t('project_management.site_records.col_when') }}</th>
                <th>{{ t('project_management.site_records.col_notice') }}</th>
                <th>{{ t('project_management.registers.col_status') }}</th>
                <th class="text-end">{{ t('project_management.actions.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in inspections" :key="i.id">
                <td class="text-no-wrap">{{ i.number }}</td>
                <td>
                  {{ i.title }}
                  <div v-if="i.element" class="text-caption text-medium-emphasis">{{ i.element }}</div>
                </td>
                <td class="text-no-wrap" :class="i.overdue ? 'text-error' : ''">{{ when(i.requested_for) }}</td>
                <td class="text-no-wrap">
                  <!-- The notice period is the point of the record: an inspection requested for
                       this afternoon is not a notice. -->
                  <span v-if="i.notice_hours !== null"
                        :class="i.notice_hours < 24 ? 'text-warning' : ''">
                    {{ t('project_management.site_records.hours_n', { n: i.notice_hours }) }}
                  </span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>
                  <VChip size="x-small" label :color="statusColour(i.status)">
                    {{ t(`project_management.site_records.ir_status_${i.status}`) }}
                  </VChip>
                </td>
                <td class="text-end">
                  <VBtn v-if="can.record && i.status === 'requested'" size="small" variant="tonal"
                        @click="openIrRecord(i)">
                    {{ t('project_management.site_records.record_outcome') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site_records.no_inspections') }}
            <div class="text-caption mt-1">{{ t('project_management.site_records.no_inspections_hint') }}</div>
          </VCardText>
        </VWindowItem>

        <!-- ── Equipment checks ───────────────────────────────────────────── -->
        <VWindowItem value="equipment">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.equipment.sub') }}</span>
            <VBtn v-if="can.record" size="small" color="primary" prepend-icon="tabler-plus" @click="equipDialog = true">
              {{ t('project_management.equipment.add') }}
            </VBtn>
          </VCardText>
          <VTable v-if="equipment.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.equipment.col_equipment') }}</th>
                <th>{{ t('project_management.equipment.col_identifier') }}</th>
                <th>{{ t('project_management.equipment.col_inspected') }}</th>
                <th>{{ t('project_management.equipment.col_result') }}</th>
                <th>{{ t('project_management.equipment.col_next') }}</th>
                <th>{{ t('project_management.equipment.col_signed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in equipment" :key="e.id">
                <td>
                  {{ e.equipment }}
                  <div v-if="e.defects" class="text-caption text-medium-emphasis">{{ e.defects }}</div>
                </td>
                <td class="text-no-wrap">{{ e.identifier || '—' }}</td>
                <td class="text-no-wrap">
                  {{ e.inspected }}
                  <div v-if="e.inspector" class="text-caption text-medium-emphasis">{{ e.inspector }}</div>
                </td>
                <td>
                  <VChip size="x-small" label
                         :color="e.result === 'pass' ? 'success' : e.result === 'fail' ? 'error' : 'warning'">
                    {{ t(`project_management.equipment.result_${e.result}`) }}
                  </VChip>
                </td>
                <td class="text-no-wrap" :class="e.overdue ? 'text-error' : ''">
                  {{ e.next_due || '—' }}
                  <VChip v-if="e.overdue" size="x-small" color="error" label class="ms-1">
                    {{ t('project_management.equipment.overdue') }}
                  </VChip>
                </td>
                <td class="text-caption">{{ e.signed_by || '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.equipment.none') }}
          </VCardText>
        </VWindowItem>

        <!-- ── Completion ─────────────────────────────────────────────────── -->
        <VWindowItem value="completion">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.site_records.completion_sub') }}</span>
            <VBtn v-if="can.certify" size="small" color="primary" prepend-icon="tabler-certificate"
                  @click="completionDialog = true">
              {{ t('project_management.site_records.certify') }}
            </VBtn>
          </VCardText>
          <VList v-if="completions.length" density="comfortable">
            <VListItem v-for="c in completions" :key="c.id">
              <template #prepend>
                <VAvatar :color="c.kind === 'final' ? 'success' : 'primary'" variant="tonal">
                  <VIcon icon="tabler-certificate" />
                </VAvatar>
              </template>
              <VListItemTitle>
                {{ t(`project_management.site_records.completion_${c.kind}`) }}
                <template v-if="c.section"> — {{ c.section }}</template>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ c.certified_on }}<template v-if="c.certified_by"> · {{ c.certified_by }}</template>
                <template v-if="c.defects_ends">
                  · {{ t('project_management.site_records.defects_until', { date: c.defects_ends }) }}
                </template>
              </VListItemSubtitle>
              <div v-if="c.outstanding" class="text-caption text-medium-emphasis mt-1">
                {{ t('project_management.site_records.outstanding') }}: {{ c.outstanding }}
              </div>
              <template #append>
                <VChip v-if="c.in_defects" size="x-small" color="warning" label>
                  {{ t('project_management.site_records.in_defects') }}
                </VChip>
                <span v-if="can.costs && c.released !== null" class="text-caption ms-2">
                  {{ project.currency }} {{ c.released.toFixed(2) }}
                </span>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.site_records.no_completion') }}
            <div class="text-caption mt-1">{{ t('project_management.site_records.no_completion_hint') }}</div>
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- ── Dialogs ──────────────────────────────────────────────────────── -->
    <VDialog v-model="siDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site_records.add_instruction') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.site_records.instruction_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="siForm.issued_on" type="date"
                          :label="t('project_management.site_records.field_issued_on')"
                          :error-messages="siForm.errors.issued_on" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <PersonField v-model="siForm.issued_by" :people="people"
                           :label="t('project_management.site_records.field_issued_by')"
                           :hint="t('project_management.site_records.issued_by_hint')"
                           :error-messages="siForm.errors.issued_by" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="siForm.title" :label="t('project_management.site_records.field_title')"
                          :error-messages="siForm.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="siForm.instruction" :label="t('project_management.site_records.field_instruction')"
                         :error-messages="siForm.errors.instruction"
                         variant="outlined" density="compact" rows="4" />
            </VCol>
            <VCol cols="12">
              <VCheckbox v-model="siForm.claimed_as_variation" density="compact"
                         :label="t('project_management.site_records.field_variation')"
                         :hint="t('project_management.site_records.variation_hint')" persistent-hint />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="siDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="siForm.processing"
                :disabled="!siForm.title || !siForm.instruction" @click="saveSi">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="testDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.site_records.log_test') }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="testForm.kind"
                       :items="options.test_kinds.map(k => ({ title: t(`project_management.site_records.test_${k}`), value: k }))"
                       :label="t('project_management.site_records.field_kind')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="testForm.sampled_on" type="date"
                          :label="t('project_management.site_records.field_sampled_on')"
                          :error-messages="testForm.errors.sampled_on" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="testForm.element" :label="t('project_management.site_records.field_element')"
                          :hint="t('project_management.site_records.element_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model.number="testForm.specified_value" type="number" step="0.001"
                          :label="t('project_management.site_records.field_specified')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model="testForm.unit" :label="t('project_management.site_records.field_unit')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="testForm.lab" :label="t('project_management.site_records.field_lab')"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="testDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="testForm.processing" @click="saveTest">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resultDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site_records.record_result') }}</VCardTitle>
          <VCardSubtitle>{{ resultingTest?.reference }} · {{ resultingTest?.element }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Said before it is saved: a failed test raises a non-conformance by itself, because a
               failed cube nobody acts on is the failure this record exists to prevent. -->
          <VAlert v-if="resultForm.result === 'fail'" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('project_management.site_records.fail_raises_ncr') }}
          </VAlert>
          <VRadioGroup v-model="resultForm.result" inline density="compact" class="mb-2">
            <VRadio :label="t('project_management.site_records.result_pass')" value="pass" />
            <VRadio :label="t('project_management.site_records.result_fail')" value="fail" />
          </VRadioGroup>
          <VTextField v-model.number="resultForm.result_value" type="number" step="0.001"
                      :label="t('project_management.site_records.field_result_value')"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="resultForm.result_on" type="date"
                      :label="t('project_management.site_records.field_result_on')"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="resultForm.lab" :label="t('project_management.site_records.field_lab')"
                      variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="resultDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="resultForm.processing" @click="saveResult">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="irDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site_records.request_inspection') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.site_records.inspection_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextField v-model="irForm.title" :label="t('project_management.site_records.field_title')"
                      :error-messages="irForm.errors.title" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="irForm.element" :label="t('project_management.site_records.field_element')"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="irForm.requested_for" type="datetime-local"
                      :label="t('project_management.site_records.field_requested_for')"
                      :hint="t('project_management.site_records.requested_for_hint')" persistent-hint
                      :error-messages="irForm.errors.requested_for" variant="outlined" density="compact" class="mb-3" />
          <PersonField v-model="irForm.inspector" :people="people" class="mb-3"
                       :label="t('project_management.site_records.field_inspector')"
                       :error-messages="irForm.errors.inspector" />
          <VAutocomplete v-model="irForm.task_id"
                         :items="tasks.map(x => ({ title: x.name, value: x.id }))"
                         :label="t('project_management.site_records.field_task')" clearable
                         variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="irDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="irForm.processing"
                :disabled="!irForm.title || !irForm.requested_for" @click="saveIr">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="irRecordDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site_records.record_outcome') }}</VCardTitle>
          <VCardSubtitle>{{ recordingIr?.number }} · {{ recordingIr?.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRadioGroup v-model="irRecordForm.status" inline density="compact" class="mb-2">
            <VRadio :label="t('project_management.site_records.ir_status_passed')" value="passed" />
            <VRadio :label="t('project_management.site_records.ir_status_failed')" value="failed" />
            <VRadio :label="t('project_management.site_records.ir_status_cancelled')" value="cancelled" />
          </VRadioGroup>
          <PersonField v-model="irRecordForm.inspector" :people="people" class="mb-3"
                       :label="t('project_management.site_records.field_inspector')"
                       :error-messages="irRecordForm.errors.inspector" />
          <VTextarea v-model="irRecordForm.outcome_note" :label="t('project_management.site_records.field_outcome')"
                     variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="irRecordDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="irRecordForm.processing" @click="saveIrRecord">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="equipDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.equipment.add') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.equipment.sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="7">
              <VTextField v-model="equipForm.equipment" :label="t('project_management.equipment.field_equipment')"
                          :error-messages="equipForm.errors.equipment" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="5">
              <!-- Scanned or typed. A code that matches nothing on file is still worth having. -->
              <VTextField v-model="equipForm.identifier"
                          :label="t('project_management.equipment.field_identifier')"
                          :hint="t('project_management.equipment.identifier_hint')" persistent-hint
                          prepend-inner-icon="tabler-qrcode"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model="equipForm.inspected_on" type="date"
                          :label="t('project_management.equipment.field_inspected_on')"
                          :error-messages="equipForm.errors.inspected_on"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="4">
              <VSelect v-model="equipForm.result"
                       :items="options.equipment_results.map(r => ({ title: t(`project_management.equipment.result_${r}`), value: r }))"
                       :label="t('project_management.equipment.field_result')"
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="equipForm.next_due" type="date"
                          :label="t('project_management.equipment.field_next_due')"
                          :error-messages="equipForm.errors.next_due"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="equipForm.defects" :label="t('project_management.equipment.field_defects')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="12">
              <PersonField v-model="equipForm.signed_by" :people="people"
                           :label="t('project_management.equipment.field_signed_by')"
                           :hint="t('project_management.equipment.signed_hint')"
                           :error-messages="equipForm.errors.signed_by" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="equipDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="equipForm.processing"
                :disabled="!equipForm.equipment || !equipForm.signed_by" @click="saveEquip">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="completionDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.site_records.certify') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.site_records.certify_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.site_records.certify_note') }}
          </VAlert>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="completionForm.kind"
                       :items="options.completion_kinds.map(k => ({ title: t(`project_management.site_records.completion_${k}`), value: k }))"
                       :label="t('project_management.site_records.field_kind')"
                       :error-messages="completionForm.errors.kind" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="completionForm.certified_on" type="date"
                          :label="t('project_management.site_records.field_certified_on')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol v-if="completionForm.kind === 'sectional'" cols="12">
              <VTextField v-model="completionForm.section" :label="t('project_management.site_records.field_section')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <PersonField v-model="completionForm.certified_by" :people="people"
                           :label="t('project_management.site_records.field_certified_by')"
                           :error-messages="completionForm.errors.certified_by" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model.number="completionForm.defects_period_days" type="number"
                          :label="t('project_management.site_records.field_defects_days')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol v-if="can.costs" cols="6" md="3">
              <VTextField v-model.number="completionForm.retention_released" type="number" step="0.01"
                          :label="t('project_management.site_records.field_retention_released')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="completionForm.outstanding_work"
                         :label="t('project_management.site_records.field_outstanding')"
                         :hint="t('project_management.site_records.outstanding_hint')" persistent-hint
                         variant="outlined" density="compact" rows="3" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="completionDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="completionForm.processing" @click="saveCompletion">
            {{ t('project_management.site_records.certify') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
