<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  kpi: { totalActive: number; totalOverdue: number; totalDone: number; complianceRate: number }
  overdue: any
  escalations: any[]
  byRule: any[]
  reports: any[]
  positions: any[]
  filters: Record<string, any>
}>()

const running       = ref(false)
const waiving       = ref(false)
const generating    = ref(false)
const waiveDialog   = ref(false)
const reportDialog  = ref(false)
const snackbar      = ref(false)
const snackbarText  = ref('')
const waiveReason   = ref('')
const waivingItem   = ref<any>(null)
const filterPosition= ref(props.filters.position_id ?? null)

const reportForm = ref({ report_type: 'overall', scope_id: null as number | null, period_start: '', period_end: '' })
const reportTypeItems = [
  { value: 'overall',    label: t('lms_compliance.dialog.report_type_overall') },
  { value: 'position',   label: t('lms_compliance.dialog.report_type_position') },
  { value: 'department', label: t('lms_compliance.dialog.report_type_department') },
]

const overdueHeaders = [
  { title: t('lms_compliance.table.employee'), key: 'employee',    sortable: false },
  { title: t('lms_compliance.table.course'),   key: 'course.title',sortable: false },
  { title: t('lms_compliance.table.rule'),     key: 'rule.name',   sortable: false },
  { title: t('lms_compliance.table.due_date'), key: 'due_date',    sortable: true },
  { title: t('lms_compliance.table.overdue'),  key: 'days_overdue',sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

function personName(user: any) {
  const p = user?.person
  return p ? `${p.first_name} ${p.surname}` : user?.email ?? '—'
}

function calcDaysOverdue(dueDate: string) {
  if (!dueDate) return 0
  const days = Math.floor((Date.now() - new Date(dueDate).getTime()) / 86400000)
  return Math.max(0, days)
}

function rateColor(rate: number) {
  return rate >= 80 ? '#4CAF50' : rate >= 60 ? '#FF9800' : '#F44336'
}

function applyFilters() {
  router.get(route('lms.admin.compliance'), { position_id: filterPosition.value }, { preserveState: true, replace: true })
}

function goPage(page: number) {
  router.get(route('lms.admin.compliance'), { ...props.filters, page }, { preserveState: true })
}

function runChecks() {
  running.value = true
  router.post(route('lms.admin.compliance.run-checks'), {}, {
    onSuccess: () => { snackbarText.value = t('lms_compliance.toast.checks_complete'); snackbar.value = true },
    onFinish:  () => { running.value = false },
    preserveScroll: true,
  })
}

function openWaive(item: any) {
  waivingItem.value  = item
  waiveReason.value  = ''
  waiveDialog.value  = true
}

function submitWaive() {
  if (!waivingItem.value || !waiveReason.value) return
  waiving.value = true
  router.patch(
    route('lms.admin.compliance.assignments.waive', waivingItem.value.id),
    { reason: waiveReason.value },
    {
      onSuccess: () => { snackbarText.value = t('lms_compliance.toast.assignment_waived'); snackbar.value = true; waiveDialog.value = false },
      onFinish:  () => { waiving.value = false },
      preserveScroll: true,
    }
  )
}

function acknowledgeEsc(id: number) {
  router.patch(route('lms.admin.compliance.escalations.acknowledge', id), {}, {
    onSuccess: () => { snackbarText.value = t('lms_compliance.toast.escalation_acknowledged'); snackbar.value = true },
    preserveScroll: true,
  })
}

function generateReport() {
  generating.value = true
  router.post(route('lms.admin.compliance.reports.generate'), reportForm.value, {
    onSuccess: () => { snackbarText.value = t('lms_compliance.toast.report_generated'); snackbar.value = true; reportDialog.value = false },
    onFinish:  () => { generating.value = false },
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="p-2">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap ml-4">
      <div>
        <div class="text-h6">{{ t('lms_compliance.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_compliance.subtitle') }}</div>
      </div>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="mdi-play-circle" :loading="running" @click="runChecks">
        {{ t('lms_compliance.run_checks') }}
      </VBtn>
      <VBtn color="primary" prepend-icon="mdi-file-chart" @click="reportDialog = true">
        {{ t('lms_compliance.generate_report') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" md="3">
        <VCard :color="kpi.complianceRate >= 80 ? 'success' : kpi.complianceRate >= 60 ? 'warning' : 'error'" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ kpi.complianceRate }}%</div>
            <div class="text-caption mt-1">{{ t('lms_compliance.kpi.overall_compliance_rate') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="error" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ kpi.totalOverdue }}</div>
            <div class="text-caption mt-1">{{ t('lms_compliance.kpi.overdue_assignments') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="warning" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ kpi.totalActive }}</div>
            <div class="text-caption mt-1">{{ t('lms_compliance.kpi.pending_in_progress') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="success" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ kpi.totalDone }}</div>
            <div class="text-caption mt-1">{{ t('lms_compliance.kpi.completed') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="8">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('lms_compliance.compliance_by_rule') }}</VCardTitle>
          <VCardText class="pt-0">
            <div v-if="byRule.length === 0" class="text-medium-emphasis text-body-2">{{ t('lms_compliance.no_active_rules') }}</div>
            <div v-for="r in byRule" :key="r.id" class="mb-3">
              <div class="d-flex justify-space-between align-center mb-1">
                <div>
                  <span class="text-body-2 font-weight-medium">{{ r.name }}</span>
                  <span class="text-caption text-medium-emphasis ml-2">{{ r.course_title }}</span>
                </div>
                <div class="d-flex align-center gap-2">
                  <VChip v-if="r.overdue > 0" size="x-small" color="error">{{ t('lms_compliance.overdue_label', { count: r.overdue }) }}</VChip>
                  <span class="text-body-2 font-weight-bold" :style="{ color: rateColor(r.rate) }">{{ r.rate }}%</span>
                </div>
              </div>
              <VProgressLinear :model-value="r.rate" :color="r.rate >= 80 ? 'success' : r.rate >= 60 ? 'warning' : 'error'" rounded height="6" bg-color="surface-variant" />
              <div class="text-caption text-medium-emphasis mt-1">{{ t('lms_compliance.completed_label', { completed: r.completed, total: r.total }) }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard>
          <VCardTitle class="d-flex align-center pa-4 pb-2 flex-wrap gap-3">
            <span class="text-subtitle-1">{{ t('lms_compliance.overdue_assignments_title') }}</span>
            <VChip v-if="overdue.total" color="error" size="small">{{ overdue.total }}</VChip>
            <VSpacer />
            <VSelect
              v-model="filterPosition"
              :items="[{ id: null, name: t('lms_compliance.all_positions') }, ...positions]"
              item-title="name" item-value="id"
              density="compact" hide-details style="max-width:200px"
              @update:model-value="applyFilters"
            />
          </VCardTitle>

          <VDataTable :headers="overdueHeaders" :items="overdue.data" hide-default-footer density="compact">
            <template #item.employee="{ item }">
              <span class="font-weight-medium">{{ personName(item.user) }}</span>
            </template>
            <template #item.due_date="{ item }">
              <span class="text-caption text-error">{{ item.due_date }}</span>
            </template>
            <template #item.days_overdue="{ item }">
              <VChip color="error" size="x-small">{{ calcDaysOverdue(item.due_date) }}d</VChip>
            </template>
            <template #item.actions="{ item }">
              <VBtn size="x-small" variant="tonal" color="warning" @click="openWaive(item)">{{ t('lms_compliance.table.waive') }}</VBtn>
            </template>
          </VDataTable>

          <div v-if="overdue.last_page > 1" class="d-flex justify-center pa-4">
            <VPagination :model-value="overdue.current_page" :length="overdue.last_page" density="compact" @update:model-value="goPage" />
          </div>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
            <VIcon color="error">mdi-alert-circle</VIcon>
            <span class="text-subtitle-1">{{ t('lms_compliance.open_escalations') }}</span>
            <VChip v-if="escalations.length" color="error" size="x-small">{{ escalations.length }}</VChip>
          </VCardTitle>
          <div v-if="escalations.length === 0" class="text-center text-medium-emphasis py-4 text-body-2">
            {{ t('lms_compliance.no_open_escalations') }}
          </div>
          <VList v-else density="compact">
            <VListItem v-for="e in escalations" :key="e.id" rounded="lg" class="mx-2 mb-1">
              <template #prepend>
                <VAvatar color="error" variant="tonal" size="32">
                  <span class="text-caption font-weight-bold">{{ e.days_overdue }}d</span>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ e.employee_name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ e.course_title }}</VListItemSubtitle>
              <template #append>
                <VBtn size="x-small" variant="text" color="primary" @click="acknowledgeEsc(e.id)">
                  {{ t('lms_compliance.ack') }}
                </VBtn>
              </template>
            </VListItem>
          </VList>
        </VCard>

        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('lms_compliance.recent_reports') }}</VCardTitle>
          <div v-if="reports.length === 0" class="text-center text-medium-emphasis py-4 text-body-2">
            {{ t('lms_compliance.no_reports') }}
          </div>
          <VList v-else density="compact">
            <VListItem v-for="r in reports" :key="r.id" rounded="lg" class="mx-2 mb-1">
              <VListItemTitle class="text-body-2">
                {{ r.scope_name ?? t('lms_compliance.overall') }}
                <VChip size="x-small" color="grey" variant="tonal" class="ml-1">{{ r.report_type }}</VChip>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ r.compliance_rate }}% · {{ r.overdue_count }} {{ t('lms_compliance.kpi.overdue_assignments') }} · {{ r.generated_at?.substring(0, 10) }}
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Waive dialog -->
    <VDialog v-model="waiveDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_compliance.dialog.waive_title') }}</VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">{{ t('lms_compliance.dialog.waive_body') }}</div>
          <VTextarea v-model="waiveReason" :label="t('lms_compliance.dialog.reason_label')" density="compact" rows="3" />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="waiveDialog = false">{{ t('lms_compliance.dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="waiving" :disabled="!waiveReason" @click="submitWaive">{{ t('lms_compliance.dialog.waive') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Generate report dialog -->
    <VDialog v-model="reportDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_compliance.dialog.report_title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="reportForm.report_type"
            :items="reportTypeItems" item-title="label" item-value="value"
            :label="t('lms_compliance.dialog.report_type_label')" density="compact" class="mb-3"
          />
          <VSelect
            v-if="reportForm.report_type === 'position'"
            v-model="reportForm.scope_id"
            :items="positions" item-title="name" item-value="id"
            :label="t('lms_compliance.dialog.position_label')" density="compact" class="mb-3"
          />
          <VRow>
            <VCol cols="6">
              <VTextField v-model="reportForm.period_start" :label="t('lms_compliance.dialog.period_start_label')" type="date" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="reportForm.period_end" :label="t('lms_compliance.dialog.period_end_label')" type="date" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="reportDialog = false">{{ t('lms_compliance.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="generating" @click="generateReport">{{ t('lms_compliance.dialog.generate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="3000" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>
