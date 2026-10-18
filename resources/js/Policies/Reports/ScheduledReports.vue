<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'

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
const drawerTitle = computed(() => isEdit.value ? 'Edit Scheduled Report' : 'New Scheduled Report')

const emptyForm = () => ({
  name: '', report_key: '', cadence: 'weekly' as 'daily' | 'weekly' | 'monthly',
  run_time: '07:00', day_of_week: null as number | null, day_of_month: null as number | null,
  format: 'pdf' as 'pdf' | 'excel' | 'both', recipients: '', active: true,
})

const form = useForm(emptyForm())

const openCreate = () => { editTarget.value = null; form.reset(); Object.assign(form, emptyForm()); drawer.value = true }

const openEdit = (r: ScheduledReport) => {
  editTarget.value = r
  form.name = r.name; form.report_key = r.report_key; form.cadence = r.cadence.toLowerCase() as any
  form.run_time = r.run_time; form.day_of_week = r.day_of_week; form.day_of_month = r.day_of_month
  form.format = r.format as any; form.recipients = r.recipients; form.active = r.active
  drawer.value = true
}

const submitForm = () => {
  if (isEdit.value && editTarget.value) {
    form.patch(`/policy-reports/scheduled/${editTarget.value.id}`, {
      onSuccess: () => { drawer.value = false; toast('Scheduled report updated.') },
    })
  } else {
    form.post('/policy-reports/scheduled', {
      onSuccess: () => { drawer.value = false; toast('Scheduled report created.') },
    })
  }
}

