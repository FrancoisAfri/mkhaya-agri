<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  kpi?: { totalActive: number; totalExpired: number; totalExpiringSoon: number; complianceRate: number }
  byCertification?: any[]
  expiringSoon?: any[]
  recentIssued?: any[]
  recentExpired?: any[]
  certifications?: any
  templates?: any
  courses?: any[]
  filters?: Record<string, any>
  activeTab?: string
}>()

const tab              = ref(props.activeTab ?? 'dashboard')
const running          = ref(false)
const certDialog       = ref(false)
const templateDialog   = ref(false)
const savingCert       = ref(false)
const savingTemplate   = ref(false)
const editingCert      = ref<any>(null)
const editingTemplate  = ref<any>(null)
const snackbar         = ref(false)
const snackbarText     = ref('')
const daysWindowLocal  = ref(props.filters?.days_window ?? 30)

const reminderOptions = [180, 90, 60, 30, 21, 14, 7, 3, 1]

const daysWindowOptions = [
  { v: 14, l: t('lms_certifications.days_window_options.14') },
  { v: 30, l: t('lms_certifications.days_window_options.30') },
  { v: 60, l: t('lms_certifications.days_window_options.60') },
  { v: 90, l: t('lms_certifications.days_window_options.90') },
]

const defaultCertForm = () => ({
  name: '', description: '', course_id: null as number | null,
  template_id: null as number | null,
  valid_days: null as number | null, refresher_before_days: 30,
  refresher_course_id: null as number | null,
  reminder_days: [90, 60, 30, 14, 7] as number[],
  actions_blocked_when_expired: [] as string[],
  requires_understanding_score: false, min_understanding_score: 70,
  auto_issue: true, is_active: true,
})
const certForm = ref(defaultCertForm())

const defaultTplForm = () => ({
  name: '', description: '', course_id: null as number | null,
  orientation: 'landscape', background_color: '#FFFFFF', accent_color: '#1E40AF',
  title_text: 'Certificate of Completion', subtitle_text: '', body_html: '',
  signatory_1_name: '', signatory_1_title: '', signatory_2_name: '', signatory_2_title: '',
  requires_understanding_score: false, min_understanding_score: 70, is_active: true,
})
const tplForm = ref(defaultTplForm())

