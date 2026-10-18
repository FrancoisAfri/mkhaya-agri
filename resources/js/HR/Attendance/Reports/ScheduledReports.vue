<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ScheduledReport {
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
  next_run: string
  last_run_at: string | null
}

const props = defineProps<{
  reports:    ScheduledReport[]
  reportKeys: Record<string, string>
}>()

const drawer       = ref(false)
const deleteDialog = ref(false)
const editTarget   = ref<ScheduledReport | null>(null)
const deleteTarget = ref<ScheduledReport | null>(null)
const snack        = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const isEdit      = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? t('attendance_report.dialog_edit_title') : t('attendance_report.dialog_new_title'))

const emptyForm = () => ({
  name: '',
  report_key: '',
  cadence: 'weekly' as 'daily' | 'weekly' | 'monthly',
  run_time: '07:00',
  day_of_week: null as number | null,
  day_of_month: null as number | null,
  format: 'pdf' as 'pdf' | 'excel' | 'both',
  recipients: '',
  active: true,
})

const form = useForm(emptyForm())

function openCreate() {
  editTarget.value = null
  form.reset()
  Object.assign(form, emptyForm())
  drawer.value = true
}

function openEdit(r: ScheduledReport) {
  editTarget.value = r
  form.name         = r.name
  form.report_key   = r.report_key
  form.cadence      = r.cadence.toLowerCase() as any
  form.run_time     = r.run_time
  form.day_of_week  = r.day_of_week
  form.day_of_month = r.day_of_month
  form.format       = r.format as any
  form.recipients   = r.recipients
  form.active       = r.active
  drawer.value = true
}

function submitForm() {
  if (isEdit.value && editTarget.value) {
    form.patch(`/attendance/reports/scheduled/${editTarget.value.id}`, {
      onSuccess: () => { drawer.value = false; toast(t('attendance_report.toast_scheduled_updated')) },
    })
  } else {
    form.post('/attendance/reports/scheduled', {
      onSuccess: () => { drawer.value = false; toast(t('attendance_report.toast_scheduled_created')) },
    })
  }
}

function confirmDelete(r: ScheduledReport) {
  deleteTarget.value = r
  deleteDialog.value = true
}

