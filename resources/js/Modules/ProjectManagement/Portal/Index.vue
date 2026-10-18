<script setup lang="ts">
import { computed, ref } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PortalShell from './PortalShell.vue'

/**
 * The portal stands alone: no company sidebar, no module grid, no CEO message. app.ts applies
 * MainLayout to anything that does not declare a layout, so this opts out explicitly.
 */
defineOptions({ layout: null })

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Proj = {
  id: string; code: string; name: string; role: string; status: string; stage: string
  planned_end: string | null; progress: number | null
  milestones: Array<{ name: string; due: string | null; status: string; done: boolean }>
  requests: Array<{ id: string; code: string | null; name: string; status: string; raised: string | null }>
  documents: Array<{ id: string; name: string; size: number | null }>
  completions: Array<{ kind: string; date: string; defects_ends: string | null }>
  changes: Array<{ code: string; title: string; status: string; days: number | null }>
  work: Array<{ id: string; name: string; due: string | null; status: string }>
  contracts: Array<{
    id: string; number: string; title: string; status: string
    version: number; awaiting_me: boolean
  }>
  snags: Array<{ reference: string; title: string; severity: string; due: string | null }>
  instructions: Array<{ number: string; title: string; issued_on: string; variation: boolean }>
  claims: Array<{ number: string; status: string; period: string | null; net: number | null }>
  invoices: Array<{
    id: string; reference: string | null; invoice_date: string | null
    period_start: string | null; period_end: string | null; description: string | null
    subtotal: string; vat_amount: string; total: string; currency: string
    status: string; submitted_at: string | null; decision_reason: string | null
    has_document: boolean; file_name: string | null
    lines: Array<{ id: string; description: string; quantity: string; rate: string; amount: string }>
  }>
  my_hours: number | null
  my_rating: { score: number; comment: string | null } | null
  member_id: string
}

const props = defineProps<{
  me: { name: string; role: string | null } | null
  company: string | null
  projects: Proj[]
  awaiting: Array<{ id: string; label: string; subject: string; project: string | null; amount: number | null; currency: string | null }>
  can: { upload: boolean }
}>()

const selected = ref<string>(props.projects[0]?.id ?? '')
const current = computed(() => props.projects.find(p => p.id === selected.value) ?? null)
const isClient = computed(() => current.value?.role === 'client')

/*
 * ── The portfolio, before any one project ───────────────────────────────────
 *
 * A client with one job wants that job. A client with five wants to know which of the five needs
 * them this week, and the old page could not answer that: it opened on whichever project happened
 * to be first and every other one was behind a dropdown. Five projects meant five deliberate
 * selections to find the one thing that was late.
 *
 * So the landing is the whole portfolio and a project is something you open. Everything below is
 * computed from the payload the page already receives — no extra request, and no second version of
 * a figure that could disagree with the one inside the project.
 */
/*
 * The overview first, whatever the count.
 *
 * This opened straight into the project when there was only one, on the reasoning that a portfolio
 * view of one is a page of charts about a single bar. That was wrong about what the page is for: a
 * client opening the portal wants to see where the work stands before they are put in front of a
 * form, and "one project today" is not a promise about tomorrow — the layout should not change
 * shape underneath somebody the week a second job starts.
 */
const view = ref<'overview' | 'project'>('overview')

function openProject(id: string) {
  selected.value = id
  view.value = 'project'
  // Opening a project from halfway down the overview should not start halfway down the project.
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const today = new Date().toISOString().slice(0, 10)

/** A milestone that has not happened and whose date has passed. */
const isLate = (m: { due: string | null; done: boolean }) => !m.done && !!m.due && m.due < today

type Line = { project: Proj; label: string; due: string | null; late: boolean }

/**
 * Everything with a date on it, across every project, soonest first.
 *
 * The point of a portfolio view is that the next thing due is the next thing due — whichever job it
 * belongs to. Sorted by date rather than grouped by project, because grouping puts the reader back
 * to comparing five lists in their head.
 */
const timeline = computed<Line[]>(() =>
  props.projects
    .flatMap(project => (project.milestones ?? [])
      .filter(m => !m.done && m.due)
      .map(m => ({ project, label: m.name, due: m.due, late: isLate(m) })))
    .sort((a, b) => (a.due ?? '').localeCompare(b.due ?? ''))
    .slice(0, 12))

/** What is late, what is open, what is waiting — the three questions worth a number. */
const portfolio = computed(() => {
  const projects = props.projects

  const milestones = projects.flatMap(p => p.milestones ?? [])
  const requests = projects.flatMap(p => p.requests ?? [])

  return {
    projects: projects.length,
    // Averaged over the projects that report one, not over all of them: counting a project with no
    // figure as zero drags the number down and says the portfolio is behind when it is unmeasured.
    progress: (() => {
      const known = projects.filter(p => p.progress !== null)

      return known.length
        ? Math.round(known.reduce((n, p) => n + (p.progress ?? 0), 0) / known.length)
        : null
    })(),
    milestones_done: milestones.filter(m => m.done).length,
    milestones_total: milestones.length,
    late: milestones.filter(isLate).length,
    open_requests: requests.filter(r => !['done', 'cancelled', 'closed'].includes(r.status)).length,
    awaiting_me: projects.flatMap(p => p.contracts ?? []).filter(c => c.awaiting_me).length,
    snags: projects.flatMap(p => p.snags ?? []).length,
  }
})

// ── The charts ───────────────────────────────────────────────────────────────

/*
 * Horizontal bars, one per project. A donut of "share of progress" would be meaningless — progress
 * is not a quantity that divides — and five bars against a common axis is the comparison somebody
 * actually makes: which of these is behind the others.
 */
const progressOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true, barHeight: '60%' } },
  colors: props.projects.map(p => ((p.progress ?? 0) >= 100 ? '#43a047' : '#1e88e5')),
  xaxis: {
    categories: props.projects.map(p => p.code),
    max: 100,
    labels: { formatter: (v: string) => `${Math.round(Number(v))}%` },
  },
  legend: { show: false },
  dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
  tooltip: {
    // The code is on the axis; the name is what the reader recognises.
    y: { title: { formatter: (_: string, o: any) => props.projects[o.dataPointIndex]?.name ?? '' } },
  },
}))

