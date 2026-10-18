<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type AssetImage = { id: number; url: string; original_name: string; sort_order: number }
type Asset = {
  id: number; uuid: string; asset_number: string | null; asset_tag: string | null
  name: string; description: string | null; images: AssetImage[]
  serial_number: string | null; model_number: string | null; make_number: string | null
  make: { id: number; name: string } | null; model: { id: number; name: string } | null
  barcode: string | null; status: { value: string; label: string; color: string }
  condition: { value: string; label: string; color: string } | null
  purchase_cost: string | null; purchase_date: string | null
  warranty_expiry: string | null; retention_until: string | null
  last_audited_at: string | null; asset_type: { id: number; name: string } | null
  category: { id: number; name: string } | null; location: { id: number; name: string } | null
  current_assignment: { id: number; assignee: string | null; effective_date: string | null; return_due_date: string | null } | null
}

type Assignment = { id: number; uuid: string; assignment_type: string; effective_date: string | null; return_due_date: string | null; returned_at: string | null; is_current: boolean; notes: string | null; assignee: { id: number; name: string } | null; assigned_by: { id: number; name: string } | null; location: { id: number; name: string } | null; created_at: string }
type Maintenance = { id: number; uuid: string; title: string; maintenance_type: string; status: { value: string; label: string; color: string }; due_date: string | null; estimated_cost: string | null; actual_cost: string | null; assigned_to: { id: number; name: string } | null }
type Incident = { id: number; title: string; severity: string; is_resolved: boolean; occurred_at: string | null }
type CustodyEntry = { id: number; action: string; notes: string | null; occurred_at: string | null }

const props = defineProps<{
  asset: Asset; assignments: Assignment[]; maintenance: Maintenance[]
  incidents: Incident[]; custody_log: CustodyEntry[]
  locations: { id: number; name: string }[]; users: { id: number; name: string; email: string }[]
}>()

const activeTab = ref('overview')
const lightboxOpen = ref(false)
const lightboxIndex = ref(0)
function openLightbox(index: number) { lightboxIndex.value = index; lightboxOpen.value = true }

const showAssignDialog = ref(false)
const assignForm = useForm({ assigned_to: '', location_id: null as number | null, assignment_type: 'permanent', effective_date: new Date().toISOString().slice(0, 10), end_date: '', notes: '' })
function submitAssign() { assignForm.post(route('assets.assign', props.asset.uuid), { onSuccess: () => { showAssignDialog.value = false; assignForm.reset() } }) }
function userFilter(_value: string, query: string, item: any): boolean {
  const q = (query || '').toLowerCase().trim(); if (!q) return true
  const raw = item?.raw ?? {}
  return String(raw.name || '').toLowerCase().includes(q) || String(raw.email || '').toLowerCase().includes(q)
}

const showMaintenanceDialog = ref(false)
const maintenanceForm = useForm({ title: '', description: '', maintenance_type: 'preventive', due_date: '', estimated_cost: '', vendor_name: '', recurrence_days: '', notes: '' })
function submitMaintenance() { maintenanceForm.post(route('assets.maintenance.store', props.asset.uuid), { onSuccess: () => { showMaintenanceDialog.value = false; maintenanceForm.reset() } }) }

const showIncidentDialog = ref(false)
const incidentForm = useForm({ title: '', description: '', severity: 'medium', repair_cost: '', condition_after: '', occurred_at: new Date().toISOString().slice(0, 10) })
function submitIncident() { incidentForm.post(route('assets.incidents.store', props.asset.uuid), { onSuccess: () => { showIncidentDialog.value = false; incidentForm.reset() } }) }

const resolvingId = ref<number | null>(null)
function resolveIncident(incidentId: number) { resolvingId.value = incidentId; router.patch(route('assets.incidents.resolve', [props.asset.uuid, incidentId]), {}, { preserveScroll: true, onFinish: () => { resolvingId.value = null } }) }

