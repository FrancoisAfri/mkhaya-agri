<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useFitToViewport } from '@/composables/useFitToViewport'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Month = { year: number; month: number; key: string; label: string }
type Person = { id: number; label: string }

type Row = {
  no: number
  id: string
  project: string | null
  project_code: string | null
  project_manager_ids: number[]
  project_manager_label: string
  stage: string | null
  progress_percentage: string | null
  project_status: string | null
  project_status_notes: string | null
  challenges: string | null
  cashflow: Record<string, string>
  estimated_professional_fee?: string
  professional_fee_invoiced_to_date?: string
  professional_fee_outstanding?: string
}

const props = defineProps<{
  period: {
    id: string; name: string; label: string; meeting_date: string | null
    general_comment: string | null; status: string; issued_at: string | null
  }
  months: Month[]
  rows: Row[]
  stages: string[]
  statuses: string[]
  people: Person[]
  projects: Array<{ id: string; code: string; name: string }>
  summary: Record<string, any> | null
  can: { edit: boolean; issue: boolean; costs: boolean }
}>()

/**
 * The grid edits a local copy.
 *
 * Saving used to reload the page, which rebuilt this very wide table and threw the reader back to
 * the first column — after every single cell. On a six-month cash-flow you would scroll right,
 * type, get thrown to the start, scroll right again. So a cell writes to `rows` immediately, the
 * request goes out with `preserveState` so this component is not replaced, and only `summary` comes
 * back. The table never re-renders and the scroll position never moves.
 */
/**
 * A plain deep copy, via JSON rather than structuredClone.
 *
 * `props.rows` is a Vue reactive proxy, and structuredClone throws DataCloneError on a Proxy —
 * which took the whole page blank rather than failing visibly. JSON reads through the proxy, and
 * these rows are plain data: strings, numbers and a flat object of month amounts.
 */
function clone(rows: Row[]): Row[] {
  return JSON.parse(JSON.stringify(rows)) as Row[]
}

const rows = ref<Row[]>(clone(props.rows))

const { el: pageEl, height: pageHeight } = useFitToViewport()

/*
 * ── Filling the report one project at a time ────────────────────────────────
 *
 * The grid is fourteen columns wide because the document it produces is. That is right for reading
 * it and wrong for writing it: entering a project's month means scrolling right through managers,
 * stage, progress, two paragraphs and five money columns, with the project name the only thing
 * still on screen to tell you whose figures you are typing.
 *
 * So the grid stays as the overview and this is the way in. Same row object and same save
 * functions — it is the same data written the same way, laid out down the page instead of across
 * it, which is the shape a form should have been all along.
 */
const editingRow = ref<Row | null>(null)

/** Keeping the drawer on the live row means a save from either place shows in both. */
const openRow = (row: Row) => { editingRow.value = row }

const editingIndex = computed(() =>
  editingRow.value ? rows.value.findIndex(r => r.id === editingRow.value!.id) : -1)

/** Straight to the next project without closing and hunting for the row below. */
function step(by: number) {
  const next = rows.value[editingIndex.value + by]
  if (next) editingRow.value = next
}

// Only when the server genuinely sends a different set — adding a project, or issuing the report.
watch(() => props.rows.length, () => { rows.value = clone(props.rows) })

const savingRow = ref<string | null>(null)
const savedAt = ref<string | null>(null)

/**
 * A compact figure for the summary tiles.
 *
 * "ZAR 1 700 000.00" does not fit a tile and was being clipped mid-number, which reads as a wrong
 * figure rather than a narrow box. The tiles show "R1.7m"; the exact amount is in the table below,
 * in the tooltip, and in the export — the tile's job is the order of magnitude.
 */
function compactMoney(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  const value = Number(amount)
  if (Number.isNaN(value)) return String(amount)

  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: 'ZAR',
      notation: 'compact', maximumFractionDigits: 1,
    }).format(value)
  } catch {
    return money(amount)
  }
}

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