const progressSeries = computed(() => [{
  name: t('project_management.portal.progress'),
  data: props.projects.map(p => Math.round(p.progress ?? 0)),
}])

/** How the portfolio divides by stage — the one question a donut answers well. */
const stageMix = computed(() => {
  const counts = new Map<string, number>()

  for (const project of props.projects) {
    const key = project.stage || 'unknown'
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  return [...counts]
})

const stageOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: stageMix.value.map(([stage]) => t(`project_management.stage.${stage}`, stage)),
  colors: ['#5e35b1', '#1e88e5', '#00897b', '#43a047', '#fb8c00', '#8e24aa'],
  legend: { position: 'bottom' },
  dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
  plotOptions: {
    pie: {
      donut: {
        labels: {
          show: true,
          total: { show: true, label: t('project_management.portal.projects'), formatter: () => String(props.projects.length) },
        },
      },
    },
  },
}))

const stageSeries = computed(() => stageMix.value.map(([, count]) => count))

/**
 * Milestones delivered against milestones outstanding, per project.
 *
 * Stacked rather than two charts: the pair only means something together — five done out of six is
 * a different project from five done out of forty, and two charts side by side make the reader do
 * that arithmetic themselves.
 */
const deliveryOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', stacked: true, toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } },
  colors: ['#43a047', '#e0e0e0', '#e53935'],
  xaxis: { categories: props.projects.map(p => p.code) },
  legend: { position: 'top', horizontalAlign: 'left' },
  dataLabels: { enabled: false },
}))

const deliverySeries = computed(() => [
  {
    name: t('project_management.portal.mile_done'),
    data: props.projects.map(p => (p.milestones ?? []).filter(m => m.done).length),
  },
  {
    name: t('project_management.portal.mile_open'),
    data: props.projects.map(p => (p.milestones ?? []).filter(m => !m.done && !isLate(m)).length),
  },
  {
    name: t('project_management.portal.mile_late'),
    data: props.projects.map(p => (p.milestones ?? []).filter(isLate).length),
  },
])

/**
 * A client asking for something.
 *
 * Without this the portal is a noticeboard: you can watch the work and rate the month, and every
 * actual request goes by email or phone and lands nowhere the project can see it.
 *
 * Only a title and a description are asked for. What a client wants is not the same as what the
 * team has committed to, so no date and no assignee — putting either here would write a promise
 * onto the schedule that nobody made.
 */
const requestForm = useForm({ name: '', description: '' })

function raiseRequest() {
  if (!current.value || !requestForm.name.trim()) return

  requestForm.post(route('project-management.portal.tasks.store', current.value.id), {
    preserveScroll: true,
    onSuccess: () => requestForm.reset(),
  })
}

