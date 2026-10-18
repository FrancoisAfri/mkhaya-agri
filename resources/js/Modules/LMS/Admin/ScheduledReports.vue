<template>
  <VContainer fluid class="pa-4">
    <!-- ── Header ───────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_scheduled_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('lms_scheduled_reports.page_description') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreate">
        {{ t('lms_scheduled_reports.new_report') }}
      </VBtn>
    </div>

    <!-- ── Table ────────────────────────────────────────────────────────── -->
    <VCard border rounded="lg">
      <VDataTable
        :headers="headers"
        :items="scheduled"
        :items-per-page="25"
        class="text-body-2"
      >
        <template #item.report_key="{ item }">
          {{ availableReports[item.report_key] ?? item.report_key }}
        </template>

        <template #item.cadence="{ item }">
          <VChip size="x-small" :color="cadenceColor(item.cadence)" label>
            {{ t(`lms_scheduled_reports.cadence.${item.cadence}`) }}
          </VChip>
        </template>

        <template #item.format="{ item }">
          <VChip size="x-small" color="info" label>
            {{ t(`lms_scheduled_reports.formats.${item.format}`) }}
          </VChip>
        </template>

        <template #item.recipients="{ item }">
          <span class="text-truncate" style="max-width:200px; display:inline-block">
            {{ item.recipients }}
          </span>
        </template>

        <template #item.next_run_at="{ item }">
          {{ item.next_run_at ? fmtDate(item.next_run_at) : '—' }}
        </template>

        <template #item.active="{ item }">
          <VChip :color="item.active ? 'success' : 'default'" size="x-small" label>
            {{ item.active ? t('lms_scheduled_reports.statuses.active') : t('lms_scheduled_reports.statuses.paused') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon size="small" variant="text" color="primary" @click="openEdit(item)">
              <VIcon size="16">mdi-pencil</VIcon>
            </VBtn>
            <VBtn icon size="small" variant="text" :color="item.active ? 'warning' : 'success'" @click="toggleReport(item)">
              <VIcon size="16">{{ item.active ? 'mdi-pause' : 'mdi-play' }}</VIcon>
            </VBtn>
            <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)">
              <VIcon size="16">mdi-delete</VIcon>
            </VBtn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center text-medium-emphasis pa-8">
            {{ t('lms_scheduled_reports.no_data') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Create / Edit Dialog ─────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="640" persistent>
      <VCard rounded="lg">
        <VCardTitle class="pa-4 d-flex align-center justify-space-between">
          <span>{{ editTarget ? t('lms_scheduled_reports.dialog.title_edit') : t('lms_scheduled_reports.dialog.title_new') }}</span>
          <VBtn icon size="small" variant="text" @click="closeDialog">
            <VIcon>mdi-close</VIcon>
          </VBtn>
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <!-- Name -->
            <VCol cols="12">
              <VTextField
                v-model="form.name"
                :label="t('lms_scheduled_reports.dialog.name_label')"
                :error-messages="form.errors.name"
                density="compact"
                variant="outlined"
                required
              />
            </VCol>

            <!-- Report Key -->
            <VCol cols="12">
              <VSelect
                v-model="form.report_key"
                :label="t('lms_scheduled_reports.dialog.report_label')"
                :items="reportItems"
                item-title="title"
                item-value="value"
                :error-messages="form.errors.report_key"
                density="compact"
                variant="outlined"
                required
              />
            </VCol>

            <!-- Cadence -->
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.cadence"
                :label="t('lms_scheduled_reports.dialog.cadence_label')"
                :items="cadenceItems"
                :error-messages="form.errors.cadence"
                density="compact"
                variant="outlined"
                required
              />
            </VCol>

            <!-- Run Time -->
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.run_time"
                :label="t('lms_scheduled_reports.dialog.run_time_label')"
                type="time"
                :error-messages="form.errors.run_time"
                density="compact"
                variant="outlined"
                required
              />
            </VCol>

            <!-- Day of Week (weekly only) -->
            <VCol v-if="form.cadence === 'weekly'" cols="12" sm="6">
              <VSelect
                v-model="form.day_of_week"
                :label="t('lms_scheduled_reports.dialog.day_of_week_label')"
                :items="dayOfWeekItems"
                item-title="title"
                item-value="value"
                :error-messages="form.errors.day_of_week"
                density="compact"
                variant="outlined"
              />
            </VCol>

            <!-- Day of Month (monthly only) -->
            <VCol v-if="form.cadence === 'monthly'" cols="12" sm="6">
              <VSelect
                v-model="form.day_of_month"
                :label="t('lms_scheduled_reports.dialog.day_of_month_label')"
                :items="dayOfMonthItems"
                :error-messages="form.errors.day_of_month"
                density="compact"
                variant="outlined"
              />
            </VCol>

            <!-- Format -->
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.format"
                :label="t('lms_scheduled_reports.dialog.format_label')"
                :items="formatItems"
                item-title="title"
                item-value="value"
                :error-messages="form.errors.format"
                density="compact"
                variant="outlined"
                required
              />
            </VCol>

            <!-- Recipients -->
            <VCol cols="12">
              <VTextField
                v-model="form.recipients"
                :label="t('lms_scheduled_reports.dialog.recipients_label')"
                :error-messages="form.errors.recipients"
                density="compact"
                variant="outlined"
                :hint="t('lms_scheduled_reports.dialog.recipients_hint')"
                persistent-hint
                required
              />
            </VCol>

            <!-- Active -->
            <VCol cols="12">
              <VSwitch
                v-model="form.active"
                :label="t('lms_scheduled_reports.dialog.active_label')"
                color="success"
                density="compact"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 justify-end gap-2">
          <VBtn variant="text" @click="closeDialog">{{ t('lms_scheduled_reports.dialog.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="form.processing"
            :disabled="form.processing"
            @click="submitForm"
          >
            {{ editTarget ? t('lms_scheduled_reports.dialog.save_changes') : t('lms_scheduled_reports.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Delete Confirmation Dialog ───────────────────────────────────── -->
    <VDialog v-model="deleteDialog" max-width="440">
      <VCard rounded="lg">
        <VCardTitle class="pa-4">{{ t('lms_scheduled_reports.delete_dialog.title') }}</VCardTitle>
        <VCardText class="pa-4" v-html="t('lms_scheduled_reports.delete_dialog.message', { name: deleteTarget?.name || '' })" />
        <VCardActions class="pa-4 justify-end gap-2">
          <VBtn variant="text" @click="deleteDialog = false">{{ t('lms_scheduled_reports.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="deleting" @click="doDelete">{{ t('lms_scheduled_reports.delete_dialog.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// ── Types ────────────────────────────────────────────────────────────────
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

// ── Props ────────────────────────────────────────────────────────────────
const props = defineProps<{
  scheduled: ScheduledReport[]
  availableReports: Record<string, string>
}>()

// ── State ────────────────────────────────────────────────────────────────
const dialog       = ref(false)
const deleteDialog = ref(false)
const deleting     = ref(false)
const editTarget   = ref<ScheduledReport | null>(null)
const deleteTarget = ref<ScheduledReport | null>(null)

// ── Form ─────────────────────────────────────────────────────────────────
const form = useForm({
  name:         '',
  report_key:   '',
  cadence:      'weekly',
  run_time:     '07:00',
  day_of_week:  1 as number | null,
  day_of_month: 1 as number | null,
  format:       'pdf',
  recipients:   '',
  active:       true,
})

// ── Select items ─────────────────────────────────────────────────────────
const reportItems = computed(() =>
  Object.entries(props.availableReports).map(([value, title]) => ({ value, title }))
)

const cadenceItems = computed(()=> [
  { title: t('lms_scheduled_reports.cadence.daily'),   value: 'daily'   },
  { title: t('lms_scheduled_reports.cadence.weekly'),  value: 'weekly'  },
  { title: t('lms_scheduled_reports.cadence.monthly'), value: 'monthly' },
])

const formatItems = computed(()=>  [
  { title: t('lms_scheduled_reports.formats.pdf'),   value: 'pdf'   },
  { title: t('lms_scheduled_reports.formats.excel'), value: 'excel' },
  { title: t('lms_scheduled_reports.formats.both'),  value: 'both'  },
])

const dayOfWeekItems = computed(()=>  [
  { title: t('lms_scheduled_reports.days.monday'),    value: 1 },
  { title: t('lms_scheduled_reports.days.tuesday'),   value: 2 },
  { title: t('lms_scheduled_reports.days.wednesday'), value: 3 },
  { title: t('lms_scheduled_reports.days.thursday'),  value: 4 },
  { title: t('lms_scheduled_reports.days.friday'),    value: 5 },
  { title: t('lms_scheduled_reports.days.saturday'),  value: 6 },
  { title: t('lms_scheduled_reports.days.sunday'),    value: 7 },
]
)
const dayOfMonthItems = Array.from({ length: 28 }, (_, i) => i + 1)

// ── Table headers ─────────────────────────────────────────────────────────
const headers = computed(()=>  [
  { title: t('lms_scheduled_reports.table.name'),      key: 'name' },
  { title: t('lms_scheduled_reports.table.report'),    key: 'report_key' },
  { title: t('lms_scheduled_reports.table.cadence'),   key: 'cadence' },
  { title: t('lms_scheduled_reports.table.format'),    key: 'format' },
  { title: t('lms_scheduled_reports.table.recipients'),key: 'recipients' },
  { title: t('lms_scheduled_reports.table.next_run'),  key: 'next_run_at' },
  { title: t('lms_scheduled_reports.table.status'),    key: 'active' },
  { title: t('lms_scheduled_reports.table.actions'),   key: 'actions', sortable: false, align: 'end' as const },
])

// ── Dialog helpers ────────────────────────────────────────────────────────
function openCreate() {
  editTarget.value = null
  form.reset()
  form.cadence = 'weekly'
  form.run_time = '07:00'
  form.day_of_week = 1
  form.day_of_month = 1
  form.active = true
  dialog.value = true
}

function openEdit(item: ScheduledReport) {
  editTarget.value = item
  form.name         = item.name
  form.report_key   = item.report_key
  form.cadence      = item.cadence
  form.run_time     = item.run_time
  form.day_of_week  = item.day_of_week
  form.day_of_month = item.day_of_month
  form.format       = item.format
  form.recipients   = item.recipients
  form.active       = item.active
  form.clearErrors()
  dialog.value = true
}

function closeDialog() {
  dialog.value = false
  editTarget.value = null
  form.reset()
}

function submitForm() {
  if (editTarget.value) {
    form.patch(route('lms.admin.scheduled-reports.update', { scheduledReport: editTarget.value.id }), {
      onSuccess: () => closeDialog(),
    })
  } else {
    form.post(route('lms.admin.scheduled-reports.store'), {
      onSuccess: () => closeDialog(),
    })
  }
}

// ── Delete ────────────────────────────────────────────────────────────────
function confirmDelete(item: ScheduledReport) {
  deleteTarget.value = item
  deleteDialog.value = true
}

function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  router.delete(route('lms.admin.scheduled-reports.destroy', { scheduledReport: deleteTarget.value.id }), {
    onFinish: () => {
      deleting.value = false
      deleteDialog.value = false
      deleteTarget.value = null
    },
  })
}

// ── Toggle ────────────────────────────────────────────────────────────────
function toggleReport(item: ScheduledReport) {
  router.post(route('lms.admin.scheduled-reports.toggle', { scheduledReport: item.id }))
}

// ── Helpers ───────────────────────────────────────────────────────────────
function cadenceColor(cadence: string): string {
  return { daily: 'info', weekly: 'primary', monthly: 'deep-purple' }[cadence] ?? 'default'
}

function fmtDate(dt: string): string {
  return new Date(dt).toLocaleString('en-ZA', {
    year: 'numeric', month: 'short', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}
</script>