/** Save one field. The grid keeps its DOM; only the totals come back. */
function save(row: Row, payload: Record<string, unknown>) {
  if (!props.can.edit) return

  savingRow.value = row.id
  router.patch(route('project-management.reporting.rows.update', [props.period.id, row.id]), payload, {
    preserveScroll: true,
    preserveState: true,
    only: ['summary'],
    onFinish: () => {
      savingRow.value = null
      savedAt.value = new Date().toLocaleTimeString()
    },
  })
}

function saveCashflow(row: Row, month: Month, value: string) {
  row.cashflow[month.key] = value
  if (!props.can.edit) return

  savingRow.value = row.id
  router.patch(
    route('project-management.reporting.cashflow.update', [props.period.id, row.id]),
    { year: month.year, month: month.month, amount: value === '' ? 0 : Number(value) },
    {
      preserveScroll: true,
      preserveState: true,
      only: ['summary'],
      onFinish: () => {
        savingRow.value = null
        savedAt.value = new Date().toLocaleTimeString()
      },
    },
  )
}

/** The managers as the client writes them — "Neo / Refiloe". */
function managerLabel(ids: number[]): string {
  return ids
    .map(id => props.people.find(p => p.id === id)?.label)
    .filter(Boolean)
    .join(' / ')
}

function saveManagers(row: Row, ids: number[]) {
  row.project_manager_ids = ids
  row.project_manager_label = managerLabel(ids)
  save(row, { project_manager_ids: ids, project_manager_label: row.project_manager_label })
}

function pullInvoiced(row: Row) {
  router.post(route('project-management.reporting.rows.pull_invoiced', [props.period.id, row.id]), {}, {
    preserveScroll: true,
    // The figures change, so the rows do have to come back here.
    onSuccess: () => { rows.value = clone(props.rows) },
  })
}

// ── The report's own fields ──────────────────────────────────────────────────

const periodForm = useForm({
  meeting_date: props.period.meeting_date ?? '',
  general_comment: props.period.general_comment ?? '',
})

function savePeriod() {
  periodForm
    .transform(d => ({ ...d, meeting_date: d.meeting_date || null, general_comment: d.general_comment || null }))
    .patch(route('project-management.reporting.update', props.period.id), {
      preserveScroll: true,
      preserveState: true,
      only: ['summary'],
    })
}

const addForm = useForm({ project: null as string | null })

function addProject() {
  if (!addForm.project) return
  addForm.post(route('project-management.reporting.projects.store', props.period.id), {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
  })
}

/** A plain navigation, not XHR — the browser has to receive it as a download. */
function download(format: 'pdf' | 'xlsx') {
  window.location.href = route('project-management.reporting.download', [props.period.id, format])
}

const issuing = ref(false)

function issue() {
  issuing.value = true
  router.post(route('project-management.reporting.issue', props.period.id), {}, {
    preserveScroll: true,
    onFinish: () => (issuing.value = false),
  })
}

const tiles = computed(() => props.summary ? [
  { key: 'summary_projects', value: String(props.summary.projects), exact: '', icon: 'tabler-folders', colour: 'primary' },
  { key: 'summary_progress', value: props.summary.average_progress === null ? '—' : props.summary.average_progress + '%', exact: '', icon: 'tabler-progress', colour: 'info' },
  { key: 'summary_estimated', value: compactMoney(props.summary.estimated_fee), exact: money(props.summary.estimated_fee), icon: 'tabler-file-percent', colour: 'teal' },
  { key: 'summary_invoiced', value: compactMoney(props.summary.invoiced), exact: money(props.summary.invoiced), icon: 'tabler-receipt', colour: 'success' },
  { key: 'summary_outstanding', value: compactMoney(props.summary.outstanding), exact: money(props.summary.outstanding), icon: 'tabler-clock-dollar', colour: 'warning' },
] : [])
</script>

