<script setup lang="ts">
import { ref, computed } from 'vue'
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
  reports: ScheduledReport[]
  reportKeys: Record<string, string>
}>()

const drawer       = ref(false)
const deleteDialog = ref(false)
const editTarget   = ref<ScheduledReport | null>(null)
const deleteTarget = ref<ScheduledReport | null>(null)
const snack        = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const isEdit = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? `${t('leave.edit')} ${t('leave.scheduled_reports_title')}` : `${t('leave.new_schedule')}`)

const emptyForm = () => ({
  name: '',
  report_key: '',
  cadence: 'daily' as 'daily' | 'weekly' | 'monthly',
  run_time: '07:00',
  day_of_week: null as number | null,
  day_of_month: null as number | null,
  format: 'pdf' as 'pdf' | 'excel' | 'both',
  recipients: '',
  active: true,
})

const form = useForm(emptyForm())

const openCreate = () => {
  editTarget.value = null
  form.reset()
  Object.assign(form, emptyForm())
  drawer.value = true
}

const openEdit = (r: ScheduledReport) => {
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

const submitForm = () => {
  if (isEdit.value && editTarget.value) {
    form.patch(`/leave-management/setup/scheduled-reports/${editTarget.value.id}`, {
      onSuccess: () => { drawer.value = false; toast(t('leave_scheduled_report.toast_updated')) },
    })
  } else {
    form.post('/leave-management/setup/scheduled-reports', {
      onSuccess: () => { drawer.value = false; toast(t('leave_scheduled_report.toast_created')) },
    })
  }
}

const confirmDelete = (r: ScheduledReport) => {
  deleteTarget.value = r
  deleteDialog.value = true
}

const doDelete = () => {
  if (!deleteTarget.value) return
  router.delete(`/leave-management/setup/scheduled-reports/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false; toast(t('leave_scheduled_report.toast_deleted'), 'error') },
  })
}

const toast = (text: string, color = 'success') => {
  snack.value = { show: true, text, color }
}

const cadenceOptions = computed(() => [
  { title: t('leave_scheduled_report.cadence_daily'),   value: 'daily'   },
  { title: t('leave_scheduled_report.cadence_weekly'),  value: 'weekly'  },
  { title: t('leave_scheduled_report.cadence_monthly'), value: 'monthly' },
])

const formatOptions = computed(() => [
  { title: t('leave_scheduled_report.format_pdf'),   value: 'pdf'   },
  { title: t('leave_scheduled_report.format_excel'), value: 'excel' },
  { title: t('leave_scheduled_report.format_both'),  value: 'both'  },
])

const dayOfWeekOptions = computed(() => [
  { title: t('leave_scheduled_report.day_monday'),    value: 1 },
  { title: t('leave_scheduled_report.day_tuesday'),   value: 2 },
  { title: t('leave_scheduled_report.day_wednesday'), value: 3 },
  { title: t('leave_scheduled_report.day_thursday'),  value: 4 },
  { title: t('leave_scheduled_report.day_friday'),    value: 5 },
  { title: t('leave_scheduled_report.day_saturday'),  value: 6 },
  { title: t('leave_scheduled_report.day_sunday'),    value: 7 },
])

const reportKeyOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ title, value }))
)

const tableHeaders = computed(() => [
  { title: t('leave.name'),       key: 'name',       sortable: true  },
  { title: t('leave.report'),     key: 'report_key', sortable: true  },
  { title: t('leave.cadence'),    key: 'cadence',    sortable: true  },
  { title: t('leave.recipients'), key: 'recipients', sortable: false },
  { title: t('leave.next_run'),   key: 'next_run',   sortable: false },
  { title: t('leave.status'),     key: 'active',     sortable: true  },
  { title: t('leave.actions'),    key: 'actions',    sortable: false },
])

const cadenceColor = (c: string) => ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t('leave.setup_label') }}</div>
            <h4 class="text-h4">{{ t('leave.scheduled_reports_title') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ t('leave_scheduled_report.subtitle') }}</p>
          </div>
          <div class="d-flex gap-2">
            <VBtn
              variant="tonal"
              prepend-icon="tabler-arrow-left"
              href="/leave-management/setup/configuration"
            >
              {{ t('leave_scheduled_report.configuration') }}
            </VBtn>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
              {{ t('leave.new_schedule') }}
            </VBtn>
          </div>
        </div>

        <!-- Table -->
        <VCard>
          <VDataTable
            :headers="tableHeaders"
            :items="reports"
            :items-per-page="15"
            hover
          >
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
                {{ item.active ? t('leave_scheduled_report.active') : t('leave_scheduled_report.paused') }}
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
                {{ t('leave_scheduled_report.no_data') }}
              </div>
            </template>
          </VDataTable>
        </VCard>

      </VCol>
    </VRow>
  </VContainer>

  <!-- ── Create / Edit Drawer ── -->
  <VNavigationDrawer
    v-model="drawer"
    temporary
    location="right"
    width="480"
  >
    <VForm @submit.prevent="submitForm" class="h-100 d-flex flex-column">

      <div class="pa-5 border-b">
        <h5 class="text-h5">{{ drawerTitle }}</h5>
      </div>

      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('leave_scheduled_report.schedule_name')"
              :placeholder="t('leave_scheduled_report.schedule_name_placeholder')"
              :error-messages="form.errors.name"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VSelect
              v-model="form.report_key"
              :items="reportKeyOptions"
              :label="t('leave_scheduled_report.report_type')"
              :error-messages="form.errors.report_key"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.cadence"
              :items="cadenceOptions"
              :label="t('leave_scheduled_report.cadence_label')"
              :error-messages="form.errors.cadence"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.run_time"
              :label="t('leave_scheduled_report.run_time')"
              placeholder="07:00"
              type="time"
              :error-messages="form.errors.run_time"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol v-if="form.cadence === 'weekly'" cols="12">
            <VSelect
              v-model="form.day_of_week"
              :items="dayOfWeekOptions"
              :label="t('leave_scheduled_report.day_of_week')"
              :error-messages="form.errors.day_of_week"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol v-if="form.cadence === 'monthly'" cols="12">
            <VTextField
              v-model="form.day_of_month"
              :label="t('leave_scheduled_report.day_of_month')"
              type="number"
              :min="1"
              :max="28"
              :error-messages="form.errors.day_of_month"
              variant="outlined"
              density="comfortable"
              :hint="t('leave_scheduled_report.day_of_month_hint')"
              persistent-hint
            />
          </VCol>

          <VCol cols="12">
            <VSelect
              v-model="form.format"
              :items="formatOptions"
              :label="t('leave_scheduled_report.output_format')"
              :error-messages="form.errors.format"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VTextField
              v-model="form.recipients"
              :label="t('leave_scheduled_report.recipients_label')"
              :placeholder="t('leave_scheduled_report.recipients_placeholder')"
              :error-messages="form.errors.recipients"
              variant="outlined"
              density="comfortable"
              :hint="t('leave_scheduled_report.recipients_hint')"
              persistent-hint
            />
          </VCol>

          <VCol cols="12">
            <VSwitch
              v-model="form.active"
              :label="t('leave_scheduled_report.active_switch')"
              color="primary"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
      </div>

      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('leave.cancel') }}</VBtn>
        <VBtn
          type="submit"
          color="primary"
          :loading="form.processing"
          prepend-icon="ri-save-line"
        >
          {{ isEdit ? t('leave.save_changes') : t('leave.new_schedule') }}
        </VBtn>
      </div>

    </VForm>
  </VNavigationDrawer>

  <!-- ── Delete Confirmation ── -->
  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">{{ t('leave.delete') }} {{ t('leave.scheduled_reports_title') }}</VCardTitle>
      <VCardText class="px-5">
        {{ t('leave_scheduled_report.delete_confirm', { name: deleteTarget?.name }) }}
      </VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">{{ t('leave.cancel') }}</VBtn>
        <VBtn color="error" @click="doDelete">{{ t('leave.delete') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Snackbar ── -->
  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">
    {{ snack.text }}
  </VSnackbar>
</template>
