<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ScheduledReport {
  id: number; name: string; report_key: string; cadence: string; run_time: string
  day_of_week: number | null; day_of_month: number | null; format: string
  recipients: string; active: boolean; next_run_at: string | null; last_run_at: string | null
}

const props = defineProps<{ reports: ScheduledReport[]; reportKeys: Record<string, string> }>()

const drawer = ref(false); const deleteDialog = ref(false)
const editTarget = ref<ScheduledReport | null>(null); const deleteTarget = ref<ScheduledReport | null>(null)
const snack = ref({ show: false, text: '', color: 'success' })

const isEdit = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? t('pa_reports.scheduled.edit_title') : t('pa_reports.scheduled.new_title'))

const emptyForm = () => ({
  name: '', report_key: '', cadence: 'daily' as 'daily'|'weekly'|'monthly', run_time: '07:00',
  day_of_week: null as number | null, day_of_month: null as number | null,
  format: 'pdf' as 'pdf'|'excel'|'both', recipients: '', active: true,
})

const form = useForm(emptyForm())

function openCreate() { editTarget.value = null; form.reset(); Object.assign(form, emptyForm()); drawer.value = true }
function openEdit(r: ScheduledReport) {
  editTarget.value = r; form.name = r.name; form.report_key = r.report_key
  form.cadence = r.cadence.toLowerCase() as any; form.run_time = r.run_time
  form.day_of_week = r.day_of_week; form.day_of_month = r.day_of_month
  form.format = r.format as any; form.recipients = r.recipients; form.active = r.active
  drawer.value = true
}
function submitForm() {
  if (isEdit.value && editTarget.value) {
    form.patch(`/performance/reports/scheduled/${editTarget.value.id}`, { onSuccess: () => { drawer.value = false; toast(t('pa_reports.scheduled.toast_updated')) } })
  } else {
    form.post('/performance/reports/scheduled', { onSuccess: () => { drawer.value = false; toast(t('pa_reports.scheduled.toast_created')) } })
  }
}
function confirmDelete(r: ScheduledReport) { deleteTarget.value = r; deleteDialog.value = true }
function doDelete() {
  if (!deleteTarget.value) return
  router.delete(`/performance/reports/scheduled/${deleteTarget.value.id}`, { onSuccess: () => { deleteDialog.value = false; toast(t('pa_reports.scheduled.toast_deleted'), 'error') } })
}
function toast(text: string, color = 'success') { snack.value = { show: true, text, color } }
function formatDate(val: string | null): string {
  if (!val) return '—'; const d = new Date(val)
  if (Number.isNaN(d.getTime())) return val
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const cadenceOptions = computed(() => [
  { title: t('pa_reports.scheduled.cadence_options.daily'), value: 'daily' },
  { title: t('pa_reports.scheduled.cadence_options.weekly'), value: 'weekly' },
  { title: t('pa_reports.scheduled.cadence_options.monthly'), value: 'monthly' },
])
const formatOptions = computed(() => [
  { title: t('pa_reports.scheduled.format_options.pdf'), value: 'pdf' },
  { title: t('pa_reports.scheduled.format_options.excel'), value: 'excel' },
  { title: t('pa_reports.scheduled.format_options.both'), value: 'both' },
])
const dayOfWeekOptions = computed(() => [
  { title: t('pa_reports.scheduled.day_options.monday'), value: 1 },
  { title: t('pa_reports.scheduled.day_options.tuesday'), value: 2 },
  { title: t('pa_reports.scheduled.day_options.wednesday'), value: 3 },
  { title: t('pa_reports.scheduled.day_options.thursday'), value: 4 },
  { title: t('pa_reports.scheduled.day_options.friday'), value: 5 },
  { title: t('pa_reports.scheduled.day_options.saturday'), value: 6 },
  { title: t('pa_reports.scheduled.day_options.sunday'), value: 7 },
])
const reportKeyOptions = computed(() => Object.entries(props.reportKeys).map(([value, title]) => ({ title, value })))

const tableHeaders = [
  { title: t('pa_reports.table.name'),       key: 'name',        sortable: true  },
  { title: t('pa_reports.table.report'),     key: 'report_key',  sortable: true  },
  { title: t('pa_reports.table.cadence'),    key: 'cadence',     sortable: true  },
  { title: t('pa_reports.table.format'),     key: 'format',      sortable: false },
  { title: t('pa_reports.table.recipients'), key: 'recipients',  sortable: false },
  { title: t('pa_reports.table.next_run'),   key: 'next_run_at', sortable: false },
  { title: t('pa_reports.table.last_run'),   key: 'last_run_at', sortable: false },
  { title: t('pa_reports.table.status'),     key: 'active',      sortable: true  },
  { title: t('pa_reports.table.actions'),    key: 'actions',     sortable: false },
]

const cadenceColor = (c: string) => ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard class="pa-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('pa_reports.module') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('pa_reports.scheduled_reports') }}</h4>
            <p class="mb-0 text-white opacity-90">{{ t('pa_reports.scheduled.subtitle') }}</p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-chart-bar" href="/performance/reports">{{ t('pa_reports.reports_engine') }}</VBtn>
            <VBtn color="white" class="text-primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('pa_reports.scheduled.new_schedule') }}</VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between pa-4">
          <span>{{ reports.length }} {{ t('pa_reports.scheduled_reports') }}</span>
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('pa_reports.scheduled.new_schedule') }}</VBtn>
        </VCardTitle>
        <VDataTable :headers="tableHeaders" :items="reports" :items-per-page="20" hover>
          <template #item.report_key="{ item }">{{ reportKeys[item.report_key] ?? item.report_key }}</template>
          <template #item.cadence="{ item }"><VChip :color="cadenceColor(item.cadence)" size="small" label>{{ item.cadence }}</VChip></template>
          <template #item.format="{ item }"><VChip color="secondary" size="small" variant="tonal" label>{{ item.format.toUpperCase() }}</VChip></template>
          <template #item.next_run_at="{ item }">{{ formatDate(item.next_run_at) }}</template>
          <template #item.last_run_at="{ item }">{{ formatDate(item.last_run_at) }}</template>
          <template #item.active="{ item }"><VChip :color="item.active ? 'success' : 'default'" size="small" label>{{ item.active ? t('pa_reports.scheduled.active') : t('pa_reports.scheduled.paused') }}</VChip></template>
          <template #item.actions="{ item }">
            <VBtn icon size="small" variant="text" @click="openEdit(item)"><VIcon icon="tabler-pencil" /></VBtn>
            <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)"><VIcon icon="tabler-trash" /></VBtn>
          </template>
          <template #no-data>
            <div class="pa-8 text-center text-medium-emphasis">
              <VIcon icon="tabler-calendar-clock" size="48" class="mb-3 d-block mx-auto" />
              <div>{{ t('pa_reports.scheduled.empty') }}</div>
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VCol>
  </VRow>

  <VNavigationDrawer v-model="drawer" temporary location="right" width="480">
    <VForm class="h-100 d-flex flex-column" @submit.prevent="submitForm">
      <div class="pa-5 border-b"><h5 class="text-h5">{{ drawerTitle }}</h5></div>
      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <VCol cols="12"><VTextField v-model="form.name" :label="t('pa_reports.scheduled.schedule_name')" :placeholder="t('pa_reports.scheduled.schedule_name_placeholder')" :error-messages="form.errors.name" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VSelect v-model="form.report_key" :items="reportKeyOptions" :label="t('pa_reports.scheduled.report_type')" :error-messages="form.errors.report_key" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12" sm="6"><VSelect v-model="form.cadence" :items="cadenceOptions" :label="t('pa_reports.scheduled.cadence')" :error-messages="form.errors.cadence" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12" sm="6"><VTextField v-model="form.run_time" :label="t('pa_reports.scheduled.run_time')" placeholder="07:00" type="time" :error-messages="form.errors.run_time" variant="outlined" density="comfortable" /></VCol>
          <VCol v-if="form.cadence === 'weekly'" cols="12"><VSelect v-model="form.day_of_week" :items="dayOfWeekOptions" :label="t('pa_reports.scheduled.day_of_week')" :error-messages="form.errors.day_of_week" variant="outlined" density="comfortable" /></VCol>
          <VCol v-if="form.cadence === 'monthly'" cols="12"><VTextField v-model="form.day_of_month" :label="t('pa_reports.scheduled.day_of_month')" type="number" :min="1" :max="28" :error-messages="form.errors.day_of_month" variant="outlined" density="comfortable" :hint="t('pa_reports.scheduled.day_of_month_hint')" persistent-hint /></VCol>
          <VCol cols="12"><VSelect v-model="form.format" :items="formatOptions" :label="t('pa_reports.scheduled.output_format')" :error-messages="form.errors.format" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VTextField v-model="form.recipients" :label="t('pa_reports.scheduled.recipients')" :placeholder="t('pa_reports.scheduled.recipients_placeholder')" :error-messages="form.errors.recipients" variant="outlined" density="comfortable" :hint="t('pa_reports.scheduled.recipients_hint')" persistent-hint /></VCol>
          <VCol cols="12"><VSwitch v-model="form.active" :label="t('pa_reports.scheduled.active')" color="primary" density="compact" hide-details /></VCol>
        </VRow>
      </div>
      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('pa.common.cancel') }}</VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="ri-save-line">{{ isEdit ? t('pa.cycles.save_changes') : t('pa_reports.scheduled.create_schedule') }}</VBtn>
      </div>
    </VForm>
  </VNavigationDrawer>

  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">{{ t('pa_reports.scheduled.delete_title') }}</VCardTitle>
      <VCardText class="px-5">{{ t('pa.cycles.delete_confirm') }} <strong>{{ deleteTarget?.name }}</strong>? {{ t('pa.cycles.cannot_undo') }}</VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">{{ t('pa.common.cancel') }}</VBtn>
        <VBtn color="error" @click="doDelete">{{ t('pa.common.delete') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
</template>

<style scoped lang="scss">
.pa-ribbon { border: 0; overflow: hidden; background: linear-gradient(135deg, #1a237e 0%, #7367f0 100%); }
</style>
