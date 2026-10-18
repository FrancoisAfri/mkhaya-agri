<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ScheduledReport {
  id: number; name: string; report_key: string; cadence: string
  run_time: string; day_of_week: number|null; day_of_month: number|null
  format: string; recipients: string; active: boolean
  next_run: string; last_run_at: string|null
}

const props = defineProps<{
  reports:    ScheduledReport[]
  reportKeys: Record<string, string>
}>()

const drawer   = ref(false)
const editId   = ref<number|null>(null)
const deleteDialog = ref(false)
const deleteTarget = ref<ScheduledReport|null>(null)

const form = useForm({
  name:         '',
  report_key:   '',
  cadence:      'weekly',
  run_time:     '07:00',
  day_of_week:  1,
  day_of_month: 1,
  format:       'excel',
  recipients:   '',
  active:       true,
})

function openCreate() {
  editId.value = null
  form.reset()
  form.cadence = 'weekly'
  form.run_time = '07:00'
  form.day_of_week = 1
  form.day_of_month = 1
  form.format = 'excel'
  form.active = true
  drawer.value = true
}

function openEdit(r: ScheduledReport) {
  editId.value = r.id
  form.name         = r.name
  form.report_key   = r.report_key
  form.cadence      = r.cadence.toLowerCase()
  form.run_time     = r.run_time
  form.day_of_week  = r.day_of_week ?? 1
  form.day_of_month = r.day_of_month ?? 1
  form.format       = r.format
  form.recipients   = r.recipients
  form.active       = r.active
  drawer.value = true
}

function save() {
  if (editId.value) {
    form.patch(route('disciplinary.reports.scheduled.update', { scheduledReport: editId.value }), {
      onSuccess: () => { drawer.value = false },
    })
  } else {
    form.post(route('disciplinary.reports.scheduled.store'), {
      onSuccess: () => { drawer.value = false },
    })
  }
}

function confirmDelete(r: ScheduledReport) {
  deleteTarget.value = r
  deleteDialog.value = true
}

function doDelete() {
  if (!deleteTarget.value) return
  router.delete(route('disciplinary.reports.scheduled.destroy', { scheduledReport: deleteTarget.value.id }), {
    onSuccess: () => { deleteDialog.value = false; deleteTarget.value = null },
  })
}

const cadenceColor = (c: string) =>
  ({ daily: 'info', weekly: 'warning', monthly: 'success' })[c.toLowerCase()] ?? 'grey'

const formatColor = (f: string) =>
  ({ pdf: 'error', excel: 'success', both: 'primary' })[f] ?? 'grey'

const reportKeyItems = Object.entries(props.reportKeys).map(([k, v]) => ({ value: k, title: v }))

const cadenceItems = [
  { value: 'daily', title: t('disciplinary_reports.scheduled.cadence_options.daily') },
  { value: 'weekly', title: t('disciplinary_reports.scheduled.cadence_options.weekly') },
  { value: 'monthly', title: t('disciplinary_reports.scheduled.cadence_options.monthly') },
]

const formatItems = [
  { value: 'excel', title: t('disciplinary_reports.scheduled.format_options.excel') },
  { value: 'pdf', title: t('disciplinary_reports.scheduled.format_options.pdf') },
  { value: 'both', title: t('disciplinary_reports.scheduled.format_options.both') },
]

const dayOfWeekItems = [
  { value: 1, title: t('disciplinary_reports.scheduled.day_full.monday') },
  { value: 2, title: t('disciplinary_reports.scheduled.day_full.tuesday') },
  { value: 3, title: t('disciplinary_reports.scheduled.day_full.wednesday') },
  { value: 4, title: t('disciplinary_reports.scheduled.day_full.thursday') },
  { value: 5, title: t('disciplinary_reports.scheduled.day_full.friday') },
  { value: 6, title: t('disciplinary_reports.scheduled.day_full.saturday') },
  { value: 7, title: t('disciplinary_reports.scheduled.day_full.sunday') },
]

