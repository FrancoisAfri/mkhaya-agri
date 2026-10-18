<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type ScheduledReport = {
  id: number
  name: string
  report_key: string
  cadence: string
  run_time: string
  day_of_week: number | null
  day_of_month: number | null
  format: string
  recipients: string
  active: boolean
  last_run_at: string | null
  next_run_at: string | null
}

const props = defineProps<{
  reports: ScheduledReport[]
  reportKeys: Record<string, string>
}>()

const reportOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ value, title })),
)
const cadenceOptions = [
  { value: 'daily', title: t('contracts_scheduled_reports.cadences.daily') },
  { value: 'weekly', title: t('contracts_scheduled_reports.cadences.weekly') },
  { value: 'monthly', title: t('contracts_scheduled_reports.cadences.monthly') },
]
const formatOptions = [
  { value: 'pdf', title: t('contracts_scheduled_reports.formats.pdf') },
  { value: 'excel', title: t('contracts_scheduled_reports.formats.excel') },
  { value: 'both', title: t('contracts_scheduled_reports.formats.both') },
]
const weekDays = [
  { value: 1, title: t('contracts_scheduled_reports.week_days.monday') },
  { value: 2, title: t('contracts_scheduled_reports.week_days.tuesday') },
  { value: 3, title: t('contracts_scheduled_reports.week_days.wednesday') },
  { value: 4, title: t('contracts_scheduled_reports.week_days.thursday') },
  { value: 5, title: t('contracts_scheduled_reports.week_days.friday') },
  { value: 6, title: t('contracts_scheduled_reports.week_days.saturday') },
  { value: 7, title: t('contracts_scheduled_reports.week_days.sunday') },
]

const dialog = ref(false)
const submitting = ref(false)
const editingItem = ref<ScheduledReport | null>(null)
const confirmDialog = ref()

const blank = () => ({
  name: '',
  report_key: Object.keys(props.reportKeys)[0] ?? 'all',
  cadence: 'monthly',
  run_time: '07:00',
  day_of_week: 1 as number | null,
  day_of_month: 1 as number | null,
  format: 'pdf',
  recipients: '',
  active: true,
})

const form = reactive(blank())

function openCreate() {
  editingItem.value = null
  Object.assign(form, blank())
  dialog.value = true
}

function openEdit(report: ScheduledReport) {
  editingItem.value = report
  Object.assign(form, {
    name: report.name,
    report_key: report.report_key,
    cadence: report.cadence,
    run_time: report.run_time,
    day_of_week: report.day_of_week ?? 1,
    day_of_month: report.day_of_month ?? 1,
    format: report.format,
    recipients: report.recipients,
    active: report.active,
  })
  dialog.value = true
}

function payload() {
  return {
    name: form.name,
    report_key: form.report_key,
    cadence: form.cadence,
    run_time: form.run_time,
    day_of_week: form.cadence === 'weekly' ? form.day_of_week : null,
    day_of_month: form.cadence === 'monthly' ? form.day_of_month : null,
    format: form.format,
    recipients: form.recipients,
    active: form.active,
  }
}

function submitForm() {
  submitting.value = true
  const done = { onFinish: () => { submitting.value = false }, onSuccess: () => { dialog.value = false } }
  if (editingItem.value) {
    router.patch(route('contracts.scheduled-reports.update', { scheduledReport: editingItem.value.id }), payload(), done)
  } else {
    router.post(route('contracts.scheduled-reports.store'), payload(), done)
  }
}

function deleteReport(report: ScheduledReport) {
  confirmDialog.value.open(
    {
      title: t('contracts_scheduled_reports.confirm.title'),
      message: t('contracts_scheduled_reports.confirm.message', { name: report.name }),
      confirmText: t('contracts_scheduled_reports.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.scheduled-reports.destroy', { scheduledReport: report.id })) },
  )
}