function humanSize(bytes: number | null) {
  if (!bytes) return ''
  return bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function severityColour(s: string) {
  return s === 'critical' ? 'error' : s === 'major' ? 'warning' : 'default'
}

// ── Uploading ───────────────────────────────────────────────────────────────
const uploadForm = useForm<{ file: File | null }>({ file: null })

function upload() {
  if (!uploadForm.file || !current.value) return
  uploadForm.post(route('project-management.portal.upload', current.value.id), {
    preserveScroll: true, forceFormData: true, onSuccess: () => uploadForm.reset(),
  })
}

// ── Booking hours (contractors) ─────────────────────────────────────────────
const timeDialog = ref(false)
const timeForm = useForm({
  task_id: '', worked_on: new Date().toISOString().slice(0, 10), hours: null as number | null, note: '',
})

function saveTime() {
  if (!current.value) return
  timeForm.post(route('project-management.portal.time', current.value.id), {
    preserveScroll: true, onSuccess: () => { timeDialog.value = false; timeForm.reset() },
  })
}

// ── Rating the month (clients) ──────────────────────────────────────────────
const ratingForm = useForm({ score: 0, comment: '' })

function openRating() {
  ratingForm.score = current.value?.my_rating?.score ?? 0
  ratingForm.comment = current.value?.my_rating?.comment ?? ''
  ratingDialog.value = true
}

const ratingDialog = ref(false)

function saveRating() {
  if (!current.value) return
  ratingForm.post(route('project-management.portal.rate', current.value.id), {
    preserveScroll: true, onSuccess: () => { ratingDialog.value = false },
  })
}

function openApproval() {
  router.get(route('project-management.approvals.index'))
}
// ── My contracts ─────────────────────────────────────────────────────────────
const CONTRACT_COLOUR: Record<string, string> = {
  draft: 'grey', in_review: 'info', approved: 'primary', out_for_signature: 'warning',
  signed: 'success', rejected: 'error', declined: 'error', cancelled: 'grey',
}

function openContract(id: string) {
  router.visit(route('project-management.contracts.show', id))
}

// ── My invoices ──────────────────────────────────────────────────────────────
//
// A contractor prepares an invoice here and sends it. Once sent it is read-only: it is a document
// the practice has received, and one that could still change underneath an approval would make
// the approval meaningless.

const invoiceDialog = ref(false)
const invoiceForm = useForm({
  reference: '', invoice_date: '', period_start: '', period_end: '', description: '', vat_rate: '',
})

function openInvoice() {
  invoiceForm.reset()
  invoiceDialog.value = true
}

function createInvoice() {
  if (!current.value) return
  invoiceForm
    .transform(data => ({ ...data, vat_rate: data.vat_rate === '' ? null : Number(data.vat_rate) / 100 }))
    .post(route('project-management.portal.invoices.store', current.value.id), {
      preserveScroll: true,
      onSuccess: () => { invoiceDialog.value = false },
    })
}

const lineDialog = ref(false)
const lineInvoiceId = ref<string | null>(null)
const lineForm = useForm({ description: '', quantity: '1', rate: '' })

function openLine(invoiceId: string) {
  lineInvoiceId.value = invoiceId
  lineForm.reset()
  lineForm.quantity = '1'
  lineDialog.value = true
}

function addLine() {
  if (!current.value || !lineInvoiceId.value) return
  lineForm.post(
    route('project-management.portal.invoices.lines.store', [current.value.id, lineInvoiceId.value]),
    { preserveScroll: true, onSuccess: () => { lineDialog.value = false } },
  )
}

function removeLine(invoiceId: string, lineId: string) {
  if (!current.value) return
  router.delete(
    route('project-management.portal.invoices.lines.destroy', [current.value.id, invoiceId, lineId]),
    { preserveScroll: true },
  )
}

function submitInvoice(invoiceId: string) {
  if (!current.value) return
  router.post(
    route('project-management.portal.invoices.submit', [current.value.id, invoiceId]),
    {},
    { preserveScroll: true },
  )
}

const documentInput = ref<HTMLInputElement | null>(null)
const documentInvoiceId = ref<string | null>(null)

function chooseDocument(invoiceId: string) {
  documentInvoiceId.value = invoiceId
  documentInput.value?.click()
}

function uploadDocument(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !current.value || !documentInvoiceId.value) return
  router.post(
    route('project-management.portal.invoices.document', [current.value.id, documentInvoiceId.value]),
    { file },
    { preserveScroll: true, forceFormData: true },
  )
  ;(event.target as HTMLInputElement).value = ''
}

function invoiceMoney(amount: string, currency: string) {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' }).format(Number(amount))
  } catch {
    return amount
  }
}

const INVOICE_COLOUR: Record<string, string> = {
  draft: 'grey', submitted: 'warning', approved: 'success', rejected: 'error',
}
</script>

