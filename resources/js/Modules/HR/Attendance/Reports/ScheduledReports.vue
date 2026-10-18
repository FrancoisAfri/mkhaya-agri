<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

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
  next_run: string | null
  last_run_at: string | null
}

const props = defineProps<{
  reports: ScheduledReport[]
  reportKeys: Record<string, string>
}>()

const createDialog = ref(false)
const editDialog   = ref(false)
const editTarget   = ref<ScheduledReport | null>(null)
const confirmDialog = ref()

const blankForm = () => ({
  name:          '',
  report_key:    '',
  cadence:       'weekly',
  run_time:      '07:00',
  day_of_week:   null as number | null,
  day_of_month:  null as number | null,
  format:        'excel',
  recipients:    '',
  active:        true,
})

const createForm = useForm(blankForm())

function openEdit(r: ScheduledReport) {
  editTarget.value = r
  editForm.name         = r.name
  editForm.report_key   = r.report_key
  editForm.cadence      = r.cadence
  editForm.run_time     = r.run_time
  editForm.day_of_week  = r.day_of_week
  editForm.day_of_month = r.day_of_month
  editForm.format       = r.format
  editForm.recipients   = r.recipients
  editForm.active       = r.active
  editDialog.value      = true
}

const editForm = useForm(blankForm())

function submitCreate() {
  createForm.post(route('attendance.reports.scheduled.store'), {
    onSuccess: () => { createDialog.value = false; createForm.reset() },
  })
}

function submitEdit() {
  if (!editTarget.value) return
  editForm.patch(route('attendance.reports.scheduled.update', { scheduledReport: editTarget.value.id }), {
    onSuccess: () => { editDialog.value = false },
  })
}

function destroy(id: number) {
  confirmDialog.value.open(
    { title: 'Delete Scheduled Report', message: 'Delete this scheduled report?', confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('attendance.reports.scheduled.destroy', { scheduledReport: id }))
    },
  )
}

const reportKeyItems = Object.entries(props.reportKeys).map(([v, t]) => ({ title: t, value: v }))
const cadenceItems   = [{ title: 'Daily', value: 'daily' }, { title: 'Weekly', value: 'weekly' }, { title: 'Monthly', value: 'monthly' }]
const formatItems    = [{ title: 'Excel', value: 'excel' }, { title: 'PDF', value: 'pdf' }, { title: 'Both', value: 'both' }]
const dayItems       = [1,2,3,4,5,6,7].map(d => ({ title: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][d-1], value: d }))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 860px;">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.reports.index'))" />
        <div>
          <h4 class="text-h4">Scheduled Reports</h4>
          <p class="text-medium-emphasis mb-0">Automate report delivery to recipients</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New Schedule</VBtn>
    </div>

    <VCard elevation="0" border>
      <VList v-if="reports.length">
        <VListItem v-for="r in reports" :key="r.id">
          <template #prepend>
            <VAvatar :color="r.active ? 'success' : 'secondary'" variant="tonal" size="38" rounded class="me-3">
              <VIcon icon="tabler-calendar-clock" size="18" />
            </VAvatar>
          </template>
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ r.name }}</span>
            <VChip size="x-small" variant="tonal" :color="r.active ? 'success' : 'secondary'" class="ms-2">{{ r.active ? 'Active' : 'Paused' }}</VChip>
          </template>
          <template #subtitle>
            <span class="text-caption text-medium-emphasis">
              {{ reportKeys[r.report_key] ?? r.report_key }} ·
              {{ r.cadence }} at {{ r.run_time }} ·
              {{ r.format.toUpperCase() }} ·
              Next: {{ r.next_run ?? '—' }}
            </span>
          </template>
          <template #append>
            <div class="d-flex gap-1">
              <VBtn icon="tabler-pencil" size="x-small" variant="text" color="primary" @click="openEdit(r)" />
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(r.id)" />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-calendar-clock" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">No scheduled reports configured.</p>
        <VBtn color="primary" variant="tonal" @click="createDialog = true">Create First Schedule</VBtn>
      </VCardText>
    </VCard>

    <!-- Create dialog -->
    <VDialog v-model="createDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Scheduled Report</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="createForm.name" label="Name *" variant="outlined" density="comfortable" :error-messages="createForm.errors.name" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="createForm.report_key" :items="reportKeyItems" item-title="title" item-value="value" label="Report *" variant="outlined" density="comfortable" :error-messages="createForm.errors.report_key" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="createForm.format" :items="formatItems" item-title="title" item-value="value" label="Format *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="createForm.cadence" :items="cadenceItems" item-title="title" item-value="value" label="Cadence *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="createForm.run_time" label="Run Time *" type="time" variant="outlined" density="comfortable" />
            </VCol>
            <VCol v-if="createForm.cadence === 'weekly'" cols="12" sm="6">
              <VSelect v-model="createForm.day_of_week" :items="dayItems" item-title="title" item-value="value" label="Day of Week" variant="outlined" density="comfortable" />
            </VCol>
            <VCol v-if="createForm.cadence === 'monthly'" cols="12" sm="6">
              <VTextField v-model="createForm.day_of_month" label="Day of Month (1–28)" type="number" variant="outlined" density="comfortable" :min="1" :max="28" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="createForm.recipients" label="Recipients * (comma-separated emails)" variant="outlined" density="comfortable" :error-messages="createForm.errors.recipients" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="createForm.active" label="Active" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="createForm.processing" @click="submitCreate">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit dialog -->
    <VDialog v-model="editDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Edit Scheduled Report</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="editForm.name" label="Name *" variant="outlined" density="comfortable" :error-messages="editForm.errors.name" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="editForm.report_key" :items="reportKeyItems" item-title="title" item-value="value" label="Report *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="editForm.format" :items="formatItems" item-title="title" item-value="value" label="Format *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="editForm.cadence" :items="cadenceItems" item-title="title" item-value="value" label="Cadence *" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="editForm.run_time" label="Run Time *" type="time" variant="outlined" density="comfortable" />
            </VCol>
            <VCol v-if="editForm.cadence === 'weekly'" cols="12" sm="6">
              <VSelect v-model="editForm.day_of_week" :items="dayItems" item-title="title" item-value="value" label="Day of Week" variant="outlined" density="comfortable" />
            </VCol>
            <VCol v-if="editForm.cadence === 'monthly'" cols="12" sm="6">
              <VTextField v-model="editForm.day_of_month" label="Day of Month (1–28)" type="number" variant="outlined" density="comfortable" :min="1" :max="28" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="editForm.recipients" label="Recipients * (comma-separated emails)" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="editForm.active" label="Active" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="editForm.processing" @click="submitEdit">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