const confirmDelete = (r: ScheduledReport) => { deleteTarget.value = r; deleteDialog.value = true }
const doDelete = () => {
  if (!deleteTarget.value) return
  router.delete(`/policy-reports/scheduled/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false; toast('Scheduled report deleted.', 'error') },
  })
}
const toast = (text: string, color = 'success') => { snack.value = { show: true, text, color } }

const cadenceOptions    = [{ title: 'Daily', value: 'daily' }, { title: 'Weekly', value: 'weekly' }, { title: 'Monthly', value: 'monthly' }]
const formatOptions     = [{ title: 'PDF', value: 'pdf' }, { title: 'Excel', value: 'excel' }, { title: 'PDF + Excel', value: 'both' }]
const dayOfWeekOptions  = [
  { title: 'Monday', value: 1 }, { title: 'Tuesday', value: 2 }, { title: 'Wednesday', value: 3 },
  { title: 'Thursday', value: 4 }, { title: 'Friday', value: 5 }, { title: 'Saturday', value: 6 }, { title: 'Sunday', value: 7 },
]
const reportKeyOptions  = computed(() => Object.entries(props.reportKeys).map(([value, title]) => ({ title, value })))

const tableHeaders = [
  { title: 'Name',       key: 'name',       sortable: true  },
  { title: 'Report',     key: 'report_key', sortable: true  },
  { title: 'Cadence',    key: 'cadence',    sortable: true  },
  { title: 'Format',     key: 'format',     sortable: false },
  { title: 'Recipients', key: 'recipients', sortable: false },
  { title: 'Next Run',   key: 'next_run',   sortable: false },
  { title: 'Status',     key: 'active',     sortable: true  },
  { title: 'Actions',    key: 'actions',    sortable: false },
]

const cadenceColor = (c: string) => ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">Policy Reports</div>
            <h4 class="text-h4">Scheduled Reports</h4>
            <p class="text-medium-emphasis mb-0">
              Configure automated policy reports delivered on a set schedule via email.
              Reports are generated and emailed to recipients automatically.
            </p>
          </div>
          <div class="d-flex gap-2">
            <VBtn variant="tonal" prepend-icon="tabler-arrow-left" href="/policy-reports">Reports Engine</VBtn>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Schedule</VBtn>
          </div>
        </div>

        <!-- Stats strip -->
        <VRow class="mb-4">
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="primary">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.length }}</div>
                <div class="text-caption">Total Schedules</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="success">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.active).length }}</div>
                <div class="text-caption">Active</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="info">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.cadence === 'daily').length }}</div>
                <div class="text-caption">Daily</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard variant="tonal" color="warning">
              <VCardText class="text-center pa-4">
                <div class="text-h4 font-weight-bold">{{ reports.filter(r => r.cadence === 'weekly').length }}</div>
                <div class="text-caption">Weekly</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- How it works banner -->
        <VAlert type="info" variant="tonal" class="mb-4" icon="tabler-info-circle">
          Scheduled reports run automatically on their configured cadence. When a report is due, the system generates
          the report, attaches it as <strong>PDF and/or Excel</strong>, and emails it to all listed recipients.
          The scheduler runs every hour and processes any reports that are overdue.
        </VAlert>

        <!-- Table -->
        <VCard>
          <VDataTable :headers="tableHeaders" :items="reports" :items-per-page="15" hover>
            <template #item.report_key="{ item }">{{ reportKeys[item.report_key] ?? item.report_key }}</template>
            <template #item.cadence="{ item }">
              <VChip :color="cadenceColor(item.cadence)" size="small" label>{{ item.cadence }}</VChip>
            </template>
            <template #item.format="{ item }">
              <VChip color="secondary" size="small" label variant="tonal">{{ item.format.toUpperCase() }}</VChip>
            </template>
            <template #item.recipients="{ item }">
              <span class="text-caption">{{ item.recipients.length > 40 ? item.recipients.substring(0, 40) + '…' : item.recipients }}</span>
            </template>
            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'default'" size="small" label>{{ item.active ? 'Active' : 'Paused' }}</VChip>
            </template>
            <template #item.actions="{ item }">
              <VBtn icon size="small" variant="text" @click="openEdit(item)"><VIcon icon="tabler-pencil" /></VBtn>
              <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)"><VIcon icon="tabler-trash" /></VBtn>
            </template>
            <template #no-data>
              <div class="pa-8 text-center text-medium-emphasis">
                <VIcon icon="tabler-calendar-clock" size="48" class="mb-3 d-block mx-auto" />
                <div class="text-subtitle-1 mb-1">No scheduled reports yet</div>
                <div class="text-body-2">Create a schedule to start receiving automated policy reports by email.</div>
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
        <p class="text-caption text-medium-emphasis mb-0 mt-1">
          Reports are emailed automatically when their scheduled time arrives.
        </p>
      </div>
      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              label="Schedule Name"
              placeholder="e.g. Weekly Compliance Report for HR"
              :error-messages="form.errors.name"
              variant="outlined"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="form.report_key"
              :items="reportKeyOptions"
              label="Report Type"
              :error-messages="form.errors.report_key"
              variant="outlined"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.cadence"
              :items="cadenceOptions"
              label="Cadence"
              :error-messages="form.errors.cadence"
              variant="outlined"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.run_time"
              label="Run Time (HH:MM)"
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
              label="Day of Week"
              :error-messages="form.errors.day_of_week"
              variant="outlined"
              density="comfortable"
            />
          </VCol>
          <VCol v-if="form.cadence === 'monthly'" cols="12">
            <VTextField
              v-model="form.day_of_month"
              label="Day of Month (1–28)"
              type="number"
              :min="1"
              :max="28"
              :error-messages="form.errors.day_of_month"
              variant="outlined"
              density="comfortable"
              hint="Use 1–28 to avoid month-end issues"
              persistent-hint
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="form.format"
              :items="formatOptions"
              label="Output Format"
              :error-messages="form.errors.format"
              variant="outlined"
              density="comfortable"
              hint="The report will be attached to the email in the selected format(s)"
              persistent-hint
            />
          </VCol>
          <VCol cols="12">
            <VTextField
              v-model="form.recipients"
              label="Recipients"
              placeholder="compliance@company.com, director@company.com"
              :error-messages="form.errors.recipients"
              variant="outlined"
              density="comfortable"
              hint="Comma-separated email addresses"
              persistent-hint
            />
          </VCol>
          <VCol cols="12">
            <VSwitch v-model="form.active" label="Active" color="primary" density="compact" hide-details />
          </VCol>
        </VRow>
      </div>
      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">Cancel</VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="ri-save-line">
          {{ isEdit ? 'Save Changes' : 'Create Schedule' }}
        </VBtn>
      </div>
    </VForm>
  </VNavigationDrawer>

  <!-- Delete Confirmation -->
  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">Delete Scheduled Report</VCardTitle>
      <VCardText class="px-5">
        Are you sure you want to delete <strong>{{ deleteTarget?.name }}</strong>?
        This will stop all future automated emails for this schedule.
      </VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">Cancel</VBtn>
        <VBtn color="error" @click="doDelete">Delete</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
</template>