<template>
  <PortalShell :company="props.company" :me="props.me">
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.portal.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.portal.subtitle') }}</p>
    </div>

    <!-- Nothing here at all: a staff member with no external membership, which is a real state
         and not an error. -->
    <VCard v-if="!projects.length" border>
      <VCardText class="text-center py-10">
        <VIcon icon="tabler-door" size="48" class="text-medium-emphasis mb-3" />
        <div class="text-body-1 mb-1">{{ t('project_management.portal.empty_title') }}</div>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.portal.empty_body') }}</p>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- What is actually waiting on this person, across every job. The reason somebody opens a
           portal, so it sits above the project rather than inside one. -->
      <VAlert v-if="awaiting.length" type="info" variant="tonal" class="mb-4">
        <div class="d-flex align-center justify-space-between flex-wrap gap-2">
          <div>
            <div class="font-weight-medium">
              {{ t('project_management.portal.awaiting_title', { count: awaiting.length }) }}
            </div>
            <div class="text-caption">
              {{ awaiting.slice(0, 3).map(a => a.label).join(' · ') }}
            </div>
          </div>
          <VBtn size="small" variant="tonal" @click="openApproval">
            {{ t('project_management.portal.review') }}
          </VBtn>
        </div>
      </VAlert>

      <!-- ── Everything, before any one thing ──────────────────────────────── -->
      <template v-if="view === 'overview'">
        <div class="portal-tiles mb-4">
          <VCard border>
            <VCardText>
              <div class="text-h5">{{ portfolio.projects }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.portal.projects') }}</div>
            </VCardText>
          </VCard>
          <VCard border>
            <VCardText>
              <div class="text-h5">{{ portfolio.progress === null ? '—' : portfolio.progress + '%' }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.portal.avg_progress') }}</div>
            </VCardText>
          </VCard>
          <VCard border>
            <VCardText>
              <div class="text-h5">{{ portfolio.milestones_done }} / {{ portfolio.milestones_total }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.portal.mile_delivered') }}</div>
            </VCardText>
          </VCard>
          <VCard border>
            <VCardText>
              <!-- Coloured only when it is not zero: a red 0 teaches people to ignore the colour. -->
              <div class="text-h5" :class="portfolio.late ? 'text-error' : ''">{{ portfolio.late }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.portal.mile_late') }}</div>
            </VCardText>
          </VCard>
          <VCard border>
            <VCardText>
              <div class="text-h5">{{ portfolio.open_requests }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.portal.open_requests') }}</div>
            </VCardText>
          </VCard>
        </div>

        <VRow class="mb-2">
          <VCol cols="12" md="7">
            <VCard border class="h-100">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.progress_by_project') }}</VCardTitle>
              </VCardItem>
              <VCardText>
                <VueApexCharts
                  type="bar" :height="Math.max(180, projects.length * 52)"
                  :options="progressOptions" :series="progressSeries"
                />
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="5">
            <VCard border class="h-100">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.by_stage') }}</VCardTitle>
              </VCardItem>
              <VCardText>
                <VueApexCharts type="donut" :height="260" :options="stageOptions" :series="stageSeries" />
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow class="mb-2">
          <VCol cols="12" md="7">
            <VCard border class="h-100">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.delivery') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.delivery_sub') }}</VCardSubtitle>
              </VCardItem>
              <VCardText>
                <VueApexCharts
                  type="bar" :height="Math.max(180, projects.length * 52)"
                  :options="deliveryOptions" :series="deliverySeries"
                />
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="5">
            <VCard border class="h-100">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.whats_next') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.whats_next_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList v-if="timeline.length" density="compact" class="py-0">
                <!-- Sorted by date across every job: the next thing due is the next thing due. -->
                <VListItem
                  v-for="(line, i) in timeline" :key="i"
                  class="cursor-pointer" @click="openProject(line.project.id)"
                >
                  <template #prepend>
                    <VIcon
                      :icon="line.late ? 'tabler-alert-triangle' : 'tabler-flag'"
                      :color="line.late ? 'error' : 'primary'" size="18" class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2">{{ line.label }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ line.project.code }} · {{ line.due }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
              <VCardText v-else class="text-body-2 text-medium-emphasis">
                {{ t('project_management.portal.nothing_scheduled') }}
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- The way in. A card per project, so opening one is a click and not a dropdown. -->
        <div class="text-body-1 font-weight-medium mt-4 mb-2">
          {{ t('project_management.portal.your_projects') }}
        </div>
        <VRow>
          <VCol v-for="project in projects" :key="project.id" cols="12" sm="6" lg="4">
            <VCard border class="h-100 cursor-pointer" @click="openProject(project.id)">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ project.name }}</VCardTitle>
                <VCardSubtitle>
                  {{ project.code }} · {{ t(`project_management.stage.${project.stage}`, project.stage) }}
                </VCardSubtitle>
                <template #append>
                  <VChip size="x-small" label :color="project.role === 'client' ? 'primary' : 'teal'">
                    {{ t(`project_management.roles.${project.role}`, project.role) }}
                  </VChip>
                </template>
              </VCardItem>
              <VCardText>
                <div class="d-flex align-center justify-space-between text-caption mb-1">
                  <span>{{ t('project_management.portal.progress') }}</span>
                  <span>{{ project.progress === null ? '—' : Math.round(project.progress) + '%' }}</span>
                </div>
                <VProgressLinear
                  :model-value="project.progress ?? 0" height="6" rounded
                  :color="(project.milestones ?? []).some(isLate) ? 'error' : 'primary'"
                />

                <div class="d-flex flex-wrap gap-1 mt-3">
                  <VChip
                    v-if="(project.milestones ?? []).some(isLate)"
                    size="x-small" label color="error" variant="tonal"
                  >
                    {{ t('project_management.portal.n_late', { n: (project.milestones ?? []).filter(isLate).length }) }}
                  </VChip>
                  <VChip
                    v-if="(project.contracts ?? []).some(c => c.awaiting_me)"
                    size="x-small" label color="warning" variant="tonal"
                  >
                    {{ t('project_management.portal.awaiting_you') }}
                  </VChip>
                  <VChip v-if="project.planned_end" size="x-small" label variant="tonal">
                    {{ t('project_management.portal.due', { date: project.planned_end }) }}
                  </VChip>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </template>

      <!-- ── One project ──────────────────────────────────────────────────── -->
      <div v-if="view === 'project'" class="d-flex align-center gap-3 mb-4 flex-wrap">
        <VBtn
          variant="text" size="small" prepend-icon="tabler-arrow-left"
          @click="view = 'overview'"
        >
          {{ t('project_management.portal.all_projects') }}
        </VBtn>
        <VAutocomplete v-if="projects.length > 1" v-model="selected"
                 :items="projects.map(p => ({ title: `${p.code} · ${p.name}`, value: p.id }))"
                 :label="t('project_management.portal.field_project')"
                 variant="outlined" density="compact" hide-details style="max-width:420px" />
      </div>

      <template v-if="current && view === 'project'">
        <VRow dense class="mb-2">
          <VCol cols="12" md="8">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle>{{ current.code }} · {{ current.name }}</VCardTitle>
                <VCardSubtitle>
                  {{ t(`project_management.status.${current.status}`) }} ·
                  {{ t(`project_management.stage.${current.stage}`) }}
                  <template v-if="current.planned_end">
                    · {{ t('project_management.portal.due', { date: current.planned_end }) }}
                  </template>
                </VCardSubtitle>
                <template #append>
                  <VChip size="small" label :color="isClient ? 'primary' : 'teal'">
                    {{ t(`project_management.roles.${current.role}`) }}
                  </VChip>
                </template>
              </VCardItem>
              <VCardText>
                <div class="d-flex align-center gap-3">
                  <VProgressLinear :model-value="current.progress ?? 0" height="10" rounded color="primary"
                                   class="flex-grow-1" />
                  <span class="text-body-2 font-weight-medium">
                    {{ current.progress !== null ? `${current.progress}%` : '—' }}
                  </span>
                </div>
                <!-- Said plainly: this is the same number the delivery team sees, not a second
                     opinion computed for the portal. -->
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('project_management.portal.progress_note') }}
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" md="4">
            <VCard border height="100%">
              <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.portal.milestones') }}</VCardTitle></VCardItem>
              <VDivider />
              <VList v-if="current.milestones.length" density="compact">
                <VListItem v-for="(m, i) in current.milestones.slice(0, 6)" :key="i">
                  <template #prepend>
                    <VIcon :icon="m.done ? 'tabler-circle-check' : 'tabler-circle-dashed'"
                           :color="m.done ? 'success' : undefined" size="18" class="me-2" />
                  </template>
                  <VListItemTitle class="text-body-2">{{ m.name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ m.due || '—' }}</VListItemSubtitle>
                </VListItem>
              </VList>
              <VCardText v-else class="text-caption text-medium-emphasis">
                {{ t('project_management.portal.no_milestones') }}
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow v-if="isClient" dense>
          <!-- ── Asking for something ─────────────────────────────────────── -->
          <VCol cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.raise_request') }}</VCardTitle>
                <VCardSubtitle class="text-wrap">{{ t('project_management.portal.raise_request_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VCardText>
                <VTextField
                  v-model="requestForm.name"
                  :label="t('project_management.portal.request_title')"
                  :error-messages="requestForm.errors.name"
                  variant="outlined" density="compact" class="mb-3"
                />
                <VTextarea
                  v-model="requestForm.description" rows="3" auto-grow
                  :label="t('project_management.portal.request_detail')"
                  :error-messages="requestForm.errors.description"
                  variant="outlined" density="compact" hide-details="auto" class="mb-3"
                />
                <VBtn
                  color="primary" size="small" prepend-icon="tabler-plus"
                  :loading="requestForm.processing" :disabled="!requestForm.name.trim()"
                  @click="raiseRequest"
                >
                  {{ t('project_management.portal.send_request') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>

          <!-- ── And what happened to it ──────────────────────────────────── -->
          <VCol cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.your_requests') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.your_requests_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList v-if="current.requests.length" density="compact">
                <VListItem v-for="r in current.requests" :key="r.id">
                  <VListItemTitle class="text-body-2">{{ r.name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    <span v-if="r.code" class="me-1">{{ r.code }}</span>{{ r.raised }}
                  </VListItemSubtitle>
                  <template #append>
                    <VChip size="x-small" label>
                      {{ t(`project_management.task_status.${r.status}`) }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
              <VCardText v-else class="text-caption text-medium-emphasis">
                {{ t('project_management.portal.no_requests') }}
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow dense>
          <!-- ── Client panels ────────────────────────────────────────────── -->
          <VCol v-if="isClient && current.changes.length" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.changes') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.changes_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="c in current.changes" :key="c.code">
                  <VListItemTitle class="text-body-2">{{ c.code }} · {{ c.title }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    <template v-if="c.days !== null">
                      {{ t('project_management.portal.days_effect', { n: c.days }) }}
                    </template>
                  </VListItemSubtitle>
                  <template #append>
                    <VChip size="x-small" label>
                      {{ t(`project_management.registers.change_status_${c.status}`) }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <!-- ── Contractor panels ────────────────────────────────────────── -->
          <!--
            What they are working under. A subcontractor can only reach the portal, so a contract
            that appears only on the delivery side is one they can never read or sign.
          -->
          <VCol v-if="!isClient && current.contracts?.length" cols="12">
            <VCard border>
              <VCardItem>
                <VCardTitle class="text-body-1">
                  {{ t('project_management.portal.my_contracts') }}
                </VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.my_contracts_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem
                  v-for="c in current.contracts" :key="c.id"
                  :title="c.number"
                  :subtitle="c.title"
                  class="cursor-pointer"
                  @click="openContract(c.id)"
                >
                  <template #append>
                    <div class="d-flex align-center gap-2">
                      <!-- The whole reason this card exists: it is their turn. -->
                      <VChip v-if="c.awaiting_me" color="warning" size="small" variant="flat">
                        {{ t('project_management.portal.sign_now') }}
                      </VChip>
                      <VChip
                        v-else :color="CONTRACT_COLOUR[c.status] ?? 'grey'"
                        size="small" variant="tonal"
                      >
                        {{ t('project_management.contracts.status_' + c.status) }}
                      </VChip>
                      <VIcon icon="tabler-chevron-right" size="18" />
                    </div>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <VCol v-if="!isClient" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.my_work') }}</VCardTitle>
                <VCardSubtitle>
                  {{ t('project_management.portal.hours_this_month', { n: current.my_hours ?? 0 }) }}
                </VCardSubtitle>
                <template #append>
                  <VBtn size="small" variant="tonal" color="primary" @click="timeDialog = true">
                    {{ t('project_management.portal.book_time') }}
                  </VBtn>
                </template>
              </VCardItem>
              <VDivider />
              <VList v-if="current.work.length" density="compact">
                <VListItem v-for="w in current.work" :key="w.id">
                  <VListItemTitle class="text-body-2">{{ w.name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ w.due || '—' }}</VListItemSubtitle>
                  <template #append>
                    <VChip size="x-small" label>{{ t(`project_management.task_status.${w.status}`) }}</VChip>
                  </template>
                </VListItem>
              </VList>
              <VCardText v-else class="text-caption text-medium-emphasis">
                {{ t('project_management.portal.no_work') }}
              </VCardText>
            </VCard>
          </VCol>

          <VCol v-if="!isClient && current.snags.length" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.portal.snags') }}</VCardTitle></VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="s in current.snags" :key="s.reference">
                  <VListItemTitle class="text-body-2">{{ s.reference }} · {{ s.title }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ s.due || '—' }}</VListItemSubtitle>
                  <template #append>
                    <VChip size="x-small" label :color="severityColour(s.severity)">
                      {{ t(`project_management.site.severity_${s.severity}`) }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <VCol v-if="!isClient && current.claims.length" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.claims') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.claims_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="c in current.claims" :key="c.number">
                  <VListItemTitle class="text-body-2">
                    {{ t('project_management.portal.claim_n', { n: c.number }) }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ c.period || '—' }}</VListItemSubtitle>
                  <template #append>
                    <span v-if="c.net !== null" class="text-body-2 me-2">{{ c.net.toFixed(2) }}</span>
                    <VChip size="x-small" label>{{ t(`project_management.site.claim_status_${c.status}`) }}</VChip>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <!-- ── My invoices ────────────────────────────────────────────── -->
          <VCol v-if="!isClient" cols="12">
            <VCard border>
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.my_invoices') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.my_invoices_sub') }}</VCardSubtitle>
                <template #append>
                  <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="openInvoice">
                    {{ t('project_management.portal.new_invoice') }}
                  </VBtn>
                </template>
              </VCardItem>
              <VDivider />

              <VCardText v-if="!current.invoices.length" class="text-center text-medium-emphasis py-6">
                {{ t('project_management.portal.no_invoices') }}
              </VCardText>

              <VExpansionPanels v-else variant="accordion" class="pa-2">
                <VExpansionPanel v-for="inv in current.invoices" :key="inv.id">
                  <VExpansionPanelTitle>
                    <div class="d-flex align-center flex-wrap gap-2" style="inline-size: 100%">
                      <VChip :color="INVOICE_COLOUR[inv.status]" size="x-small" variant="tonal">
                        {{ t('project_management.portal.invoice_status_' + inv.status) }}
                      </VChip>
                      <span class="text-body-2">{{ inv.reference || t('project_management.portal.invoice_unreferenced') }}</span>
                      <span class="text-caption text-medium-emphasis">{{ inv.period_end || inv.invoice_date || '' }}</span>
                      <VSpacer />
                      <span class="text-body-2 figure">{{ invoiceMoney(inv.total, inv.currency) }}</span>
                    </div>
                  </VExpansionPanelTitle>
                  <VExpansionPanelText>
                    <!-- A rejection they cannot read is one they cannot act on. -->
                    <VAlert
                      v-if="inv.status === 'rejected' && inv.decision_reason"
                      type="error" variant="tonal" density="compact" class="mb-3"
                    >
                      {{ inv.decision_reason }}
                    </VAlert>

                    <VTable v-if="inv.lines.length" density="compact" class="mb-3">
                      <thead>
                        <tr>
                          <th>{{ t('project_management.portal.line_description') }}</th>
                          <th class="text-end">{{ t('project_management.portal.line_quantity') }}</th>
                          <th class="text-end">{{ t('project_management.portal.line_rate') }}</th>
                          <th class="text-end">{{ t('project_management.portal.line_amount') }}</th>
                          <th />
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="line in inv.lines" :key="line.id">
                          <td>{{ line.description }}</td>
                          <td class="text-end figure">{{ line.quantity }}</td>
                          <td class="text-end figure">{{ invoiceMoney(line.rate, inv.currency) }}</td>
                          <td class="text-end figure">{{ invoiceMoney(line.amount, inv.currency) }}</td>
                          <td class="text-end">
                            <VBtn
                              v-if="inv.status === 'draft'" icon="tabler-trash" size="x-small" variant="text"
                              @click="removeLine(inv.id, line.id)"
                            />
                          </td>
                        </tr>
                      </tbody>
                    </VTable>

                    <div class="d-flex flex-wrap justify-space-between gap-3 mb-3">
                      <div class="text-body-2 text-medium-emphasis">{{ inv.description }}</div>
                      <div class="text-end">
                        <div class="text-caption text-medium-emphasis">
                          {{ t('project_management.portal.invoice_vat') }} {{ invoiceMoney(inv.vat_amount, inv.currency) }}
                        </div>
                        <div class="text-body-1 figure">{{ invoiceMoney(inv.total, inv.currency) }}</div>
                      </div>
                    </div>

                    <div v-if="inv.status === 'draft'" class="d-flex flex-wrap gap-2">
                      <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="openLine(inv.id)">
                        {{ t('project_management.portal.add_line') }}
                      </VBtn>
                      <VBtn size="small" variant="text" prepend-icon="tabler-paperclip" @click="chooseDocument(inv.id)">
                        {{ inv.file_name || t('project_management.portal.attach_invoice') }}
                      </VBtn>
                      <VSpacer />
                      <VBtn size="small" color="primary" prepend-icon="tabler-send" @click="submitInvoice(inv.id)">
                        {{ t('project_management.portal.submit_invoice') }}
                      </VBtn>
                    </div>
                    <div v-else class="text-caption text-medium-emphasis">
                      {{ t('project_management.portal.invoice_sent_on', { at: inv.submitted_at ?? '' }) }}
                    </div>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>
            </VCard>
          </VCol>

          <VCol v-if="!isClient && current.instructions.length" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.portal.instructions') }}</VCardTitle></VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="i in current.instructions" :key="i.number">
                  <VListItemTitle class="text-body-2">{{ i.number }} · {{ i.title }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ i.issued_on }}</VListItemSubtitle>
                  <template #append>
                    <VChip v-if="i.variation" size="x-small" color="warning" label>
                      {{ t('project_management.site_records.claimed') }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
            </VCard>
          </VCol>

          <!-- ── Both ─────────────────────────────────────────────────────── -->
          <VCol cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.documents') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.documents_sub') }}</VCardSubtitle>
              </VCardItem>
              <VDivider />
              <VList v-if="current.documents.length" density="compact" max-height="280" class="overflow-y-auto">
                <VListItem v-for="d in current.documents" :key="d.id">
                  <VListItemTitle class="text-body-2">
                    <a :href="route('project-management.documents.show', [current.id, d.id])" target="_blank">
                      {{ d.name }}
                    </a>
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ humanSize(d.size) }}</VListItemSubtitle>
                </VListItem>
              </VList>
              <VCardText v-else class="text-caption text-medium-emphasis">
                {{ t('project_management.portal.no_documents') }}
              </VCardText>
              <VDivider v-if="can.upload" />
              <VCardText v-if="can.upload" class="d-flex align-center gap-2 flex-wrap">
                <VFileInput v-model="uploadForm.file" :label="t('project_management.portal.upload')"
                            :error-messages="uploadForm.errors.file"
                            variant="outlined" density="compact" prepend-icon=""
                            prepend-inner-icon="tabler-upload" hide-details style="min-width:220px" />
                <VBtn color="primary" size="small" :loading="uploadForm.processing"
                      :disabled="!uploadForm.file" @click="upload">
                  {{ t('project_management.portal.send') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>

          <VCol v-if="isClient" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.portal.rate_title') }}</VCardTitle>
                <VCardSubtitle>{{ t('project_management.portal.rate_sub') }}</VCardSubtitle>
              </VCardItem>
              <VCardText class="text-center">
                <VRating :model-value="current.my_rating?.score ?? 0" readonly density="comfortable"
                         color="warning" />
                <div class="text-caption text-medium-emphasis mb-3">
                  {{ current.my_rating
                    ? t('project_management.portal.rated_this_month')
                    : t('project_management.portal.not_rated') }}
                </div>
                <VBtn size="small" variant="tonal" color="primary" @click="openRating">
                  {{ current.my_rating ? t('project_management.portal.change_rating') : t('project_management.portal.rate_it') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>

          <VCol v-if="current.completions.length" cols="12" md="6">
            <VCard border height="100%">
              <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.portal.completion') }}</VCardTitle></VCardItem>
              <VDivider />
              <VList density="compact">
                <VListItem v-for="(c, i) in current.completions" :key="i">
                  <VListItemTitle class="text-body-2">
                    {{ t(`project_management.site_records.completion_${c.kind}`) }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ c.date }}
                    <template v-if="c.defects_ends">
                      · {{ t('project_management.site_records.defects_until', { date: c.defects_ends }) }}
                    </template>
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCard>
          </VCol>
        </VRow>
      </template>
    </template>

    <VDialog v-model="ratingDialog" :fullscreen="smAndDown" max-width="480">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portal.rate_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.portal.rate_dialog_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText class="text-center">
          <VRating v-model="ratingForm.score" density="comfortable" color="warning" size="large" />
          <VTextarea v-model="ratingForm.comment" class="mt-4"
                     :label="t('project_management.portal.rate_comment')"
                     variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ratingDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ratingForm.processing" :disabled="!ratingForm.score" @click="saveRating">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="timeDialog" :fullscreen="smAndDown" max-width="480">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portal.book_time') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.portal.time_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VSelect v-model="timeForm.task_id"
                   :items="(current?.work ?? []).map(w => ({ title: w.name, value: w.id }))"
                   :label="t('project_management.portal.field_task')" clearable
                   variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="timeForm.worked_on" type="date"
                      :label="t('project_management.portal.field_date')"
                      :error-messages="timeForm.errors.worked_on"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model.number="timeForm.hours" type="number" step="0.25"
                      :label="t('project_management.portal.field_hours')"
                      :error-messages="timeForm.errors.hours"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="timeForm.note" :label="t('project_management.portal.field_note')"
                     variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="timeDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="timeForm.processing" :disabled="!timeForm.hours" @click="saveTime">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

    <!-- The file input lives outside the loop: one hidden control, pointed at whichever invoice
         asked for it, rather than one per row. -->
    <input ref="documentInput" type="file" accept=".pdf,.png,.jpg,.jpeg" hidden @change="uploadDocument">

    <!-- ── Start an invoice ───────────────────────────────────────────────── -->
    <VDialog v-model="invoiceDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portal.new_invoice') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.portal.new_invoice_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model="invoiceForm.reference"
                :label="t('project_management.portal.invoice_reference')"
                :hint="t('project_management.portal.invoice_reference_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="invoiceForm.invoice_date" type="date"
                :label="t('project_management.portal.invoice_date')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="invoiceForm.period_start" type="date"
                :label="t('project_management.portal.period_start')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="invoiceForm.period_end" type="date"
                :label="t('project_management.portal.period_end')"
                :error-messages="invoiceForm.errors.period_end"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="invoiceForm.vat_rate" inputmode="decimal" suffix="%"
                :label="t('project_management.portal.invoice_vat_rate')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="invoiceForm.description" rows="2" auto-grow
                :label="t('project_management.portal.invoice_description')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="invoiceDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="invoiceForm.processing" @click="createInvoice">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Add a line ─────────────────────────────────────────────────────── -->
    <VDialog v-model="lineDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.portal.add_line') }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="lineForm.description"
                :label="t('project_management.portal.line_description')"
                :error-messages="lineForm.errors.description"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="lineForm.quantity" inputmode="decimal"
                :label="t('project_management.portal.line_quantity')"
                :error-messages="lineForm.errors.quantity"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="lineForm.rate" inputmode="decimal"
                :label="t('project_management.portal.line_rate')"
                :error-messages="lineForm.errors.rate"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="lineDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="lineForm.processing" @click="addLine">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </PortalShell>
</template>

<style scoped>
/* Five small numbers that wrap, rather than five columns that squeeze. */
.portal-tiles {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}
</style>