const expiringSoonHeaders = [
  { title: t('lms_certifications.table.employee'), key: 'employee_name', sortable: false },
  { title: t('lms_certifications.table.certification'), key: 'certification', sortable: false },
  { title: t('lms_certifications.table.expires'), key: 'expires_at', sortable: true },
  { title: t('lms_certifications.table.days_left'), key: 'days_until_expiry', sortable: true },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

const certHeaders = [
  { title: t('lms_certifications.table.name'), key: 'name' },
  { title: t('lms_certifications.table.course'), key: 'course', sortable: false },
  { title: t('lms_certifications.table.validity'), key: 'valid_days', sortable: false },
  { title: t('lms_certifications.table.status'), key: 'is_active', sortable: false },
  { title: t('lms_certifications.table.stats'), key: 'stats', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

const templateHeaders = [
  { title: t('lms_certifications.table.name'), key: 'name' },
  { title: t('lms_certifications.table.orientation'), key: 'orientation', sortable: false },
  { title: t('lms_certifications.table.understanding'), key: 'requires_understanding_score', sortable: false },
  { title: t('lms_certifications.table.status'), key: 'is_active', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

const orientationItems = [
  { title: t('lms_certifications.dialog.landscape'), value: 'landscape' },
  { title: t('lms_certifications.dialog.portrait'), value: 'portrait' },
]

function rateColor(rate: number) {
  return rate >= 80 ? '#4CAF50' : rate >= 60 ? '#FF9800' : '#F44336'
}

function applyFilters() {
  router.get(route('lms.admin.certifications'), { days_window: daysWindowLocal.value }, { preserveState: true, replace: true })
}

function runChecks() {
  running.value = true
  router.post(route('lms.admin.certifications.run-checks'), {}, {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.checks_complete'); snackbar.value = true },
    onFinish:  () => { running.value = false },
    preserveScroll: true,
  })
}

function assignRefresher(item: any) {
  router.post(route('lms.admin.certifications.assign-refresher', item.id), {}, {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.refresher_assigned'); snackbar.value = true },
    preserveScroll: true,
  })
}

function toggleReminderDay(d: number) {
  const idx = certForm.value.reminder_days.indexOf(d)
  if (idx >= 0) certForm.value.reminder_days.splice(idx, 1)
  else certForm.value.reminder_days.push(d)
  certForm.value.reminder_days.sort((a, b) => b - a)
}

// Certifications
function openCreateCert() { editingCert.value = null; certForm.value = defaultCertForm(); certDialog.value = true }
function openEditCert(item: any) {
  editingCert.value = item
  certForm.value = {
    name: item.name, description: item.description ?? '',
    course_id: item.course_id, template_id: item.template_id,
    valid_days: item.valid_days, refresher_before_days: item.refresher_before_days ?? 30,
    refresher_course_id: item.refresher_course_id,
    reminder_days: item.reminder_days ?? [90, 60, 30, 14, 7],
    actions_blocked_when_expired: item.actions_blocked_when_expired ?? [],
    requires_understanding_score: item.requires_understanding_score,
    min_understanding_score: item.min_understanding_score ?? 70,
    auto_issue: item.auto_issue, is_active: item.is_active,
  }
  certDialog.value = true
}
function saveCert() {
  savingCert.value = true
  const url    = editingCert.value ? route('lms.admin.certifications.update', editingCert.value.id) : route('lms.admin.certifications.store')
  const method = editingCert.value ? 'patch' : 'post'
  router[method](url, certForm.value, {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.saved'); snackbar.value = true; certDialog.value = false },
    onFinish:  () => { savingCert.value = false },
    preserveScroll: true,
  })
}
function deleteCert(item: any) {
  router.delete(route('lms.admin.certifications.destroy', item.id), {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.deleted'); snackbar.value = true },
    preserveScroll: true,
  })
}

// Templates
function openCreateTemplate() { editingTemplate.value = null; tplForm.value = defaultTplForm(); templateDialog.value = true }
function openEditTemplate(item: any) {
  editingTemplate.value = item
  tplForm.value = {
    name: item.name, description: item.description ?? '', course_id: item.course_id,
    orientation: item.orientation ?? 'landscape',
    background_color: item.background_color ?? '#FFFFFF',
    accent_color: item.accent_color ?? '#1E40AF',
    title_text: item.title_text ?? '', subtitle_text: item.subtitle_text ?? '',
    body_html: item.body_html ?? '',
    signatory_1_name: item.signatory_1_name ?? '', signatory_1_title: item.signatory_1_title ?? '',
    signatory_2_name: item.signatory_2_name ?? '', signatory_2_title: item.signatory_2_title ?? '',
    requires_understanding_score: item.requires_understanding_score,
    min_understanding_score: item.min_understanding_score ?? 70,
    is_active: item.is_active,
  }
  templateDialog.value = true
}
function saveTemplate() {
  savingTemplate.value = true
  const url    = editingTemplate.value ? route('lms.admin.cert-templates.update', editingTemplate.value.id) : route('lms.admin.cert-templates.store')
  const method = editingTemplate.value ? 'patch' : 'post'
  router[method](url, tplForm.value, {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.saved'); snackbar.value = true; templateDialog.value = false },
    onFinish:  () => { savingTemplate.value = false },
    preserveScroll: true,
  })
}
function deleteTemplate(item: any) {
  router.delete(route('lms.admin.cert-templates.destroy', item.id), {
    onSuccess: () => { snackbarText.value = t('lms_certifications.toast.deleted'); snackbar.value = true },
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="p-3">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_certifications.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_certifications.subtitle') }}</div>
      </div>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="mdi-refresh" :loading="running" @click="runChecks">{{ t('lms_certifications.run_checks') }}</VBtn>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="dashboard">{{ t('lms_certifications.tabs.dashboard') }}</VTab>
      <VTab value="certifications">{{ t('lms_certifications.tabs.certifications') }}</VTab>
      <VTab value="templates">{{ t('lms_certifications.tabs.templates') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- ── Dashboard tab ── -->
      <VWindowItem value="dashboard">
        <VRow class="mb-4">
          <VCol cols="6" md="3">
            <VCard :color="kpi?.complianceRate >= 80 ? 'success' : kpi?.complianceRate >= 60 ? 'warning' : 'error'" variant="tonal">
              <VCardText class="text-center">
                <div class="text-h4 font-weight-bold">{{ kpi?.complianceRate }}%</div>
                <div class="text-caption mt-1">{{ t('lms_certifications.kpi.compliance_rate') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard color="success" variant="tonal">
              <VCardText class="text-center">
                <div class="text-h4 font-weight-bold">{{ kpi?.totalActive }}</div>
                <div class="text-caption mt-1">{{ t('lms_certifications.kpi.active_certificates') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard color="warning" variant="tonal">
              <VCardText class="text-center">
                <div class="text-h4 font-weight-bold">{{ kpi?.totalExpiringSoon }}</div>
                <div class="text-caption mt-1">{{ t('lms_certifications.kpi.expiring') }} ({{ filters?.days_window ?? 30 }}d)</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard color="error" variant="tonal">
              <VCardText class="text-center">
                <div class="text-h4 font-weight-bold">{{ kpi?.totalExpired }}</div>
                <div class="text-caption mt-1">{{ t('lms_certifications.kpi.expired') }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="7">
            <VCard class="mb-4">
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('lms_certifications.compliance_by_program') }}</VCardTitle>
              <VCardText class="pt-0">
                <div v-if="!byCertification?.length" class="text-medium-emphasis text-body-2">{{ t('lms_certifications.no_active_programs') }}</div>
                <div v-for="c in byCertification" :key="c.id" class="mb-3">
                  <div class="d-flex justify-space-between align-center mb-1">
                    <div>
                      <span class="text-body-2 font-weight-medium">{{ c.name }}</span>
                      <span class="text-caption text-medium-emphasis ml-2">{{ c.course_title }}</span>
                    </div>
                    <div class="d-flex align-center gap-2">
                      <VChip v-if="c.expired > 0" size="x-small" color="error">{{ t('lms_certifications.expired_label', { count: c.expired }) }}</VChip>
                      <VChip v-if="c.expiring > 0" size="x-small" color="warning">{{ t('lms_certifications.expiring_label', { count: c.expiring }) }}</VChip>
                      <span class="text-body-2 font-weight-bold" :style="{ color: rateColor(c.rate) }">{{ c.rate }}%</span>
                    </div>
                  </div>
                  <VProgressLinear :model-value="c.rate" :color="c.rate >= 80 ? 'success' : c.rate >= 60 ? 'warning' : 'error'" rounded height="6" bg-color="surface-variant" />
                  <div class="text-caption text-medium-emphasis mt-1">{{ t('lms_certifications.active_label', { active: c.active, total: c.total }) }}</div>
                </div>
              </VCardText>
            </VCard>

            <VCard>
              <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
                <span class="text-subtitle-1">{{ t('lms_certifications.expiring_soon') }}</span>
                <VChip v-if="expiringSoon?.length" color="warning" size="small">{{ expiringSoon?.length }}</VChip>
                <VSpacer />
                <VSelect v-model="daysWindowLocal" :items="daysWindowOptions" item-title="l" item-value="v" density="compact" hide-details style="max-width:130px" @update:model-value="applyFilters" />
              </VCardTitle>
              <VDataTable :headers="expiringSoonHeaders" :items="expiringSoon" hide-default-footer density="compact">
                <template #item.days_until_expiry="{ item }">
                  <VChip :color="item.days_until_expiry <= 14 ? 'error' : item.days_until_expiry <= 30 ? 'warning' : 'info'" size="x-small">{{ item.days_until_expiry }}d</VChip>
                </template>
                <template #item.actions="{ item }">
                  <VBtn v-if="item.has_refresher" size="x-small" variant="tonal" color="warning" @click="assignRefresher(item)">{{ t('lms_certifications.assign_refresher') }}</VBtn>
                </template>
              </VDataTable>
            </VCard>
          </VCol>

          <VCol cols="12" md="5">
            <VCard class="mb-4">
              <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
                <VIcon color="success">mdi-certificate</VIcon>
                <span class="text-subtitle-1">{{ t('lms_certifications.recently_issued') }}</span>
              </VCardTitle>
              <div v-if="!recentIssued?.length" class="text-center text-medium-emphasis py-4 text-body-2">{{ t('lms_certifications.no_recent_issues') }}</div>
              <VList v-else density="compact">
                <VListItem v-for="(r, i) in recentIssued" :key="i" rounded="lg" class="mx-2 mb-1">
                  <VListItemTitle class="text-body-2 font-weight-medium">{{ r.employee_name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ r.course_title }} · {{ r.created_at?.substring(0, 10) }}</VListItemSubtitle>
                </VListItem>
              </VList>
            </VCard>

            <VCard>
              <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
                <VIcon color="error">mdi-certificate-outline</VIcon>
                <span class="text-subtitle-1">{{ t('lms_certifications.recently_expired') }}</span>
              </VCardTitle>
              <div v-if="!recentExpired?.length" class="text-center text-medium-emphasis py-4 text-body-2">{{ t('lms_certifications.no_recent_expirations') }}</div>
              <VList v-else density="compact">
                <VListItem v-for="(r, i) in recentExpired" :key="i" rounded="lg" class="mx-2 mb-1">
                  <VListItemTitle class="text-body-2 font-weight-medium">{{ r.employee_name }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ r.course_title }} · {{ r.created_at?.substring(0, 10) }}</VListItemSubtitle>
                </VListItem>
              </VList>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ── Certifications tab ── -->
      <VWindowItem value="certifications">
        <div class="d-flex justify-end mb-4">
          <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreateCert">{{ t('lms_certifications.new_program') }}</VBtn>
        </div>
        <VCard>
          <VDataTable :headers="certHeaders" :items="certifications?.data ?? []" hide-default-footer density="compact">
            <template #item.course="{ item }">
              <span class="text-body-2">{{ item.course?.title }}</span>
            </template>
            <template #item.valid_days="{ item }">
              <span class="text-caption">{{ item.valid_days ? item.valid_days + ' ' + t('lms_certifications.table.days') : t('lms_certifications.table.no_expiry') }}</span>
            </template>
            <template #item.stats="{ item }">
              <div class="d-flex gap-1">
                <VChip size="x-small" color="success" variant="tonal">{{ item.active_count ?? 0 }} {{ t('lms_certifications.table.active') }}</VChip>
                <VChip v-if="item.expired_count" size="x-small" color="error" variant="tonal">{{ item.expired_count }} {{ t('lms_certifications.kpi.expired') }}</VChip>
              </div>
            </template>
            <template #item.is_active="{ item }">
              <VChip :color="item.is_active ? 'success' : 'grey'" size="x-small">{{ item.is_active ? t('lms_certifications.table.active') : t('lms_certifications.table.inactive') }}</VChip>
            </template>
            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn icon size="x-small" variant="text" @click="openEditCert(item)"><VIcon size="16">mdi-pencil</VIcon></VBtn>
                <VBtn icon size="x-small" variant="text" color="error" @click="deleteCert(item)"><VIcon size="16">mdi-delete</VIcon></VBtn>
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- ── Templates tab ── -->
      <VWindowItem value="templates">
        <div class="d-flex justify-end mb-4">
          <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreateTemplate">{{ t('lms_certifications.new_template') }}</VBtn>
        </div>
        <VCard>
          <VDataTable :headers="templateHeaders" :items="templates?.data ?? []" hide-default-footer density="compact">
            <template #item.orientation="{ item }">
              <VChip size="x-small" color="primary" variant="tonal">{{ item.orientation }}</VChip>
            </template>
            <template #item.requires_understanding_score="{ item }">
              <VChip :color="item.requires_understanding_score ? 'warning' : 'grey'" size="x-small">
                {{ item.requires_understanding_score ? `≥${item.min_understanding_score}%` : t('lms_certifications.table.not_required') }}
              </VChip>
            </template>
            <template #item.is_active="{ item }">
              <VChip :color="item.is_active ? 'success' : 'grey'" size="x-small">{{ item.is_active ? t('lms_certifications.table.active') : t('lms_certifications.table.inactive') }}</VChip>
            </template>
            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn icon size="x-small" variant="text" @click="openEditTemplate(item)"><VIcon size="16">mdi-pencil</VIcon></VBtn>
                <VBtn icon size="x-small" variant="text" color="error" @click="deleteTemplate(item)"><VIcon size="16">mdi-delete</VIcon></VBtn>
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

    </VWindow>

    <!-- Certification program dialog -->
    <VDialog v-model="certDialog" max-width="680" scrollable>
      <VCard>
        <VCardTitle class="pa-4">{{ editingCert ? t('lms_certifications.dialog.cert_title_edit') : t('lms_certifications.dialog.cert_title_new') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model="certForm.name" :label="t('lms_certifications.dialog.program_name')" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="certForm.description" :label="t('lms_certifications.dialog.description')" density="compact" rows="2" /></VCol>
            <VCol cols="12">
              <VSelect v-model="certForm.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_certifications.dialog.course')" density="compact" :disabled="!!editingCert" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="certForm.template_id" :items="[{id:null,name:t('lms_certifications.dialog.no_template')},...(templates?.data??[])]" item-title="name" item-value="id" :label="t('lms_certifications.dialog.template')" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="certForm.valid_days" :label="t('lms_certifications.dialog.valid_days')" type="number" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="certForm.refresher_before_days" :label="t('lms_certifications.dialog.refresher_before')" type="number" density="compact" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="certForm.refresher_course_id" :items="[{id:null,title:t('lms_certifications.dialog.no_refresher')},...courses]" item-title="title" item-value="id" :label="t('lms_certifications.dialog.refresher_course')" density="compact" />
            </VCol>
            <VCol cols="12">
              <div class="text-body-2 mb-1">{{ t('lms_certifications.dialog.reminder_days') }}</div>
              <div class="d-flex gap-2 flex-wrap">
                <VChip v-for="d in reminderOptions" :key="d"
                  :color="certForm.reminder_days.includes(d) ? 'primary' : 'default'"
                  :variant="certForm.reminder_days.includes(d) ? 'flat' : 'tonal'"
                  size="small" style="cursor:pointer" @click="toggleReminderDay(d)">{{ d }}d</VChip>
              </div>
            </VCol>
            <VCol cols="12">
              <VCombobox v-model="certForm.actions_blocked_when_expired" :label="t('lms_certifications.dialog.actions_blocked')" density="compact" multiple chips closable-chips :hint="t('lms_certifications.dialog.actions_blocked_hint')" persistent-hint />
            </VCol>
            <VCol cols="6">
              <VSwitch v-model="certForm.requires_understanding_score" :label="t('lms_certifications.dialog.require_understanding')" color="primary" density="compact" />
            </VCol>
            <VCol cols="6" v-if="certForm.requires_understanding_score">
              <VTextField v-model.number="certForm.min_understanding_score" :label="t('lms_certifications.dialog.min_score')" type="number" density="compact" />
            </VCol>
            <VCol cols="6"><VSwitch v-model="certForm.auto_issue" :label="t('lms_certifications.dialog.auto_issue')" color="primary" density="compact" /></VCol>
            <VCol cols="6"><VSwitch v-model="certForm.is_active" :label="t('lms_certifications.dialog.active')" color="primary" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="certDialog = false">{{ t('lms_certifications.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingCert" @click="saveCert">{{ editingCert ? t('lms_certifications.dialog.update') : t('lms_certifications.dialog.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Template dialog -->
    <VDialog v-model="templateDialog" max-width="680" scrollable>
      <VCard>
        <VCardTitle class="pa-4">{{ editingTemplate ? t('lms_certifications.dialog.template_title_edit') : t('lms_certifications.dialog.template_title_new') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model="tplForm.name" :label="t('lms_certifications.dialog.template_name')" density="compact" /></VCol>
            <VCol cols="6">
              <VSelect v-model="tplForm.orientation" :items="orientationItems" item-title="title" item-value="value" :label="t('lms_certifications.dialog.orientation')" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="tplForm.title_text" :label="t('lms_certifications.dialog.title_text')" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="tplForm.background_color" :label="t('lms_certifications.dialog.background_color')" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="tplForm.accent_color" :label="t('lms_certifications.dialog.accent_color')" density="compact" />
            </VCol>
            <VCol cols="12"><VTextarea v-model="tplForm.body_html" :label="t('lms_certifications.dialog.body_html')" density="compact" rows="4" :hint="t('lms_certifications.dialog.body_html_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model="tplForm.signatory_1_name" :label="t('lms_certifications.dialog.signatory_1_name')" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="tplForm.signatory_1_title" :label="t('lms_certifications.dialog.signatory_1_title')" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="tplForm.signatory_2_name" :label="t('lms_certifications.dialog.signatory_2_name')" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="tplForm.signatory_2_title" :label="t('lms_certifications.dialog.signatory_2_title')" density="compact" /></VCol>
            <VCol cols="6"><VSwitch v-model="tplForm.requires_understanding_score" :label="t('lms_certifications.dialog.require_understanding')" color="primary" density="compact" /></VCol>
            <VCol cols="6" v-if="tplForm.requires_understanding_score">
              <VTextField v-model.number="tplForm.min_understanding_score" :label="t('lms_certifications.dialog.min_score')" type="number" density="compact" />
            </VCol>
            <VCol cols="6"><VSwitch v-model="tplForm.is_active" :label="t('lms_certifications.dialog.active')" color="primary" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="templateDialog = false">{{ t('lms_certifications.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingTemplate" @click="saveTemplate">{{ editingTemplate ? t('lms_certifications.dialog.update') : t('lms_certifications.dialog.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="3000" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>