const dayNames = ['',
  t('disciplinary_reports.scheduled.day_names.mon'),
  t('disciplinary_reports.scheduled.day_names.tue'),
  t('disciplinary_reports.scheduled.day_names.wed'),
  t('disciplinary_reports.scheduled.day_names.thu'),
  t('disciplinary_reports.scheduled.day_names.fri'),
  t('disciplinary_reports.scheduled.day_names.sat'),
  t('disciplinary_reports.scheduled.day_names.sun'),
]
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_reports.scheduled.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_reports.scheduled.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="tabler-chart-bar" :href="route('disciplinary.reports.index')">
          {{ t('disciplinary_reports.scheduled.reports') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('disciplinary_reports.scheduled.new_scheduled_report') }}
        </VBtn>
      </div>
    </div>

    <!-- Empty state -->
    <template v-if="!reports.length">
      <VCard class="text-center pa-12" variant="outlined">
        <VIcon size="56" class="mb-3 opacity-30">tabler-calendar-clock</VIcon>
        <div class="text-h6 mb-2">{{ t('disciplinary_reports.scheduled.no_scheduled_title') }}</div>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('disciplinary_reports.scheduled.no_scheduled_subtitle') }}</p>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('disciplinary_reports.scheduled.create_first') }}</VBtn>
      </VCard>
    </template>

    <!-- Reports table -->
    <VCard v-else>
      <VDataTable
        :items="reports"
        :headers="[
          { title: t('disciplinary_reports.scheduled.name'), key: 'name' },
          { title: t('disciplinary_reports.scheduled.report'), key: 'report_key' },
          { title: t('disciplinary_reports.scheduled.cadence'), key: 'cadence' },
          { title: t('disciplinary_reports.scheduled.run_time'), key: 'run_time' },
          { title: t('disciplinary_reports.scheduled.format'), key: 'format' },
          { title: t('disciplinary_reports.scheduled.recipients'), key: 'recipients' },
          { title: t('disciplinary_reports.scheduled.next_run'), key: 'next_run' },
          { title: t('disciplinary_reports.scheduled.last_run'), key: 'last_run_at' },
          { title: t('disciplinary_reports.scheduled.active'), key: 'active' },
          { title: '', key: 'actions', sortable: false },
        ]"
        density="comfortable"
      >
        <template #item.report_key="{ item }">
          <span class="text-body-2">{{ reportKeys[item.report_key] ?? item.report_key }}</span>
        </template>
        <template #item.cadence="{ item }">
          <VChip :color="cadenceColor(item.cadence)" size="small" variant="tonal">{{ item.cadence }}</VChip>
        </template>
        <template #item.run_time="{ item }">
          <span class="font-mono text-body-2">{{ item.run_time }}</span>
          <span v-if="item.cadence.toLowerCase() === 'weekly' && item.day_of_week" class="text-caption text-medium-emphasis ml-1">
            ({{ dayNames[item.day_of_week] ?? '' }})
          </span>
          <span v-if="item.cadence.toLowerCase() === 'monthly' && item.day_of_month" class="text-caption text-medium-emphasis ml-1">
            (day {{ item.day_of_month }})
          </span>
        </template>
        <template #item.format="{ item }">
          <VChip :color="formatColor(item.format)" size="x-small" variant="tonal">{{ item.format.toUpperCase() }}</VChip>
        </template>
        <template #item.recipients="{ item }">
          <span class="text-caption text-medium-emphasis" style="max-width:180px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
            {{ item.recipients }}
          </span>
        </template>
        <template #item.last_run_at="{ item }">{{ item.last_run_at ?? '—' }}</template>
        <template #item.active="{ item }">
          <VChip :color="item.active ? 'success' : 'grey'" size="x-small" variant="tonal">
            {{ item.active ? t('disciplinary_reports.scheduled.active') : t('disciplinary_reports.scheduled.paused') }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openEdit(item)" />
            <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="confirmDelete(item)" />
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create / Edit Drawer -->
    <VNavigationDrawer v-model="drawer" location="right" :width="480" temporary>
      <div class="pa-4">
        <div class="d-flex align-center justify-space-between mb-4">
          <span class="text-body-1 font-weight-bold">{{ editId ? t('disciplinary_reports.scheduled.drawer_title_edit') : t('disciplinary_reports.scheduled.drawer_title_new') }}</span>
          <VBtn icon="tabler-x" variant="text" size="small" @click="drawer = false" />
        </div>

        <VTextField v-model="form.name" :label="t('disciplinary_reports.scheduled.report_name_label')" variant="outlined" density="compact"
          class="mb-3" :error-messages="form.errors.name" />

        <VSelect v-model="form.report_key" :label="t('disciplinary_reports.scheduled.report_type_label')"
          :items="reportKeyItems"
          item-value="value" item-title="title"
          variant="outlined" density="compact" class="mb-3" :error-messages="form.errors.report_key" />

        <VSelect v-model="form.cadence" :label="t('disciplinary_reports.scheduled.cadence_label')"
          :items="cadenceItems"
          variant="outlined" density="compact" class="mb-3" />

        <VTextField v-model="form.run_time" :label="t('disciplinary_reports.scheduled.run_time_label')" variant="outlined"
          density="compact" class="mb-3" placeholder="07:00" />

        <VSelect v-if="form.cadence === 'weekly'" v-model="form.day_of_week" :label="t('disciplinary_reports.scheduled.day_of_week_label')"
          :items="dayOfWeekItems"
          variant="outlined" density="compact" class="mb-3" />

        <VTextField v-if="form.cadence === 'monthly'" v-model.number="form.day_of_month"
          :label="t('disciplinary_reports.scheduled.day_of_month_label')" type="number" :min="1" :max="28"
          variant="outlined" density="compact" class="mb-3" />

        <VSelect v-model="form.format" :label="t('disciplinary_reports.scheduled.format_label')"
          :items="formatItems"
          variant="outlined" density="compact" class="mb-3" />

        <VTextarea v-model="form.recipients" :label="t('disciplinary_reports.scheduled.recipients_label')"
          variant="outlined" density="compact" rows="3" class="mb-3"
          placeholder="hr@company.com, manager@company.com"
          :error-messages="form.errors.recipients" />

        <VSwitch v-model="form.active" :label="t('disciplinary_reports.scheduled.active_label')" color="success" class="mb-4" />

        <div class="d-flex ga-2 justify-end">
          <VBtn variant="outlined" @click="drawer = false">{{ t('disciplinary_reports.scheduled.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editId ? t('disciplinary_reports.scheduled.update') : t('disciplinary_reports.scheduled.create') }}
          </VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Delete confirmation -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard :title="t('disciplinary_reports.scheduled.delete_title')">
        <VCardText>
          {{ t('disciplinary_reports.scheduled.delete_confirm', { name: deleteTarget?.name }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="deleteDialog = false">{{ t('disciplinary_reports.scheduled.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('disciplinary_reports.scheduled.delete_button') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
