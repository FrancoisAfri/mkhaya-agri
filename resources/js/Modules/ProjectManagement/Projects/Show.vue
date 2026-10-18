<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import ChatPanel from './ChatPanel.vue'
import ClientPicker from './ClientPicker.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Project = {
  id: string; code: string; name: string; description: string | null
  status: string; stage: string; priority: string
  client: string | null; client_id: string | null; client_source: string | null
  currency: string; budget_amount: string | null; timezone: string
  calendar: { id: string; name: string; timezone: string } | null
  planned_start: string | null; planned_end: string | null
  actual_start: string | null; actual_end: string | null
  location_name: string | null; task_count: number; evidence_count: number
  is_billable: boolean; timesheet_approval_required: boolean
  created_at: string | null; updated_at: string | null
}

const props = defineProps<{
  project: Project
  capabilities: Record<string, boolean>
  can: { update: boolean; delete: boolean; changeStatus: boolean; raisePurchase: boolean; viewInvoicing: boolean }
  currencies: Array<{ code: string; name: string; priority: boolean }>
  timezones: Array<{ value: string; title: string }>
  calendars: Array<{ id: string; name: string; timezone: string }>
  statuses: string[]
  stages: string[]
  priorities: string[]
  /** Records from other modules attached to this project, grouped by kind. */
  links: Record<string, Array<{ id: string; label: string; meta: Record<string, any>; orphaned: boolean }>>
  /** Kinds this workspace can attach — empty when no other module is installed. */
  linkKinds: string[]
  /** Won deals behind this project's client, when CRM knows of any. */
  opportunities: Array<{ label: string; amount: number | null; currency: string | null }>
  /** Decision points between stages (§2). */
  gates: Array<{ id: string; stage: string; name: string; criteria: string | null; status: string; note: string | null; decided_at: string | null }>
  /** Offered only while the project has no tasks — applying one over a real plan would duplicate it. */
  templates: Array<{ id: string; name: string; industry: string }>
}>()

const currencyItems = computed(() => props.currencies.map(c => ({
  title: `${c.code} — ${c.name}`,
  value: c.code,
})))

const editing = ref(false)
const confirmDelete = ref(false)

/**
 * The project's pages, as tabs.
 *
 * Every one of these is a real navigation rather than a panel: the schedule, the board and the
 * evidence timeline each carry payloads far too heavy to hold in one Inertia response, and loading
 * seven of them to show one would make the page that opens a project the slowest in the module.
 *
 * So the tab strip is a *destination* control, not a content switcher — which is why `currentTab`
 * is always `overview` here and each tab navigates away.
 */
const tabs = computed(() => [
  { key: 'overview', label: t('project_management.projects.tab_overview'), icon: 'tabler-info-circle', route: null },
  { key: 'work', label: t('project_management.work.title'), icon: 'tabler-sitemap', route: 'project-management.work' },
  { key: 'views', label: t('project_management.views.title'), icon: 'tabler-layout-columns', route: 'project-management.board' },
  { key: 'schedule', label: t('project_management.schedule.title'), icon: 'tabler-calendar-stats', route: 'project-management.schedule' },
  { key: 'delivery', label: t('project_management.delivery.title'), icon: 'tabler-users-group', route: 'project-management.delivery' },
  { key: 'figures', label: t('project_management.figures.title'), icon: 'tabler-math-avg', route: 'project-management.figures' },
  { key: 'documents', label: t('project_management.documents.title'), icon: 'tabler-files', route: 'project-management.documents' },
  { key: 'site', label: t('project_management.site.title'), icon: 'tabler-building-factory', route: 'project-management.site' },
  { key: 'registers', label: t('project_management.registers.title'), icon: 'tabler-alert-triangle', route: 'project-management.registers' },
  { key: 'site_records', label: t('project_management.site_records.title'), icon: 'tabler-clipboard-check', route: 'project-management.site.records' },
  { key: 'agile', label: t('project_management.agile.title'), icon: 'tabler-run', route: 'project-management.agile' },
  // Chat and gates stay on this page rather than navigating: both are already in this payload and
  // are small. They are `panel: true` so `goTo` switches the view instead of routing away — the one
  // place the strip is a content switcher rather than a destination control.
  { key: 'chat', label: t('project_management.chat.tab'), icon: 'tabler-message-circle', route: null, panel: true },
  { key: 'gates', label: t('project_management.gates.tab'), icon: 'tabler-flag-check', route: null, panel: true },
  { key: 'evidence', label: t('project_management.projects.view_evidence'), icon: 'tabler-clipboard-check', route: null },
  // Project Invoicing is an add-on: the tab appears only where it is usable, so a project page on
  // a workspace that does not invoice looks exactly as it did before.
  ...(props.can.viewInvoicing
    ? [{
        key: 'invoicing',
        label: t('project_management.hubs.invoicing.title'),
        icon: 'tabler-file-invoice',
        route: 'project-management.projects.invoicing',
      }]
    : []),
])