<template>
  <!--
    The page is the height of the screen and the report scrolls inside it.

    A practice with thirty projects and fourteen columns had a page metres tall in both directions:
    scroll down to reach a project and the column headings are gone, scroll right to reach the
    cashflow and the project name goes with them. The headings and the first two columns stick, and
    the page itself does not move.
  -->
  <div ref="pageEl" class="pa-4 d-flex flex-column report-page" :style="{ blockSize: pageHeight }">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ props.period.name }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          <!-- Says when it last saved, so nobody presses anything to be sure. -->
          <span v-if="savedAt">{{ t('project_management.monthly_reporting.saved_at', { time: savedAt }) }}</span>
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap align-center">
        <VProgressCircular v-if="savingRow" indeterminate size="20" width="2" color="primary" />
        <VBtn variant="tonal" prepend-icon="tabler-arrow-left" @click="router.get(route('project-management.reporting.index'))">
          {{ t('project_management.monthly_reporting.title') }}
        </VBtn>
        <VMenu>
          <template #activator="{ props: menu }">
            <VBtn v-bind="menu" variant="tonal" prepend-icon="tabler-download" append-icon="tabler-chevron-down">
              {{ t('project_management.monthly_reporting.generate') }}
            </VBtn>
          </template>
          <VList density="compact">
            <VListItem prepend-icon="tabler-file-type-pdf" @click="download('pdf')">
              <VListItemTitle>{{ t('project_management.monthly_reporting.export_pdf') }}</VListItemTitle>
            </VListItem>
            <VListItem prepend-icon="tabler-file-spreadsheet" @click="download('xlsx')">
              <VListItemTitle>{{ t('project_management.monthly_reporting.export_excel') }}</VListItemTitle>
            </VListItem>
            <VListItem prepend-icon="tabler-printer" @click="download('pdf')">
              <!-- Printing is the PDF: a browser print of a table this wide is unusable. -->
              <VListItemTitle>{{ t('project_management.monthly_reporting.print') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VMenu>
        <VBtn v-if="props.can.issue" color="primary" prepend-icon="tabler-file-check" :loading="issuing" @click="issue">
          {{ t('project_management.monthly_reporting.issue') }}
        </VBtn>
      </div>
    </div>

    <VAlert v-if="props.period.status === 'issued'" type="info" variant="tonal" density="comfortable" class="mb-4">
      {{ t('project_management.monthly_reporting.issued_note', { at: props.period.issued_at ?? '' }) }}
    </VAlert>

    <!-- ── The report's own fields ────────────────────────────────────────── -->
    <VCard border class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="3">
            <VTextField
              v-model="periodForm.meeting_date" type="date" :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.meeting_date')"
              variant="outlined" density="compact"
              @change="savePeriod"
            />
          </VCol>
          <VCol cols="12" md="9">
            <VTextarea
              v-model="periodForm.general_comment" rows="2" auto-grow :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.general_comment')"
              :hint="t('project_management.monthly_reporting.general_comment_hint')" persistent-hint
              variant="outlined" density="compact"
              @change="savePeriod"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ── Dashboard, from the fields the document actually has ───────────── -->
    <div v-if="tiles.length" class="summary-strip mb-4">
      <VCard v-for="tile in tiles" :key="tile.key" border>
        <VCardText class="d-flex align-center gap-3">
          <VAvatar :color="tile.colour" variant="tonal" rounded size="42">
            <VIcon :icon="tile.icon" />
          </VAvatar>
          <div style="min-inline-size: 0">
            <!-- The exact amount on hover; the tile itself stays legible. -->
            <div class="text-h6 text-no-wrap tile-value" :title="tile.exact">{{ tile.value }}</div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ t('project_management.monthly_reporting.' + tile.key) }}
            </div>
          </div>
        </VCardText>
      </VCard>
    </div>

    <!-- ── The report ─────────────────────────────────────────────────────── -->
    <VCard border class="d-flex flex-column report-card">
      <div class="table-scroll">
        <VTable v-if="rows.length" density="compact">
          <thead>
            <tr>
              <th class="sticky-col text-no-wrap">{{ t('project_management.monthly_reporting.col_no') }}</th>
              <th class="sticky-col-2 text-no-wrap">{{ t('project_management.monthly_reporting.col_project') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_manager') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_stage') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_progress') }}</th>
              <th style="min-inline-size: 20rem">{{ t('project_management.monthly_reporting.col_status') }}</th>
              <th style="min-inline-size: 20rem">{{ t('project_management.monthly_reporting.col_challenges') }}</th>
              <template v-if="props.can.costs">
                <th class="text-end text-no-wrap">{{ t('project_management.monthly_reporting.col_estimated_fee') }}</th>
                <th class="text-end text-no-wrap">{{ t('project_management.monthly_reporting.col_invoiced') }}</th>
                <th class="text-end text-no-wrap">{{ t('project_management.monthly_reporting.col_outstanding') }}</th>
                <!-- Dynamic: these follow the reporting month and are never fixed columns. -->
                <th v-for="m in props.months" :key="m.key" class="text-end text-no-wrap">{{ m.label }}</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="sticky-col text-no-wrap">{{ row.no }}</td>
              <td class="sticky-col-2">
                <div class="d-flex align-center gap-1">
                  <div style="min-inline-size: 0">
                    <div class="text-no-wrap">{{ row.project }}</div>
                    <div class="text-caption text-medium-emphasis">{{ row.project_code }}</div>
                  </div>
                  <!-- On the frozen column, so it is reachable however far right you have scrolled. -->
                  <VBtn
                    size="x-small" variant="text" icon="tabler-pencil"
                    :title="t('project_management.monthly_reporting.open_row')"
                    :aria-label="t('project_management.monthly_reporting.open_row')"
                    @click="openRow(row)"
                  />
                </div>
              </td>

              <!-- Managers: a real list of people, and a project can have more than one. -->
              <td style="min-inline-size: 15rem">
                <VAutocomplete
                  v-if="props.can.edit"
                  :model-value="row.project_manager_ids"
                  :items="props.people.map(p => ({ title: p.label, value: p.id }))"
                  multiple chips closable-chips
                  density="compact" variant="outlined" hide-details
                  :placeholder="t('project_management.monthly_reporting.col_manager')"
                  @update:model-value="(v: number[]) => saveManagers(row, v)"
                />
                <span v-else>{{ row.project_manager_label || '—' }}</span>
              </td>

              <td style="min-inline-size: 14rem">
                <VCombobox
                  v-if="props.can.edit"
                  v-model="row.stage" :items="props.stages"
                  density="compact" variant="outlined" hide-details
                  @update:model-value="(v: string) => save(row, { stage: v })"
                />
                <span v-else>{{ row.stage || '—' }}</span>
              </td>

              <td style="min-inline-size: 7rem">
                <VTextField
                  v-if="props.can.edit"
                  v-model="row.progress_percentage" suffix="%" inputmode="decimal"
                  density="compact" variant="outlined" hide-details
                  @change="save(row, { progress_percentage: row.progress_percentage })"
                />
                <span v-else>{{ row.progress_percentage ?? '—' }}</span>
              </td>

              <!-- Status: the type, and the running commentary beside it. -->
              <td>
                <VCombobox
                  v-if="props.can.edit"
                  v-model="row.project_status" :items="props.statuses"
                  density="compact" variant="outlined" hide-details class="mb-2"
                  :label="t('project_management.monthly_reporting.status_type')"
                  @update:model-value="(v: string) => save(row, { project_status: v })"
                />
                <VTextarea
                  v-if="props.can.edit"
                  v-model="row.project_status_notes" rows="3" auto-grow
                  density="compact" variant="outlined" hide-details
                  :placeholder="t('project_management.monthly_reporting.status_notes_hint')"
                  @change="save(row, { project_status_notes: row.project_status_notes })"
                />
                <template v-else>
                  <div>{{ row.project_status || '—' }}</div>
                  <div style="white-space: pre-wrap" class="text-caption">{{ row.project_status_notes }}</div>
                </template>
              </td>

              <td>
                <VTextarea
                  v-if="props.can.edit"
                  v-model="row.challenges" rows="3" auto-grow
                  density="compact" variant="outlined" hide-details
                  :placeholder="t('project_management.monthly_reporting.challenges_hint')"
                  @change="save(row, { challenges: row.challenges })"
                />
                <!-- Line breaks and bullets are preserved exactly as typed. -->
                <span v-else style="white-space: pre-wrap">{{ row.challenges || '—' }}</span>
              </td>

              <template v-if="props.can.costs">
                <td class="text-end text-no-wrap" style="min-inline-size: 9rem">
                  <VTextField
                    v-if="props.can.edit"
                    v-model="row.estimated_professional_fee" inputmode="decimal"
                    density="compact" variant="outlined" hide-details
                    @change="save(row, { estimated_professional_fee: row.estimated_professional_fee })"
                  />
                  <span v-else>{{ money(row.estimated_professional_fee) }}</span>
                </td>
                <td class="text-end text-no-wrap" style="min-inline-size: 11rem">
                  <div class="d-flex align-center gap-1">
                    <VTextField
                      v-if="props.can.edit"
                      v-model="row.professional_fee_invoiced_to_date" inputmode="decimal"
                      density="compact" variant="outlined" hide-details
                      @change="save(row, { professional_fee_invoiced_to_date: row.professional_fee_invoiced_to_date })"
                    />
                    <span v-else>{{ money(row.professional_fee_invoiced_to_date) }}</span>
                    <!-- Asked for, never automatic: the practice types this figure today. -->
                    <VBtn
                      v-if="props.can.edit"
                      size="x-small" variant="text" icon="tabler-download"
                      :title="t('project_management.monthly_reporting.pull_invoiced_hint')"
                      @click="pullInvoiced(row)"
                    />
                  </div>
                </td>
                <td class="text-end text-no-wrap" style="min-inline-size: 9rem">
                  <VTextField
                    v-if="props.can.edit"
                    v-model="row.professional_fee_outstanding" inputmode="decimal"
                    density="compact" variant="outlined" hide-details
                    @change="save(row, { professional_fee_outstanding: row.professional_fee_outstanding })"
                  />
                  <span v-else>{{ money(row.professional_fee_outstanding) }}</span>
                </td>
                <td v-for="m in props.months" :key="m.key" class="text-end text-no-wrap" style="min-inline-size: 8rem">
                  <VTextField
                    v-if="props.can.edit"
                    :model-value="row.cashflow[m.key] ?? ''" inputmode="decimal"
                    density="compact" variant="outlined" hide-details
                    @change="(e: any) => saveCashflow(row, m, e.target.value)"
                  />
                  <span v-else>{{ money(row.cashflow[m.key]) }}</span>
                </td>
              </template>
            </tr>
          </tbody>
          <tfoot v-if="props.summary">
            <tr>
              <th class="sticky-col" />
              <th class="sticky-col-2 text-no-wrap">
                {{ t('project_management.monthly_reporting.cashflow_heading') }}
              </th>
              <th :colspan="props.can.costs ? 8 : 5" />
              <th v-for="m in props.summary.cashflow" :key="m.key" class="text-end text-no-wrap">
                {{ money(m.amount) }}
              </th>
            </tr>
          </tfoot>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('project_management.monthly_reporting.no_reports') }}
        </VCardText>
      </div>

      <VCardText v-if="props.can.edit && props.projects.length" class="d-flex gap-3 flex-wrap">
        <VAutocomplete
          v-model="addForm.project"
          :items="props.projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
          :label="t('project_management.monthly_reporting.add_project')"
          density="compact" variant="outlined" hide-details
          style="max-width: 340px"
        />
        <VBtn variant="tonal" :loading="addForm.processing" :disabled="!addForm.project" @click="addProject">
          {{ t('project_management.actions.add') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!--
      One project's month, down the page.

      Every field here is bound to the same row and saved by the same functions as the grid; this is
      a different shape for the same work, not a second way of doing it.
    -->
    <VNavigationDrawer
      :model-value="editingRow !== null" location="end" temporary width="560"
      @update:model-value="v => { if (!v) editingRow = null }"
    >
      <template v-if="editingRow">
        <div class="pa-4 d-flex align-center justify-space-between">
          <div style="min-inline-size: 0">
            <div class="text-caption text-medium-emphasis">{{ editingRow.project_code }}</div>
            <h6 class="text-h6 text-truncate">{{ editingRow.project }}</h6>
          </div>
          <VBtn icon="tabler-x" variant="text" size="small"
                :aria-label="t('project_management.actions.close')" @click="editingRow = null" />
        </div>
        <VDivider />

        <div class="pa-4">
          <VAutocomplete
            :model-value="editingRow.project_manager_ids"
            :items="props.people.map(p => ({ title: p.label, value: p.id }))"
            :label="t('project_management.monthly_reporting.col_manager')"
            :disabled="!props.can.edit"
            multiple chips closable-chips variant="outlined" density="compact" class="mb-3"
            @update:model-value="(v: number[]) => saveManagers(editingRow!, v)"
          />

          <div class="d-flex gap-2 mb-3">
            <VCombobox
              v-model="editingRow.stage" :items="props.stages" :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.col_stage')"
              variant="outlined" density="compact" hide-details
              @update:model-value="(v: string) => save(editingRow!, { stage: v })"
            />
            <VTextField
              v-model="editingRow.progress_percentage" suffix="%" inputmode="decimal"
              :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.col_progress')"
              variant="outlined" density="compact" hide-details style="max-width: 9rem"
              @change="save(editingRow!, { progress_percentage: editingRow!.progress_percentage })"
            />
          </div>

          <VCombobox
            v-model="editingRow.project_status" :items="props.statuses" :disabled="!props.can.edit"
            :label="t('project_management.monthly_reporting.status_type')"
            variant="outlined" density="compact" class="mb-3"
            @update:model-value="(v: string) => save(editingRow!, { project_status: v })"
          />

          <!-- Room to write: this is the paragraph the report is actually read for. -->
          <VTextarea
            v-model="editingRow.project_status_notes" rows="5" auto-grow :disabled="!props.can.edit"
            :label="t('project_management.monthly_reporting.col_status')"
            :placeholder="t('project_management.monthly_reporting.status_notes_hint')"
            variant="outlined" density="compact" class="mb-3"
            @change="save(editingRow!, { project_status_notes: editingRow!.project_status_notes })"
          />

          <VTextarea
            v-model="editingRow.challenges" rows="5" auto-grow :disabled="!props.can.edit"
            :label="t('project_management.monthly_reporting.col_challenges')"
            :placeholder="t('project_management.monthly_reporting.challenges_hint')"
            variant="outlined" density="compact" class="mb-3"
            @change="save(editingRow!, { challenges: editingRow!.challenges })"
          />

          <template v-if="props.can.costs">
            <VDivider class="mb-3" />
            <div class="text-body-2 font-weight-medium mb-2">
              {{ t('project_management.monthly_reporting.col_estimated_fee') }}
            </div>

            <VTextField
              v-model="editingRow.estimated_professional_fee" inputmode="decimal"
              :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.col_estimated_fee')"
              variant="outlined" density="compact" class="mb-3"
              @change="save(editingRow!, { estimated_professional_fee: editingRow!.estimated_professional_fee })"
            />

            <div class="d-flex align-center gap-1 mb-3">
              <VTextField
                v-model="editingRow.professional_fee_invoiced_to_date" inputmode="decimal"
                :disabled="!props.can.edit"
                :label="t('project_management.monthly_reporting.col_invoiced')"
                variant="outlined" density="compact" hide-details
                @change="save(editingRow!, { professional_fee_invoiced_to_date: editingRow!.professional_fee_invoiced_to_date })"
              />
              <!-- Asked for, never automatic: the practice types this figure today. -->
              <VBtn
                v-if="props.can.edit" size="small" variant="text" icon="tabler-download"
                :title="t('project_management.monthly_reporting.pull_invoiced_hint')"
                @click="pullInvoiced(editingRow!)"
              />
            </div>

            <VTextField
              v-model="editingRow.professional_fee_outstanding" inputmode="decimal"
              :disabled="!props.can.edit"
              :label="t('project_management.monthly_reporting.col_outstanding')"
              variant="outlined" density="compact" class="mb-3"
              @change="save(editingRow!, { professional_fee_outstanding: editingRow!.professional_fee_outstanding })"
            />

            <div class="text-body-2 font-weight-medium mb-2">
              {{ t('project_management.monthly_reporting.cashflow_heading') }}
            </div>
            <VTextField
              v-for="m in props.months" :key="m.key"
              :model-value="editingRow.cashflow[m.key] ?? ''" inputmode="decimal"
              :disabled="!props.can.edit" :label="m.label"
              variant="outlined" density="compact" class="mb-2"
              @change="(e: any) => saveCashflow(editingRow!, m, e.target.value)"
            />
          </template>
        </div>

        <VDivider />
        <!-- Straight on to the next project; the report is filled in one pass, not thirty. -->
        <div class="pa-3 d-flex align-center justify-space-between">
          <VBtn
            variant="text" size="small" prepend-icon="tabler-chevron-left"
            :disabled="editingIndex <= 0" @click="step(-1)"
          >{{ t('project_management.monthly_reporting.previous_project') }}</VBtn>
          <span class="text-caption text-medium-emphasis">{{ editingIndex + 1 }} / {{ rows.length }}</span>
          <VBtn
            variant="text" size="small" append-icon="tabler-chevron-right"
            :disabled="editingIndex >= rows.length - 1" @click="step(1)"
          >{{ t('project_management.monthly_reporting.next_project') }}</VBtn>
        </div>
      </template>
    </VNavigationDrawer>
  </div>
</template>

<style scoped>
/*
 * Height comes from useFitToViewport, so nothing here guesses at the shell's chrome.
 *
 * `overflow: auto` and not `hidden`. Hidden was the bug: everything above the table — the heading,
 * the buttons, the meeting-date card, five tiles of money — comes to roughly four hundred pixels,
 * and on a laptop window that left the table almost nothing and simply cut the rest off. Content
 * that has been clipped is gone with no way to reach it, which is worse than the scrolling this was
 * meant to remove.
 *
 * So: the page still prefers to be exactly the screen, the table still scrolls inside it, and when
 * the window is genuinely too short the page scrolls a little rather than hiding a field somebody
 * needs to fill in.
 */
.report-page { overflow: auto; }

.report-card {
  flex: 1 1 auto;
  /*
   * A floor, so the table is always worth looking at. Without it a short window shrinks the table
   * towards nothing while the tiles above keep their full height — the reverse of what matters
   * here, since the tiles are a summary of the thing the table is for.
   */
  min-block-size: 22rem;
}

/*
 * The report is wider and longer than any screen; it scrolls inside its card, not the page.
 * `min-block-size: 0` is the line that makes that true — without it the table sets the card's
 * height and the page grows to fit it again.
 */
.table-scroll {
  overflow: auto;
  flex: 1 1 auto;
  min-block-size: 0;
}

/* Headings stay while the rows move; scrolling to project thirty is useless without them. */
.table-scroll :deep(thead th) {
  position: sticky;
  inset-block-start: 0;
  z-index: 2;
  background: rgb(var(--v-theme-surface));
}

/* The two frozen columns have to win over the frozen header where they cross it. */
.table-scroll :deep(thead th.sticky-col),
.table-scroll :deep(thead th.sticky-col-2) { z-index: 3; }

/* The number and the project name stay put — without them a scrolled row is anonymous. */
.sticky-col,
.sticky-col-2 {
  position: sticky;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
}
.sticky-col { inset-inline-start: 0; }
.sticky-col-2 { inset-inline-start: 3rem; }

.summary-strip {
  display: grid;
  gap: 1rem;
  /* Wider than before: five tiles of money need the room, and a clipped figure reads as a wrong
     figure rather than a narrow box. */
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
}

.tile-value {
  font-variant-numeric: tabular-nums;
}
</style>
