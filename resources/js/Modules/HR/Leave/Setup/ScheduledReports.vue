<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ScheduledReport = {
  id: number
  name: string
  report_key: string
  frequency: string
  recipients: string[]
  format: string
  active: boolean
  next_run_at: string | null
}

const props = defineProps<{ scheduledReports: ScheduledReport[] }>()

const dialog      = ref(false)
const submitting  = ref(false)
const editingItem = ref<ScheduledReport | null>(null)
const confirmDialog = ref()

const form = reactive({
  name:       '',
  report_key: 'leave_summary',
  frequency:  'monthly',
  recipients: [] as string[],
  format:     'pdf',
  active:     true,
})

const recipientInput = ref('')

const reportOptions = [
  { title: 'Leave Summary', value: 'leave_summary' },
  { title: 'Balance Report', value: 'balance_report' },
  { title: 'BCEA Compliance', value: 'bcea_compliance' },
  { title: 'Negative Balances', value: 'negative_balances' },
  { title: 'Absence Trends', value: 'absence_trends' },
]

const frequencyOptions = [
  { title: 'Daily', value: 'daily' },
  { title: 'Weekly', value: 'weekly' },
  { title: 'Monthly', value: 'monthly' },
  { title: 'Quarterly', value: 'quarterly' },
]

function openCreate() {
  editingItem.value = null
  Object.assign(form, { name: '', report_key: 'leave_summary', frequency: 'monthly', recipients: [], format: 'pdf', active: true })
  dialog.value = true
}

function openEdit(r: ScheduledReport) {
  editingItem.value = r
  Object.assign(form, { name: r.name, report_key: r.report_key, frequency: r.frequency, recipients: [...r.recipients], format: r.format, active: r.active })
  dialog.value = true
}

function addRecipient() {
  const email = recipientInput.value.trim()
  if (email && !form.recipients.includes(email)) {
    form.recipients.push(email)
  }
  recipientInput.value = ''
}

function removeRecipient(email: string) {
  form.recipients = form.recipients.filter(r => r !== email)
}

function submitForm() {
  submitting.value = true
  if (editingItem.value) {
    router.patch(route('hr.leave.setup.scheduled-reports.update', { scheduledReport: editingItem.value.id }), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  } else {
    router.post(route('hr.leave.setup.scheduled-reports.store'), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  }
}

function deleteReport(r: ScheduledReport) {
  confirmDialog.value.open(
    { title: 'Delete Scheduled Report', message: `Delete scheduled report "${r.name}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('hr.leave.setup.scheduled-reports.destroy', { scheduledReport: r.id }))
    },
  )
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.reports'))" />
        <div>
          <h4 class="text-h4">Scheduled Reports</h4>
          <p class="text-medium-emphasis mb-0">Automate leave report delivery to your team</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Schedule</VBtn>
    </div>

    <!-- Reports List -->
    <VCard elevation="0" border>
      <VList v-if="scheduledReports.length">
        <VListItem
          v-for="r in scheduledReports"
          :key="r.id"
          :subtitle="`${r.frequency} · ${r.format.toUpperCase()} · Next: ${formatDate(r.next_run_at)}`"
        >
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ r.name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ r.report_key.replace('_', ' ') }}</span>
          </template>
          <template #prepend>
            <VAvatar :color="r.active ? 'primary' : 'secondary'" variant="tonal" size="40" rounded class="me-3">
              <VIcon icon="tabler-calendar-repeat" size="20" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="r.active ? 'success' : 'secondary'" size="x-small" variant="tonal">{{ r.active ? 'Active' : 'Paused' }}</VChip>
              <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEdit(r)" />
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteReport(r)" />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-calendar-repeat" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">No scheduled reports yet.</p>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate">Create First Schedule</VBtn>
      </VCardText>
    </VCard>

    <!-- Create/Edit Dialog -->
    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingItem ? 'Edit Schedule' : 'New Scheduled Report' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" label="Name *" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.report_key"
                :items="reportOptions"
                item-title="title"
                item-value="value"
                label="Report *"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.frequency"
                :items="frequencyOptions"
                item-title="title"
                item-value="value"
                label="Frequency"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.format"
                :items="[{ title: 'PDF', value: 'pdf' }, { title: 'Excel', value: 'xlsx' }, { title: 'CSV', value: 'csv' }]"
                item-title="title"
                item-value="value"
                label="Format"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSwitch v-model="form.active" label="Active" hide-details density="compact" />
            </VCol>

            <!-- Recipients -->
            <VCol cols="12">
              <p class="text-body-2 font-weight-medium mb-2">Recipients</p>
              <div class="d-flex gap-2 mb-2">
                <VTextField
                  v-model="recipientInput"
                  label="Add email…"
                  variant="outlined"
                  density="compact"
                  hide-details
                  class="flex-1-1"
                  @keydown.enter.prevent="addRecipient"
                />
                <VBtn variant="tonal" color="primary" @click="addRecipient">Add</VBtn>
              </div>
              <div v-if="form.recipients.length" class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="email in form.recipients"
                  :key="email"
                  closable
                  size="small"
                  color="primary"
                  variant="tonal"
                  @click:close="removeRecipient(email)"
                >
                  {{ email }}
                </VChip>
              </div>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="submitting"
            :disabled="!form.name || !form.report_key"
            @click="submitForm"
          >
            {{ editingItem ? 'Save' : 'Create' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />

  </VContainer>
</template>