const showMovementDialog = ref(false)
const movementForm = useForm({ to_location_id: null as number | null, reason: '' })
function submitMovement() { movementForm.post(route('assets.movements.store', props.asset.uuid), { onSuccess: () => { showMovementDialog.value = false; movementForm.reset() } }) }

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function formatCurrency(val: string | null) { if (val == null) return '—'; return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val)) }
function severityColor(s: string) { return { low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'default' }

const assignmentTypeItems = computed(() => [
  { title: t('assets_show.assignment_types.permanent'), value: 'permanent' },
  { title: t('assets_show.assignment_types.temporary'), value: 'temporary' },
  { title: t('assets_show.assignment_types.loan'), value: 'loan' },
])
const maintenanceTypeItems = computed(() => [
  { title: t('assets_show.maintenance_types.preventive'), value: 'preventive' },
  { title: t('assets_show.maintenance_types.corrective'), value: 'corrective' },
  { title: t('assets_show.maintenance_types.predictive'), value: 'predictive' },
  { title: t('assets_show.maintenance_types.calibration'), value: 'calibration' },
  { title: t('assets_show.maintenance_types.inspection'), value: 'inspection' },
])
const severityItems = computed(() => [
  { title: t('assets_show.severities.low'), value: 'low' },
  { title: t('assets_show.severities.medium'), value: 'medium' },
  { title: t('assets_show.severities.high'), value: 'high' },
  { title: t('assets_show.severities.critical'), value: 'critical' },
])
const conditionItems = computed(() => [
  { title: t('assets_create.conditions.new'), value: 'new' },
  { title: t('assets_create.conditions.good'), value: 'good' },
  { title: t('assets_create.conditions.fair'), value: 'fair' },
  { title: t('assets_create.conditions.poor'), value: 'poor' },
  { title: t('assets_create.conditions.damaged'), value: 'damaged' },
  { title: t('assets_create.conditions.irreparable'), value: 'irreparable' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-5">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('assets.index'))" />
      <span class="text-body-2 text-medium-emphasis cursor-pointer" @click="router.visit(route('assets.index'))">{{ t('assets_show.back') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ asset.name }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">{{ asset.name }}</h4>
          <VChip :color="asset.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ asset.status.label }}</VChip>
          <VChip v-if="asset.condition" :color="asset.condition.color" size="small" label variant="tonal" class="font-weight-medium">{{ asset.condition.label }}</VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ asset.asset_number ?? asset.asset_tag ?? t('assets_show.no_tag') }}
          <span v-if="asset.category"> · {{ asset.category.name }}</span>
          <span v-if="asset.make"> · {{ asset.make.name }}</span>
          <span v-if="asset.model"> {{ asset.model.name }}</span>
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" color="primary" prepend-icon="tabler-user-share" @click="showAssignDialog = true">{{ t('assets_show.actions.assign') }}</VBtn>
        <VBtn variant="tonal" color="info" prepend-icon="tabler-tool" @click="showMaintenanceDialog = true">{{ t('assets_show.actions.schedule_maintenance') }}</VBtn>
        <VBtn variant="tonal" color="warning" prepend-icon="tabler-alert-triangle" @click="showIncidentDialog = true">{{ t('assets_show.actions.report_incident') }}</VBtn>
        <VBtn variant="tonal" color="secondary" prepend-icon="tabler-route" @click="showMovementDialog = true">{{ t('assets_show.actions.move_asset') }}</VBtn>
      </div>
    </div>

    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="overview">{{ t('assets_show.tabs.overview') }}</VTab>
      <VTab value="assignments">{{ t('assets_show.tabs.assignments') }} <VChip size="x-small" class="ms-2">{{ assignments.length }}</VChip></VTab>
      <VTab value="maintenance">{{ t('assets_show.tabs.maintenance') }} <VChip size="x-small" class="ms-2">{{ maintenance.length }}</VChip></VTab>
      <VTab value="incidents">{{ t('assets_show.tabs.incidents') }} <VChip size="x-small" class="ms-2">{{ incidents.length }}</VChip></VTab>
      <VTab value="history">{{ t('assets_show.tabs.history') }}</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="overview">
        <VCard v-if="asset.images?.length" elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.images.title') }} <VChip size="x-small" class="ms-2">{{ asset.images.length }}</VChip></VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div class="d-flex flex-wrap gap-3">
              <div v-for="(img, i) in asset.images" :key="img.id" class="rounded-lg overflow-hidden cursor-pointer flex-shrink-0" style="width:120px; height:120px; position:relative;" @click="openLightbox(i)">
                <img :src="img.url" :alt="img.original_name" style="width:100%; height:100%; object-fit:cover;" />
                <div class="position-absolute inset-0 d-flex align-center justify-center" style="background:rgba(0,0,0,0); transition:background 0.2s;" @mouseenter="($event.currentTarget as HTMLElement).style.background='rgba(0,0,0,0.3)'" @mouseleave="($event.currentTarget as HTMLElement).style.background='rgba(0,0,0,0)'">
                  <VIcon icon="tabler-zoom-in" color="white" size="24" style="opacity:0.9;" />
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VDialog v-model="lightboxOpen" max-width="900" :scrim-opacity="0.85">
          <VCard color="surface">
            <VCardTitle class="pa-4 d-flex align-center justify-space-between">
              <span class="text-body-2">{{ asset.images[lightboxIndex]?.original_name }}</span>
              <div class="d-flex align-center gap-2"><span class="text-caption text-medium-emphasis">{{ lightboxIndex + 1 }} / {{ asset.images.length }}</span><VBtn icon="tabler-x" variant="text" size="small" @click="lightboxOpen = false" /></div>
            </VCardTitle>
            <VCardText class="pa-0 d-flex align-center justify-center" style="min-height:400px; max-height:70vh; overflow:hidden;">
              <img :src="asset.images[lightboxIndex]?.url" :alt="asset.images[lightboxIndex]?.original_name" style="max-width:100%; max-height:70vh; object-fit:contain;" />
            </VCardText>
            <VCardActions v-if="asset.images.length > 1" class="pa-4 justify-center gap-3">
              <VBtn icon="tabler-chevron-left" variant="tonal" :disabled="lightboxIndex === 0" @click="lightboxIndex--" />
              <VBtn icon="tabler-chevron-right" variant="tonal" :disabled="lightboxIndex === asset.images.length - 1" @click="lightboxIndex++" />
            </VCardActions>
          </VCard>
        </VDialog>

        <VRow>
          <VCol cols="12" md="8">
            <VCard elevation="0" border class="mb-4">
              <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.details.title') }}</VCardTitle>
              <VCardText class="pa-5 pt-0">
                <VRow>
                  <VCol v-if="asset.description" cols="12"><p class="text-body-2 text-medium-emphasis">{{ asset.description }}</p><VDivider class="mb-4" /></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.serial_number') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.serial_number ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.make') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.make?.name ?? asset.make_number ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.model') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.model?.name ?? asset.model_number ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.type') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.asset_type?.name ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.category') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.category?.name ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.location') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.location?.name ?? '—' }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.purchase_cost') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatCurrency(asset.purchase_cost) }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.purchase_date') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(asset.purchase_date) }}</p></VCol>
                  <VCol cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.warranty_expiry') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(asset.warranty_expiry) }}</p></VCol>
                  <VCol v-if="asset.barcode" cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.barcode') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ asset.barcode }}</p></VCol>
                  <VCol v-if="asset.last_audited_at" cols="6" sm="4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_show.details.last_audited') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(asset.last_audited_at) }}</p></VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="4">
            <VCard elevation="0" border class="mb-4">
              <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.current_assignment.title') }}</VCardTitle>
              <VCardText class="pa-5 pt-0">
                <div v-if="asset.current_assignment">
                  <div class="d-flex align-center gap-2 mb-3"><VAvatar size="40" color="primary" variant="tonal"><VIcon icon="tabler-user" size="20" /></VAvatar><div><p class="text-body-2 font-weight-semibold mb-0">{{ asset.current_assignment.assignee ?? 'Unknown' }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('assets_show.current_assignment.since', { date: formatDate(asset.current_assignment.effective_date) }) }}</p></div></div>
                  <div v-if="asset.current_assignment.return_due_date" class="mb-1"><p class="text-caption text-medium-emphasis mb-0">{{ t('assets_show.current_assignment.ends', { date: formatDate(asset.current_assignment.return_due_date) }) }}</p></div>
                </div>
                <div v-else class="text-center py-4">
                  <VIcon icon="tabler-inbox" size="40" color="medium-emphasis" class="mb-2" />
                  <p class="text-body-2 text-medium-emphasis mb-3">{{ t('assets_show.current_assignment.not_assigned') }}</p>
                  <VBtn size="small" color="primary" variant="tonal" @click="showAssignDialog = true">{{ t('assets_show.current_assignment.assign_now') }}</VBtn>
                </div>
              </VCardText>
            </VCard>

            <VCard elevation="0" border>
              <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.compliance.title') }}</VCardTitle>
              <VCardText class="pa-5 pt-0">
                <div class="d-flex flex-column gap-3">
                  <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('assets_show.compliance.retain_until') }}</span><span class="text-body-2">{{ formatDate(asset.retention_until) }}</span></div>
                  <div v-if="asset.last_audited_at" class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('assets_show.compliance.last_audit') }}</span><span class="text-body-2">{{ formatDate(asset.last_audited_at) }}</span></div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem value="assignments">
        <VCard elevation="0" border>
          <VCardText v-if="assignments.length" class="pa-0">
            <VTable>
              <thead><tr><th>{{ t('assets_show.assignments_tab.table.assigned_to') }}</th><th>{{ t('assets_show.assignments_tab.table.type') }}</th><th>{{ t('assets_show.assignments_tab.table.location') }}</th><th>{{ t('assets_show.assignments_tab.table.effective') }}</th><th>{{ t('assets_show.assignments_tab.table.end_date') }}</th><th>{{ t('assets_show.assignments_tab.table.status') }}</th><th>{{ t('assets_show.assignments_tab.table.by') }}</th></tr></thead>
              <tbody>
                <tr v-for="assignment in assignments" :key="assignment.id">
                  <td><span class="text-body-2 font-weight-medium">{{ assignment.assignee?.name ?? '—' }}</span></td>
                  <td><span class="text-body-2 text-capitalize">{{ assignment.assignment_type }}</span></td>
                  <td><span class="text-body-2">{{ assignment.location?.name ?? '—' }}</span></td>
                  <td><span class="text-body-2">{{ formatDate(assignment.effective_date) }}</span></td>
                  <td><span class="text-body-2">{{ formatDate(assignment.return_due_date) }}</span></td>
                  <td><VChip :color="assignment.is_current ? 'success' : 'default'" size="x-small" label variant="tonal">{{ assignment.is_current ? t('assets_show.assignments_tab.active') : t('assets_show.assignments_tab.historical') }}</VChip></td>
                  <td><span class="text-body-2">{{ assignment.assigned_by?.name ?? '—' }}</span></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-user-share" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_show.assignments_tab.empty_title') }}</p>
            <VBtn color="primary" variant="tonal" @click="showAssignDialog = true">{{ t('assets_show.assignments_tab.empty_button') }}</VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="maintenance">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-3 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('assets_show.tabs.maintenance') }}</span><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="showMaintenanceDialog = true">{{ t('assets_show.maintenance_tab.schedule') }}</VBtn></VCardTitle>
          <VCardText v-if="maintenance.length" class="pa-0">
            <VTable>
              <thead><tr><th>{{ t('assets_show.maintenance_tab.table.title') }}</th><th>{{ t('assets_show.maintenance_tab.table.type') }}</th><th>{{ t('assets_show.maintenance_tab.table.status') }}</th><th>{{ t('assets_show.maintenance_tab.table.due_date') }}</th><th>{{ t('assets_show.maintenance_tab.table.est_cost') }}</th><th>{{ t('assets_show.maintenance_tab.table.assigned_to') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in maintenance" :key="m.id">
                  <td><span class="text-body-2 font-weight-medium">{{ m.title }}</span></td>
                  <td><span class="text-body-2 text-capitalize">{{ m.maintenance_type?.replace('_', ' ') }}</span></td>
                  <td><VChip :color="m.status.color" size="x-small" label variant="tonal">{{ m.status.label }}</VChip></td>
                  <td><span class="text-body-2">{{ formatDate(m.due_date) }}</span></td>
                  <td><span class="text-body-2">{{ formatCurrency(m.estimated_cost) }}</span></td>
                  <td><span class="text-body-2">{{ m.assigned_to?.name ?? '—' }}</span></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-tool" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_show.maintenance_tab.empty_title') }}</p>
            <VBtn color="primary" variant="tonal" @click="showMaintenanceDialog = true">{{ t('assets_show.maintenance_tab.empty_button') }}</VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="incidents">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-3 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('assets_show.tabs.incidents') }}</span><VBtn size="small" color="warning" variant="tonal" prepend-icon="tabler-plus" @click="showIncidentDialog = true">{{ t('assets_show.incidents_tab.report') }}</VBtn></VCardTitle>
          <VCardText v-if="incidents.length" class="pa-0">
            <VTable>
              <thead><tr><th>{{ t('assets_show.incidents_tab.table.title') }}</th><th>{{ t('assets_show.incidents_tab.table.severity') }}</th><th>{{ t('assets_show.incidents_tab.table.occurred') }}</th><th>{{ t('assets_show.incidents_tab.table.status') }}</th><th>{{ t('assets_show.incidents_tab.table.actions') }}</th></tr></thead>
              <tbody>
                <tr v-for="inc in incidents" :key="inc.id">
                  <td><span class="text-body-2 font-weight-medium">{{ inc.title }}</span></td>
                  <td><VChip :color="severityColor(inc.severity)" size="x-small" label variant="tonal" class="text-capitalize">{{ inc.severity }}</VChip></td>
                  <td><span class="text-body-2">{{ formatDate(inc.occurred_at) }}</span></td>
                  <td><VChip :color="inc.is_resolved ? 'success' : 'warning'" size="x-small" label variant="tonal">{{ inc.is_resolved ? t('assets_show.incidents_tab.resolved') : t('assets_show.incidents_tab.open') }}</VChip></td>
                  <td><VBtn v-if="!inc.is_resolved" size="x-small" variant="tonal" color="success" :loading="resolvingId === inc.id" @click.stop="resolveIncident(inc.id)">{{ t('assets_show.incidents_tab.resolve') }}</VBtn></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-shield-check" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_show.incidents_tab.empty_title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('assets_show.incidents_tab.empty_description') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="history">
        <VCard elevation="0" border>
          <VCardText v-if="custody_log.length" class="pa-5">
            <div class="d-flex flex-column gap-3">
              <div v-for="entry in custody_log" :key="entry.id" class="d-flex align-start gap-3">
                <div class="d-flex align-center justify-center rounded-circle flex-shrink-0" style="width:36px; height:36px; background: rgba(115,103,240,0.12);"><VIcon icon="tabler-history" size="18" color="primary" /></div>
                <div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-0 text-capitalize">{{ entry.action.replace('_', ' ') }}</p><p v-if="entry.notes" class="text-caption text-medium-emphasis mb-0">{{ entry.notes }}</p></div>
                <span class="text-caption text-medium-emphasis flex-shrink-0">{{ formatDate(entry.occurred_at) }}</span>
              </div>
            </div>
          </VCardText>
          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-history" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_show.history_tab.empty_title') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Assign Dialog -->
    <VDialog v-model="showAssignDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.dialog.assign_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VForm @submit.prevent="submitAssign">
            <VRow>
              <VCol cols="12"><VAutocomplete v-model="assignForm.assigned_to" :items="users" item-title="name" item-value="id" :label="t('assets_show.dialog.assign_to')" :placeholder="t('assets_show.dialog.search_placeholder')" :custom-filter="userFilter" :error-messages="assignForm.errors.assigned_to" density="comfortable" variant="outlined" clearable><template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :title="item.raw.name" :subtitle="item.raw.email" /></template></VAutocomplete></VCol>
              <VCol cols="12" md="6"><VSelect v-model="assignForm.assignment_type" :items="assignmentTypeItems" item-title="title" item-value="value" :label="t('assets_show.dialog.assignment_type')" :error-messages="assignForm.errors.assignment_type" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VSelect v-model="assignForm.location_id" :items="locations" item-title="name" item-value="id" :label="t('assets_show.dialog.location')" :error-messages="assignForm.errors.location_id" density="comfortable" variant="outlined" clearable /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="assignForm.effective_date" :label="t('assets_show.dialog.effective_date')" type="date" :error-messages="assignForm.errors.effective_date" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="assignForm.end_date" :label="t('assets_show.dialog.end_date')" type="date" :error-messages="assignForm.errors.end_date" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12"><VTextarea v-model="assignForm.notes" :label="t('assets_show.dialog.notes')" rows="2" :error-messages="assignForm.errors.notes" variant="outlined" /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="showAssignDialog = false">{{ t('assets_show.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="assignForm.processing" @click="submitAssign">{{ t('assets_show.dialog.assign_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Incident Dialog -->
    <VDialog v-model="showIncidentDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.dialog.incident_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VForm @submit.prevent="submitIncident">
            <VRow>
              <VCol cols="12"><VTextField v-model="incidentForm.title" :label="t('assets_show.dialog.incident_title_field')" :error-messages="incidentForm.errors.title" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VSelect v-model="incidentForm.severity" :items="severityItems" item-title="title" item-value="value" :label="t('assets_show.dialog.severity')" :error-messages="incidentForm.errors.severity" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="incidentForm.occurred_at" :label="t('assets_show.dialog.occurred_at')" type="date" :error-messages="incidentForm.errors.occurred_at" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="incidentForm.repair_cost" :label="t('assets_show.dialog.repair_cost')" type="number" :error-messages="incidentForm.errors.repair_cost" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VSelect v-model="incidentForm.condition_after" :items="conditionItems" item-title="title" item-value="value" :label="t('assets_show.dialog.condition_after')" :error-messages="incidentForm.errors.condition_after" density="comfortable" variant="outlined" clearable /></VCol>
              <VCol cols="12"><VTextarea v-model="incidentForm.description" :label="t('assets_show.dialog.description')" rows="3" :error-messages="incidentForm.errors.description" variant="outlined" /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="showIncidentDialog = false">{{ t('assets_show.dialog.cancel') }}</VBtn><VBtn color="warning" :loading="incidentForm.processing" @click="submitIncident">{{ t('assets_show.dialog.report_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Movement Dialog -->
    <VDialog v-model="showMovementDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.dialog.move_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VForm @submit.prevent="submitMovement">
            <VRow>
              <VCol cols="12"><VSelect v-model="movementForm.to_location_id" :items="locations" item-title="name" item-value="id" :label="t('assets_show.dialog.move_to')" :error-messages="movementForm.errors.to_location_id" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12"><VTextarea v-model="movementForm.reason" :label="t('assets_show.dialog.reason')" rows="2" :error-messages="movementForm.errors.reason" variant="outlined" /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="showMovementDialog = false">{{ t('assets_show.dialog.cancel') }}</VBtn><VBtn color="secondary" :loading="movementForm.processing" @click="submitMovement">{{ t('assets_show.dialog.move_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Maintenance Dialog -->
    <VDialog v-model="showMaintenanceDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('assets_show.dialog.maintenance_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-5">
          <VForm @submit.prevent="submitMaintenance">
            <VRow>
              <VCol cols="12"><VTextField v-model="maintenanceForm.title" :label="t('assets_show.dialog.maintenance_title_field')" :error-messages="maintenanceForm.errors.title" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VSelect v-model="maintenanceForm.maintenance_type" :items="maintenanceTypeItems" item-title="title" item-value="value" :label="t('assets_show.dialog.maintenance_type')" :error-messages="maintenanceForm.errors.maintenance_type" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="maintenanceForm.due_date" :label="t('assets_show.dialog.due_date')" type="date" :error-messages="maintenanceForm.errors.due_date" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="maintenanceForm.estimated_cost" :label="t('assets_show.dialog.estimated_cost')" type="number" :error-messages="maintenanceForm.errors.estimated_cost" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="maintenanceForm.vendor_name" :label="t('assets_show.dialog.vendor')" :error-messages="maintenanceForm.errors.vendor_name" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12" md="6"><VTextField v-model="maintenanceForm.recurrence_days" :label="t('assets_show.dialog.recurrence')" type="number" :placeholder="t('assets_show.dialog.recurrence_placeholder')" :error-messages="maintenanceForm.errors.recurrence_days" density="comfortable" variant="outlined" /></VCol>
              <VCol cols="12"><VTextarea v-model="maintenanceForm.description" :label="t('assets_show.dialog.description')" rows="2" :error-messages="maintenanceForm.errors.description" variant="outlined" /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="showMaintenanceDialog = false">{{ t('assets_show.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="maintenanceForm.processing" @click="submitMaintenance">{{ t('assets_show.dialog.schedule_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