const currentTab = ref<'overview' | 'chat' | 'gates'>('overview')

/**
 * The tabs split into a visible row and an overflow menu.
 *
 * Fourteen tabs do not fit, and Vuetify's scroll arrows hide the ones on the end behind a control
 * most people never notice — so the pages at the far end were effectively unreachable without
 * knowing they were there. A "More" menu names what is inside it, the way the main navigation does.
 *
 * The cut is deliberate rather than measured: the first eight are the ones used daily, and a
 * responsive measurement that reflows as you resize is worse than a stable row you learn the shape
 * of. Whichever tab is open is always promoted into the visible set, so the current page never
 * hides inside the menu.
 */
const PRIMARY_TABS = 8

const visibleTabs = computed(() => {
  const shown = tabs.value.slice(0, PRIMARY_TABS)
  const active = tabs.value.find(t => t.key === currentTab.value)

  return active && !shown.includes(active) ? [...shown.slice(0, -1), active] : shown
})

const overflowTabs = computed(() => tabs.value.filter(t => !visibleTabs.value.includes(t)))

// ── Stage gates ──────────────────────────────────────────────────────────────

const gateDialog = ref(false)

/** Shown on the gates tab so a pending decision is visible from anywhere on the page. */
const pendingGates = computed(() => props.gates.filter(g => g.status === 'pending').length)

const gateForm = useForm({ stage: 'planning', name: '', criteria: '' })

function saveGate() {
  gateForm.post(route('project-management.gates.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { gateDialog.value = false; gateForm.reset() },
  })
}

const decideDialog = ref(false)
const decidingGate = ref<{ id: string; name: string } | null>(null)
const decideForm = useForm({ status: 'passed', note: '' })

function openDecide(g: { id: string; name: string }) {
  decidingGate.value = g
  decideForm.reset()
  decideForm.clearErrors()
  decideDialog.value = true
}

function submitDecide() {
  if (!decidingGate.value) return
  decideForm.post(route('project-management.gates.decide', [props.project.id, decidingGate.value.id]), {
    preserveScroll: true, onSuccess: () => { decideDialog.value = false },
  })
}

function gateColour(status: string) {
  return status === 'passed' ? 'success' : status === 'failed' ? 'error'
    : status === 'waived' ? 'warning' : 'default'
}

// ── Starting from a template ─────────────────────────────────────────────────

const templateForm = useForm({ template_id: '' })

function applyTemplate() {
  templateForm.post(route('project-management.projects.template', props.project.id), { preserveScroll: true })
}

// ── Raising a purchase against this project ──────────────────────────────────

const purchaseOpen = ref(false)

const purchaseForm = useForm<{
  title: string; justification: string; needed_by: string; delivery_location: string
  urgency: string; items: Array<{ name: string; description: string; quantity: number; unit_price: number }>
}>({
  title: '', justification: '', needed_by: '', delivery_location: '', urgency: 'normal',
  items: [{ name: '', description: '', quantity: 1, unit_price: 0 }],
})

// Shown live so nobody submits a request whose total surprises them at the approval step.
const purchaseTotal = computed(() => purchaseForm.items.reduce(
  (sum, i) => sum + (Number(i.quantity) || 0) * (Number(i.unit_price) || 0), 0,
))