function cadenceDetail(report: ScheduledReport) {
  if (report.cadence === 'weekly') return weekDays.find(d => d.value === report.day_of_week)?.title ?? t('contracts_scheduled_reports.cadences.weekly')
  if (report.cadence === 'monthly') return `Day ${report.day_of_month ?? 1}`
  return t('contracts_scheduled_reports.cadences.daily')
}

function fmtDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <ConfirmDialog ref="confirmDialog" />
    <!-- Loading overlay -->
    <VOverlay v-if="submitting" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('contracts.reports'))" />
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_scheduled_reports.page_title') }}</h4>
          <p class="text-medium-emphasis mb-0">{{ t('contracts_scheduled_reports.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('contracts_scheduled_reports.new_schedule') }}</VBtn>
    </div>

    <VCard elevation="0" border>
      <VList v-if="reports.length">
        <VListItem
          v-for="report in reports"
          :key="report.id"
          :subtitle="`${report.cadence} · ${cadenceDetail(report)} · ${report.run_time} · ${report.format.toUpperCase()} · ${t('contracts_scheduled_reports.next', { date: fmtDate(report.next_run_at) })}`"
        >
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ report.name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ reportKeys[report.report_key] ?? report.report_key }}</span>
          </template>
          <template #prepend>
            <VAvatar :color="report.active ? 'primary' : 'secondary'" variant="tonal" size="40" rounded class="me-3">
              <VIcon icon="tabler-calendar-repeat" size="20" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="report.active ? 'success' : 'secondary'" size="x-small" variant="tonal">
                {{ report.active ? t('contracts_scheduled_reports.active') : t('contracts_scheduled_reports.paused') }}
              </VChip>
              <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEdit(report)" />
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteReport(report)" />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-calendar-repeat" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">{{ t('contracts_scheduled_reports.empty.message') }}</p>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('contracts_scheduled_reports.empty.create_first') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Create/Edit Dialog -->
    <VDialog v-model="dialog" max-width="600" persistent>
      <VCard :loading="submitting" :disabled="submitting">
        <VCardTitle class="pa-4 pb-2 text-h6">
          {{ editingItem ? t('contracts_scheduled_reports.dialog.edit_title') : t('contracts_scheduled_reports.dialog.new_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('contracts_scheduled_reports.dialog.name')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.report_key" :items="reportOptions" item-title="title" item-value="value" :label="t('contracts_scheduled_reports.dialog.report')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.cadence" :items="cadenceOptions" item-title="title" item-value="value" :label="t('contracts_scheduled_reports.dialog.cadence')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.run_time" type="time" :label="t('contracts_scheduled_reports.dialog.run_time')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol v-if="form.cadence === 'weekly'" cols="12" sm="6">
              <VSelect v-model="form.day_of_week" :items="weekDays" item-title="title" item-value="value" :label="t('contracts_scheduled_reports.dialog.day_of_week')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol v-if="form.cadence === 'monthly'" cols="12" sm="6">
              <VTextField v-model.number="form.day_of_month" type="number" min="1" max="31" :label="t('contracts_scheduled_reports.dialog.day_of_month')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.format" :items="formatOptions" item-title="title" item-value="value" :label="t('contracts_scheduled_reports.dialog.format')" variant="outlined" density="compact" :disabled="submitting" />
            </VCol>
            <VCol cols="12" sm="6" class="d-flex align-center">
              <VSwitch v-model="form.active" :label="t('contracts_scheduled_reports.dialog.active')" hide-details density="compact" color="primary" :disabled="submitting" />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.recipients"
                :label="t('contracts_scheduled_reports.dialog.recipients')"
                :placeholder="t('contracts_scheduled_reports.dialog.recipients_placeholder')"
                variant="outlined"
                density="compact"
                :disabled="submitting"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="submitting" @click="dialog = false">
            {{ t('contracts_scheduled_reports.dialog.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="submitting"
            :disabled="!form.name || !form.report_key || !form.recipients || submitting"
            @click="submitForm"
          >
            {{ editingItem ? t('contracts_scheduled_reports.dialog.save') : t('contracts_scheduled_reports.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