function doDelete() {
  if (!deleteTarget.value) return
  router.delete(`/attendance/reports/scheduled/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false; toast(t('attendance_report.toast_scheduled_deleted'), 'error') },
  })
}

function toast(text: string, color = 'success') {
  snack.value = { show: true, text, color }
}

const cadenceOptions = computed(() => [
  { title: t('attendance_report.cadence_daily'), value: 'daily' },
  { title: t('attendance_report.cadence_weekly'), value: 'weekly' },
  { title: t('attendance_report.cadence_monthly'), value: 'monthly' },
])

const formatOptions = computed(() => [
  { title: t('attendance_report.format_pdf'), value: 'pdf' },
  { title: t('attendance_report.format_excel'), value: 'excel' },
  { title: t('attendance_report.format_both'), value: 'both' },
])

const dayOfWeekOptions = computed(() => [
  { title: t('attendance_report.day_monday'), value: 1 },
  { title: t('attendance_report.day_tuesday'), value: 2 },
  { title: t('attendance_report.day_wednesday'), value: 3 },
  { title: t('attendance_report.day_thursday'), value: 4 },
  { title: t('attendance_report.day_friday'), value: 5 },
  { title: t('attendance_report.day_saturday'), value: 6 },
  { title: t('attendance_report.day_sunday'), value: 7 },
])

const reportKeyOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ title, value }))
)

const tableHeaders = computed(() => [
  { title: t('attendance_report.table_name'),       key: 'name',       sortable: true  },
  { title: t('attendance_report.table_report'),     key: 'report_key', sortable: true  },
  { title: t('attendance_report.table_cadence'),    key: 'cadence',    sortable: true  },
  { title: t('attendance_report.table_recipients'), key: 'recipients', sortable: false },
  { title: t('attendance_report.table_next_run'),   key: 'next_run',   sortable: false },
  { title: t('attendance_report.table_status'),     key: 'active',     sortable: true  },
  { title: t('attendance_report.table_actions'),    key: 'actions',    sortable: false },
])

function cadenceColor(c: string) {
  return ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t('attendance_report.breadcrumb_module') }}</div>
            <h4 class="text-h4">{{ t('attendance_report.scheduled_title') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ t('attendance_report.scheduled_subtitle') }}</p>
          </div>
          <div class="d-flex gap-2">
            <VBtn variant="tonal" prepend-icon="tabler-arrow-left" href="/attendance/reports">
              {{ t('attendance_report.reports_back') }}
            </VBtn>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
              {{ t('attendance_report.new_schedule') }}
            </VBtn>
          </div>
        </div>

        <!-- Stats strip -->
        <VRow class="mb-4">
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="primary">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.length }}</div>
                <div class="text-caption">{{ t('attendance_report.total_schedules') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="success">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.active).length }}</div>
                <div class="text-caption">{{ t('attendance_report.active') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="info">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.cadence === 'daily').length }}</div>
                <div class="text-caption">{{ t('attendance_report.daily') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="warning">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.cadence === 'weekly').length }}</div>
                <div class="text-caption">{{ t('attendance_report.weekly') }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- Table -->
        <VCard>
          <VDataTable :headers="tableHeaders" :items="reports" :items-per-page="15" hover>
            <template #item.report_key="{ item }">
              {{ reportKeys[item.report_key] ?? item.report_key }}
            </template>

            <template #item.cadence="{ item }">
              <VChip :color="cadenceColor(item.cadence)" size="small" label>
                {{ item.cadence }}
              </VChip>
            </template>

            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'default'" size="small" label>
                {{ item.active ? t('attendance_report.active') : t('attendance_report.paused') }}
              </VChip>
            </template>

            <template #item.actions="{ item }">
              <VBtn icon size="small" variant="text" @click="openEdit(item)">
                <VIcon icon="tabler-pencil" />
              </VBtn>
              <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)">
                <VIcon icon="tabler-trash-can-outline" />
              </VBtn>
            </template>

            <template #no-data>
              <div class="pa-8 text-center text-medium-emphasis">
                <VIcon icon="tabler-calendar-clock" size="48" class="mb-3 d-block mx-auto" />
                {{ t('attendance_report.no_scheduled') }}
              </div>
            </template>
          </VDataTable>
        </VCard>

      </VCol>
    </VRow>
  </VContainer>

  <!-- Create / Edit Drawer -->
  <VNavigationDrawer v-model="drawer" temporary location="right" width="480">
    <VForm @submit.prevent="submitForm" class="h-100 d-flex flex-column">

      <div class="pa-5 border-b">
        <h5 class="text-h5">{{ drawerTitle }}</h5>
      </div>

      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <!-- Name -->
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('attendance_report.field_schedule_name')"
              :placeholder="t('attendance_report.field_schedule_name_placeholder')"
              :error-messages="form.errors.name"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Report key -->
          <VCol cols="12">
            <VSelect
              v-model="form.report_key"
              :items="reportKeyOptions"
              :label="t('attendance_report.field_report_type')"
              :error-messages="form.errors.report_key"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Cadence -->
          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.cadence"
              :items="cadenceOptions"
              :label="t('attendance_report.field_cadence')"
              :error-messages="form.errors.cadence"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Run time -->
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.run_time"
              :label="t('attendance_report.field_run_time')"
              type="time"
              :error-messages="form.errors.run_time"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Day of week (weekly only) -->
          <VCol v-if="form.cadence === 'weekly'" cols="12">
            <VSelect
              v-model="form.day_of_week"
              :items="dayOfWeekOptions"
              :label="t('attendance_report.field_day_of_week')"
              :error-messages="form.errors.day_of_week"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Day of month (monthly only) -->
          <VCol v-if="form.cadence === 'monthly'" cols="12">
            <VTextField
              v-model="form.day_of_month"
              :label="t('attendance_report.field_day_of_month')"
              type="number"
              :min="1"
              :max="28"
              :error-messages="form.errors.day_of_month"
              variant="outlined"
              density="comfortable"
              :hint="t('attendance_report.field_day_of_month_hint')"
              persistent-hint
            />
          </VCol>

          <!-- Format -->
          <VCol cols="12">
            <VSelect
              v-model="form.format"
              :items="formatOptions"
              :label="t('attendance_report.field_output_format')"
              :error-messages="form.errors.format"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <!-- Recipients -->
          <VCol cols="12">
            <VTextField
              v-model="form.recipients"
              :label="t('attendance_report.field_recipients')"
              :placeholder="t('attendance_report.field_recipients_placeholder')"
              :error-messages="form.errors.recipients"
              variant="outlined"
              density="comfortable"
              :hint="t('attendance_report.field_recipients_hint')"
              persistent-hint
            />
          </VCol>

          <!-- Active -->
          <VCol cols="12">
            <VSwitch
              v-model="form.active"
              :label="t('attendance_report.field_active')"
              color="primary"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
      </div>

      <!-- Footer -->
      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('attendance_report.cancel') }}</VBtn>
        <VBtn
          type="submit"
          color="primary"
          :loading="form.processing"
          prepend-icon="ri-save-line"
        >
          {{ isEdit ? t('attendance_report.save_changes') : t('attendance_report.create_schedule') }}
        </VBtn>
      </div>

    </VForm>
  </VNavigationDrawer>

  <!-- Delete Confirmation -->
  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">{{ t('attendance_report.delete_title') }}</VCardTitle>
      <VCardText class="px-5">
        {{ t('attendance_report.delete_confirm', { name: deleteTarget?.name }) }}
      </VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">{{ t('attendance_report.cancel') }}</VBtn>
        <VBtn color="error" @click="doDelete">{{ t('attendance_report.delete') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">
    {{ snack.text }}
  </VSnackbar>
</template>