function addPurchaseItem() {
  purchaseForm.items.push({ name: '', description: '', quantity: 1, unit_price: 0 })
}

function removePurchaseItem(index: number) {
  if (purchaseForm.items.length > 1) purchaseForm.items.splice(index, 1)
}

function submitPurchase() {
  purchaseForm.post(route('project-management.purchases.raise', props.project.id), {
    preserveScroll: true,
    onSuccess: () => {
      purchaseOpen.value = false
      purchaseForm.reset()
    },
  })
}

// ── Linked records from other modules ────────────────────────────────────────

const linkDialog = ref(false)
const linkKind = ref<string>('')
const linkResults = ref<Array<{ id: string; label: string }>>([])
const linkLoading = ref(false)
const linkConfirmer = ref()

const linkForm = useForm({ kind: '', record_id: '', label: '' })

const hasLinks = computed(() => Object.keys(props.links ?? {}).length > 0)

async function findRecords(query: string) {
  if (!linkKind.value) return

  linkLoading.value = true
  try {
    const response = await fetch(
      route('project-management.links.search', props.project.id)
        + `?kind=${encodeURIComponent(linkKind.value)}&q=${encodeURIComponent(query ?? '')}`,
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
    linkResults.value = (await response.json()).results ?? []
  } finally {
    linkLoading.value = false
  }
}

function openLink(kind: string) {
  linkKind.value = kind
  linkForm.reset()
  linkForm.kind = kind
  linkResults.value = []
  linkDialog.value = true
  findRecords('')
}

function saveLink() {
  // The label travels with the link so the row still reads if the other module is uninstalled or
  // the record removed — an orphaned link showing an id is one nobody can interpret.
  linkForm.label = linkResults.value.find(r => r.id === linkForm.record_id)?.label ?? ''

  linkForm.post(route('project-management.links.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => { linkDialog.value = false; linkForm.reset() },
  })
}

function removeLink(kind: string, link: { id: string; label: string }) {
  linkConfirmer.value.open({
    title: t('project_management.links.confirm_remove_title'),
    message: t('project_management.links.confirm_remove_body', { label: link.label }),
    confirmText: t('project_management.links.unlink'),
    icon: 'tabler-unlink',
  }, () => router.delete(
    route('project-management.links.destroy', [props.project.id, link.id]),
    { preserveScroll: true },
  ))
}

const kindIcon: Record<string, string> = {
  asset: 'tabler-package', vehicle: 'tabler-truck', contract: 'tabler-file-certificate',
  purchase_order: 'tabler-shopping-cart', requisition: 'tabler-clipboard-list',
  supplier: 'tabler-building-store', ticket: 'tabler-lifebuoy',
  budget: 'tabler-wallet', invoice: 'tabler-receipt',
}

function goTo(tab: { key: string; route: string | null; panel?: boolean }) {
  // The panels live here; everything else is a different page.
  if (tab.key === 'overview' || tab.panel) {
    currentTab.value = tab.key as 'overview' | 'chat' | 'gates'

    return
  }

  // The evidence timeline is tenant-wide and filtered to this project, so it takes a query rather
  // than a project in the path.
  if (tab.key === 'evidence') {
    router.get(route('project-management.evidence.timeline'), { filter: { project_id: props.project.id } })

    return
  }

  router.get(route(tab.route!, props.project.id))
}

const form = useForm({
  name: props.project.name,
  description: props.project.description ?? '',
  status: props.project.status,
  stage: props.project.stage,
  priority: props.project.priority,
  currency: props.project.currency,
  budget_amount: props.project.budget_amount ?? '',
  client_id: props.project.client_id ?? null,
  timezone: props.project.timezone,
  planned_start: props.project.planned_start ?? '',
  planned_end: props.project.planned_end ?? '',
  actual_start: props.project.actual_start ?? '',
  actual_end: props.project.actual_end ?? '',
  location_name: props.project.location_name ?? '',
  calendar_uuid: props.project.calendar?.id ?? '',
  timesheet_approval_required: props.project.timesheet_approval_required,
})

function save() {
  form.transform(data => ({
    ...data,
    budget_amount: data.budget_amount === '' ? null : data.budget_amount,
    client_id: data.client_id || null,
    planned_start: data.planned_start || null,
    planned_end: data.planned_end || null,
    actual_start: data.actual_start || null,
    actual_end: data.actual_end || null,
    calendar_uuid: data.calendar_uuid || null,
  })).put(route('project-management.projects.update', props.project.id), {
    onSuccess: () => { editing.value = false },
  })
}

const destroying = ref(false)

function destroy() {
  destroying.value = true

  router.delete(route('project-management.projects.destroy', props.project.id))
}

const statusColor: Record<string, string> = {
  draft: 'grey', active: 'success', on_hold: 'warning',
  completed: 'info', cancelled: 'error', archived: 'grey',
}

function money(amount: string | null | undefined, currency: string | undefined) {
  // Redacted for external roles: the key is absent, not null (PM-1021).
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' }).format(Number(amount))
  } catch {
    return `${currency} ${amount}`
  }
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.index'))">
      {{ t('project_management.actions.back_to_projects') }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-center gap-2">
          <h4 class="text-h4 mb-0">{{ project.name }}</h4>
          <VChip size="small" :color="statusColor[project.status] ?? 'grey'" label>
            {{ t(`project_management.status.${project.status}`) }}
          </VChip>
        </div>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ project.code }} · {{ t(`project_management.stage.${project.stage}`) }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.update" color="primary" prepend-icon="tabler-edit" @click="editing = true">
          {{ t('project_management.actions.edit') }}
        </VBtn>
        <VBtn
          v-if="can.delete" color="error" variant="tonal" icon="tabler-trash"
          :aria-label="t('project_management.actions.delete')" @click="confirmDelete = true"
        />
      </div>
    </div>

    <!--
      The project's pages as a tab strip rather than seven tonal buttons in a row.
      Two reasons it reads better: a row of equal-weight buttons gives no sense of *where you are*,
      and on a narrower screen it wrapped into a second line that looked like a toolbar of unrelated
      actions. Tabs say "these are the faces of this project, and you are on Overview".

      Each is a real navigation — these pages carry their own payloads and are far too heavy to
      hold in one Inertia response.
    -->
    <div class="d-flex align-center mb-4">
      <VTabs :model-value="currentTab" show-arrows class="flex-grow-1">
        <VTab
          v-for="tab in visibleTabs" :key="tab.key" :value="tab.key"
          :prepend-icon="tab.icon" @click="goTo(tab)"
        >
          {{ tab.label }}
          <!-- A gate waiting on a decision is the one thing worth seeing from the other tabs. -->
          <VChip v-if="tab.key === 'gates' && pendingGates" size="x-small" label color="warning" class="ms-2">
            {{ pendingGates }}
          </VChip>
        </VTab>
      </VTabs>

      <!-- The rest, named rather than hidden behind a scroll arrow. -->
      <VMenu v-if="overflowTabs.length">
        <template #activator="{ props: menuProps }">
          <VBtn
            v-bind="menuProps" variant="text" class="flex-shrink-0"
            prepend-icon="tabler-dots" append-icon="tabler-chevron-down"
          >
            {{ t('project_management.projects.more_tabs') }}
          </VBtn>
        </template>
        <VList density="compact">
          <VListItem
            v-for="tab in overflowTabs" :key="tab.key"
            :prepend-icon="tab.icon" :title="tab.label" @click="goTo(tab)"
          >
            <template v-if="tab.key === 'gates' && pendingGates" #append>
              <VChip size="x-small" label color="warning">{{ pendingGates }}</VChip>
            </template>
          </VListItem>
        </VList>
      </VMenu>
    </div>

    <!--
      Chat and gates get the full width when chosen, rather than the sidebar column they used to
      share. A conversation and a gate list are both long, and squeezing them into a third of the
      page is what made them get scrolled past.
    -->
    <VCard v-if="currentTab === 'chat'" border>
      <ChatPanel :project-id="project.id" :flat="true" />
    </VCard>

    <VCard v-else-if="currentTab === 'gates'" border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.gates.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.gates.subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VList v-if="gates.length" density="compact">
        <VListItem v-for="g in gates" :key="g.id">
          <template #prepend>
            <VIcon :icon="g.status === 'pending' ? 'tabler-circle-dashed' : 'tabler-circle-check'"
                   :color="gateColour(g.status)" size="18" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2">{{ g.name }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ t(`project_management.stage.${g.stage}`) }}
            <template v-if="g.criteria"> · {{ g.criteria }}</template>
            <template v-if="g.note"> · {{ g.note }}</template>
          </VListItemSubtitle>
          <template #append>
            <VChip size="x-small" label :color="gateColour(g.status)">
              {{ t(`project_management.gates.status_${g.status}`) }}
            </VChip>
            <VBtn v-if="can.changeStatus && g.status === 'pending'" size="small" variant="text"
                  icon="tabler-gavel" class="ms-1"
                  :aria-label="t('project_management.gates.decide')" @click="openDecide(g)" />
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-caption text-medium-emphasis">
        {{ t('project_management.gates.none') }} {{ t('project_management.gates.none_hint') }}
      </VCardText>
      <VDivider v-if="can.update" />
      <VCardText v-if="can.update">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="gateDialog = true">
          {{ t('project_management.gates.add') }}
        </VBtn>
      </VCardText>
    </VCard>

    <VRow v-else>
      <VCol cols="12" md="8">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('project_management.projects.details') }}</VCardTitle></VCardItem>
          <VDivider />
          <VCardText>
            <p v-if="project.description" class="mb-4">{{ project.description }}</p>
            <VRow dense>
              <VCol v-for="row in [
                { label: 'col_client', value: project.client ?? '—' },
                { label: 'field_priority', value: t(`project_management.priority.${project.priority}`) },
                { label: 'field_planned_start', value: project.planned_start ?? '—' },
                { label: 'field_planned_end', value: project.planned_end ?? '—' },
                { label: 'field_actual_start', value: project.actual_start ?? '—' },
                { label: 'field_actual_end', value: project.actual_end ?? '—' },
                { label: 'col_budget', value: money(project.budget_amount, project.currency) },
                { label: 'field_timezone', value: project.timezone },
                { label: 'field_calendar', value: project.calendar?.name ?? '—' },
                { label: 'field_location', value: project.location_name ?? '—' },
              ]" :key="row.label" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis">
                  {{ t(`project_management.projects.${row.label}`) }}
                </div>
                <div class="mb-3">{{ row.value }}</div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('project_management.projects.at_a_glance') }}</VCardTitle></VCardItem>
          <VDivider />
          <VList density="compact">
            <VListItem>
              <VListItemTitle>{{ t('project_management.projects.task_count') }}</VListItemTitle>
              <template #append><strong>{{ project.task_count }}</strong></template>
            </VListItem>
            <VListItem>
              <VListItemTitle>{{ t('project_management.projects.evidence_count') }}</VListItemTitle>
              <template #append><strong>{{ project.evidence_count }}</strong></template>
            </VListItem>
            <VListItem>
              <VListItemTitle>{{ t('project_management.projects.field_billable') }}</VListItemTitle>
              <template #append>
                <VIcon :icon="project.is_billable ? 'tabler-check' : 'tabler-x'"
                       :color="project.is_billable ? 'success' : 'grey'" />
              </template>
            </VListItem>
            <VListItem>
              <VListItemTitle>{{ t('project_management.projects.field_timesheet_approval') }}</VListItemTitle>
              <template #append>
                <VIcon :icon="project.timesheet_approval_required ? 'tabler-check' : 'tabler-x'"
                       :color="project.timesheet_approval_required ? 'success' : 'grey'" />
              </template>
            </VListItem>
          </VList>
        </VCard>
        <!--
          Records from other modules attached to this project. Hidden entirely when nothing can be
          attached: an empty picker for a module the workspace does not have is how a working
          system comes to look broken.
        -->
        <VCard v-if="linkKinds.length" border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.links.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.links.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />

          <VList v-if="hasLinks" density="compact">
            <template v-for="(items, kind) in links" :key="kind">
              <VListSubheader>{{ t(`project_management.links.kind_${kind}`) }}</VListSubheader>
              <VListItem v-for="link in items" :key="link.id">
                <template #prepend>
                  <VIcon :icon="kindIcon[kind] ?? 'tabler-link'" size="18" class="me-2" />
                </template>
                <VListItemTitle class="text-body-2">{{ link.label }}</VListItemTitle>
                <!-- Said plainly rather than hidden: a link to a record somebody deleted is still
                     a fact about this project. -->
                <VListItemSubtitle v-if="link.orphaned" class="text-caption text-warning">
                  {{ t('project_management.links.orphaned') }}
                </VListItemSubtitle>
                <template #append>
                  <VBtn
                    v-if="can.update" size="small" variant="text" icon="tabler-unlink"
                    :title="t('project_management.links.unlink')" @click="removeLink(kind, link)"
                  />
                </template>
              </VListItem>
            </template>
          </VList>

          <VCardText v-else class="text-caption text-medium-emphasis">
            {{ t('project_management.links.none') }}
          </VCardText>

          <VDivider />
          <VCardText v-if="can.update" class="d-flex flex-wrap gap-1">
            <VBtn
              v-for="kind in linkKinds" :key="kind"
              size="x-small" variant="tonal" :prepend-icon="kindIcon[kind] ?? 'tabler-plus'"
              @click="openLink(kind)"
            >
              {{ t(`project_management.links.kind_${kind}`) }}
            </VBtn>
          </VCardText>

          <!-- Attaching an existing order is half the story; this is the other half. Kept apart
               from the link buttons because it creates a record rather than referencing one. -->
          <template v-if="can.raisePurchase">
            <VDivider />
            <VCardText>
              <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-shopping-cart-plus" block
                    @click="purchaseOpen = true">
                {{ t('project_management.purchases.raise') }}
              </VBtn>
            </VCardText>
          </template>
        </VCard>

        <!-- Only while there is no plan yet: laying a template over forty existing tasks would
             duplicate every one of them. -->
        <VCard v-if="can.update && templates.length" border class="mt-4">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.templates.start_from') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.templates.start_from_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VSelect v-model="templateForm.template_id"
                     :items="templates.map(x => ({ title: `${x.name}`, value: x.id }))"
                     :label="t('project_management.templates.field_template')"
                     variant="outlined" density="compact" class="mb-2" />
            <VBtn color="primary" size="small" block :loading="templateForm.processing"
                  :disabled="!templateForm.template_id" @click="applyTemplate">
              {{ t('project_management.templates.apply') }}
            </VBtn>
          </VCardText>
        </VCard>

        <!-- What was sold, from CRM. The number a project manager is measured against. -->
        <VCard v-if="opportunities.length" border class="mt-4">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.links.won_work') }}</VCardTitle>
          </VCardItem>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="(deal, i) in opportunities" :key="i">
              <VListItemTitle class="text-body-2">{{ deal.label }}</VListItemTitle>
              <template #append>
                <strong v-if="deal.amount !== null">{{ money(String(deal.amount), deal.currency ?? project.currency) }}</strong>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :fullscreen="smAndDown" v-model="linkDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t(`project_management.links.add_${linkKind}`) }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VAutocomplete
            v-model="linkForm.record_id"
            :items="linkResults.map(r => ({ title: r.label, value: r.id }))"
            :loading="linkLoading"
            :label="t('project_management.links.find')"
            :no-data-text="t('project_management.links.no_matches')"
            :error-messages="linkForm.errors.record_id"
            variant="outlined" density="compact"
            @update:search="findRecords"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="linkDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="linkForm.processing" :disabled="!linkForm.record_id" @click="saveLink">
            {{ t('project_management.links.link') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="gateDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.gates.add') }}</VCardTitle></VCardItem>
        <VCardText>
          <VSelect v-model="gateForm.stage"
                   :items="stages.map(s => ({ title: t(`project_management.stage.${s}`), value: s }))"
                   :label="t('project_management.gates.field_stage')"
                   variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="gateForm.name" :label="t('project_management.gates.field_name')"
                      :error-messages="gateForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="gateForm.criteria" :label="t('project_management.gates.field_criteria')"
                     :hint="t('project_management.gates.criteria_hint')" persistent-hint
                     variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="gateDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="gateForm.processing" :disabled="!gateForm.name" @click="saveGate">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="decideDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.gates.decide') }}</VCardTitle>
          <VCardSubtitle>{{ decidingGate?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRadioGroup v-model="decideForm.status" inline density="compact" class="mb-2">
            <VRadio :label="t('project_management.gates.pass')" value="passed" />
            <VRadio :label="t('project_management.gates.fail')" value="failed" />
            <VRadio :label="t('project_management.gates.waive')" value="waived" />
          </VRadioGroup>
          <!-- Waiving is offered on purpose: gates do get skipped, and a system with only pass and
               fail gets one marked "passed" instead. -->
          <VTextarea v-model="decideForm.note" :label="t('project_management.gates.field_note')"
                     :error-messages="decideForm.errors.note"
                     variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="decideDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="decideForm.processing" @click="submitDecide">
            {{ t('project_management.gates.decide') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="linkConfirmer" />

    <!-- ── Raise a purchase ──────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="purchaseOpen" max-width="820">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.purchases.raise') }}</VCardTitle>
          <VCardSubtitle>{{ project.code }} · {{ project.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Sets expectations before anything is typed: this leaves PM and enters Procurement's
               approval, and the project's code goes with it. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.purchases.note') }}
          </VAlert>

          <VRow dense>
            <VCol cols="12" md="8">
              <VTextField
                v-model="purchaseForm.title" :label="t('project_management.purchases.title_field')"
                :error-messages="purchaseForm.errors.title" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="purchaseForm.urgency"
                :items="['low', 'normal', 'high', 'urgent'].map(u => ({ title: t(`project_management.purchases.urgency_${u}`), value: u }))"
                :label="t('project_management.purchases.urgency')"
                :error-messages="purchaseForm.errors.urgency" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="purchaseForm.needed_by" type="date"
                :label="t('project_management.purchases.needed_by')"
                :error-messages="purchaseForm.errors.needed_by" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="purchaseForm.delivery_location"
                :label="t('project_management.purchases.delivery_location')"
                :error-messages="purchaseForm.errors.delivery_location" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="purchaseForm.justification"
                :label="t('project_management.purchases.justification')"
                :hint="t('project_management.purchases.justification_hint')" persistent-hint
                :error-messages="purchaseForm.errors.justification"
                variant="outlined" density="compact" rows="2"
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-body-2 font-weight-medium">{{ t('project_management.purchases.items') }}</span>
            <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="addPurchaseItem">
              {{ t('project_management.purchases.add_item') }}
            </VBtn>
          </div>

          <VRow v-for="(item, i) in purchaseForm.items" :key="i" dense class="align-center">
            <VCol cols="12" md="4">
              <VTextField
                v-model="item.name" :label="t('project_management.purchases.item_name')"
                :error-messages="purchaseForm.errors[`items.${i}.name` as never]"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="item.description" :label="t('project_management.purchases.item_description')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField
                v-model.number="item.quantity" type="number" step="0.01"
                :label="t('project_management.purchases.quantity')"
                :error-messages="purchaseForm.errors[`items.${i}.quantity` as never]"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField
                v-model.number="item.unit_price" type="number" step="0.01"
                :label="t('project_management.purchases.unit_price')"
                :error-messages="purchaseForm.errors[`items.${i}.unit_price` as never]"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="1" class="text-end">
              <VBtn
                icon="tabler-trash" variant="text" size="small"
                :disabled="purchaseForm.items.length === 1"
                :aria-label="t('project_management.purchases.remove_item')"
                @click="removePurchaseItem(i)"
              />
            </VCol>
          </VRow>

          <div class="d-flex justify-end mt-2">
            <span class="text-body-2 text-medium-emphasis me-2">
              {{ t('project_management.purchases.estimated_total') }}
            </span>
            <span class="text-body-1 font-weight-medium">
              {{ project.currency }} {{ purchaseTotal.toFixed(2) }}
            </span>
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="purchaseOpen = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="purchaseForm.processing"
            :disabled="!purchaseForm.title || !purchaseForm.items[0].name"
            @click="submitPurchase"
          >
            {{ t('project_management.purchases.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="editing" max-width="720">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.actions.edit') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('project_management.projects.field_name')"
                          :error-messages="form.errors.name" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" :label="t('project_management.projects.field_description')"
                         rows="2" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <!-- Searches whichever module owns clients here, and can add one without leaving
                   the form. -->
              <ClientPicker
                v-model="form.client_id"
                :current="{ id: project.client_id, label: project.client, source: project.client_source }"
                :error-message="form.errors.client_id"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.status" :disabled="!can.changeStatus"
                :items="statuses.map(s => ({ title: t(`project_management.status.${s}`), value: s }))"
                :hint="can.changeStatus ? '' : t('project_management.projects.hint_status_locked')"
                persistent-hint
                :label="t('project_management.projects.col_status')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.stage"
                :items="stages.map(s => ({ title: t(`project_management.stage.${s}`), value: s }))"
                :label="t('project_management.projects.field_stage')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="form.currency" :items="currencyItems"
                             :label="t('project_management.projects.field_currency')"
                             variant="outlined" density="compact"
                             :error-messages="form.errors.currency" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.budget_amount" :label="t('project_management.projects.field_budget')"
                          inputmode="decimal" variant="outlined" density="compact"
                          :error-messages="form.errors.budget_amount" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.priority"
                :items="priorities.map(p => ({ title: t(`project_management.priority.${p}`), value: p }))"
                :label="t('project_management.projects.field_priority')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="form.timezone" :items="timezones"
                             :label="t('project_management.projects.field_timezone')"
                             :error-messages="form.errors.timezone" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.calendar_uuid"
                :items="calendars.map(c => ({ title: `${c.name} · ${c.timezone}`, value: c.id }))"
                :label="t('project_management.projects.field_calendar')"
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.location_name"
                          :label="t('project_management.projects.field_location')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.planned_start" type="date"
                          :label="t('project_management.projects.field_planned_start')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.planned_end" type="date"
                          :label="t('project_management.projects.field_planned_end')"
                          :error-messages="form.errors.planned_end" variant="outlined" density="compact" />
            </VCol>

            <!--
              What actually happened, beside what was planned. Kept editable rather than derived:
              a project starts when somebody breaks ground, which is rarely the day a task was
              first ticked, and a date the system guessed is one nobody will trust on a claim.
            -->
            <VCol cols="12" md="6">
              <VTextField v-model="form.actual_start" type="date"
                          :label="t('project_management.projects.field_actual_start')"
                          :hint="t('project_management.projects.hint_actual_start')" persistent-hint
                          :error-messages="form.errors.actual_start" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.actual_end" type="date"
                          :label="t('project_management.projects.field_actual_end')"
                          :hint="t('project_management.projects.hint_actual_end')" persistent-hint
                          :error-messages="form.errors.actual_end" variant="outlined" density="compact" />
            </VCol>

            <!--
              Whether booked hours wait for a sign-off. Right for a job billed to a client, where an
              hour is money; friction on an internal one, where the same routing sends every entry to
              a manager who approves it on sight — and a queue that is always rubber-stamped teaches
              people that queues are rubber stamps.
            -->
            <VCol cols="12">
              <VSwitch
                v-model="form.timesheet_approval_required"
                :label="t('project_management.projects.field_timesheet_approval')"
                :hint="t('project_management.projects.hint_timesheet_approval')" persistent-hint
                color="primary" density="compact" inset
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editing = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" v-model="confirmDelete" max-width="460">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.projects.confirm_delete_title') }}</VCardTitle></VCardItem>
        <VCardText>{{ t('project_management.projects.confirm_delete_body') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDelete = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="destroying" @click="destroy">
            {{ t('project_management.actions.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
