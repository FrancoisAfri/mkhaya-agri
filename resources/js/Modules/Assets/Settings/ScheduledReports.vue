<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface ScheduledReport {
  id: number; name: string; report_key: string; cadence: string; run_time: string
  day_of_week: number | null; day_of_month: number | null; format: string
  recipients: string; active: boolean; next_run: string; last_run_at: string | null
}

const props = defineProps<{ reports: ScheduledReport[]; reportKeys: Record<string, string> }>()

const drawer = ref(false); const deleteDialog = ref(false)
const editTarget = ref<ScheduledReport | null>(null); const deleteTarget = ref<ScheduledReport | null>(null)
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const isEdit = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? t('assets_scheduled_reports.drawer.edit_title') : t('assets_scheduled_reports.drawer.new_title'))

const emptyForm = () => ({
  name: '', report_key: '', cadence: 'daily' as 'daily' | 'weekly' | 'monthly', run_time: '07:00',
  day_of_week: null as number | null, day_of_month: null as number | null,
  format: 'pdf' as 'pdf' | 'excel' | 'both', recipients: '', active: true,
})
const form = useForm(emptyForm())

const openCreate = () => { editTarget.value = null; form.reset(); Object.assign(form, emptyForm()); drawer.value = true }
const openEdit = (r: ScheduledReport) => {
  editTarget.value = r; form.name = r.name; form.report_key = r.report_key
  form.cadence = r.cadence.toLowerCase() as any; form.run_time = r.run_time
  form.day_of_week = r.day_of_week; form.day_of_month = r.day_of_month
  form.format = r.format as any; form.recipients = r.recipients; form.active = r.active; drawer.value = true
}
const submitForm = () => {
  if (isEdit.value && editTarget.value) {
    form.patch(route('assets.settings.scheduled-reports.update', editTarget.value.id), { onSuccess: () => { drawer.value = false; toast(t('assets_scheduled_reports.snackbar.updated')) } })
  } else {
    form.post(route('assets.settings.scheduled-reports.store'), { onSuccess: () => { drawer.value = false; toast(t('assets_scheduled_reports.snackbar.created')) } })
  }
}
const confirmDelete = (r: ScheduledReport) => { deleteTarget.value = r; deleteDialog.value = true }
const doDelete = () => {
  if (!deleteTarget.value) return
  router.delete(route('assets.settings.scheduled-reports.destroy', deleteTarget.value.id), { onSuccess: () => { deleteDialog.value = false; toast(t('assets_scheduled_reports.snackbar.deleted'), 'error') } })
}
const toast = (text: string, color = 'success') => { snack.value = { show: true, text, color } }

const cadenceOptions = computed(()=> [
  { title: t('assets_scheduled_reports.cadences.daily'), value: 'daily' },
  { title: t('assets_scheduled_reports.cadences.weekly'), value: 'weekly' },
  { title: t('assets_scheduled_reports.cadences.monthly'), value: 'monthly' },
])
const formatOptions = computed(()=> [
  { title: t('assets_scheduled_reports.formats.pdf'), value: 'pdf' },
  { title: t('assets_scheduled_reports.formats.excel'), value: 'excel' },
  { title: t('assets_scheduled_reports.formats.both'), value: 'both' },
])
const dayOfWeekOptions = computed(() => [
  { title: t('assets_scheduled_reports.weekdays.monday'), value: 1 },
  { title: t('assets_scheduled_reports.weekdays.tuesday'), value: 2 },
  { title: t('assets_scheduled_reports.weekdays.wednesday'), value: 3 },
  { title: t('assets_scheduled_reports.weekdays.thursday'), value: 4 },
  { title: t('assets_scheduled_reports.weekdays.friday'), value: 5 },
  { title: t('assets_scheduled_reports.weekdays.saturday'), value: 6 },
  { title: t('assets_scheduled_reports.weekdays.sunday'), value: 7 },
])
const reportKeyOptions = computed(() => Object.entries(props.reportKeys).map(([value, title]) => ({ title, value })))
const tableHeaders = computed(()=> [
  { title: t('assets_scheduled_reports.table.name'), key: 'name', sortable: true },
  { title: t('assets_scheduled_reports.table.report'), key: 'report_key', sortable: true },
  { title: t('assets_scheduled_reports.table.cadence'), key: 'cadence', sortable: true },
  { title: t('assets_scheduled_reports.table.format'), key: 'format', sortable: false },
  { title: t('assets_scheduled_reports.table.recipients'), key: 'recipients', sortable: false },
  { title: t('assets_scheduled_reports.table.next_run'), key: 'next_run', sortable: false },
  { title: t('assets_scheduled_reports.table.last_run'), key: 'last_run_at', sortable: false },
  { title: t('assets_scheduled_reports.table.status'), key: 'active', sortable: true },
  { title: '', key: 'actions', sortable: false },
])
const cadenceColor = (c: string) => ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')
const formatLabel = (f: string) => ({ pdf: 'PDF', excel: 'CSV', both: 'PDF+CSV' }[f] ?? f)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center"><VCol cols="12" lg="11">
      <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
        <div>
          <h4 class="text-h4">{{ t('assets_scheduled_reports.page_title') }}</h4>
          <p class="text-medium-emphasis mb-0">{{ t('assets_scheduled_reports.description') }}</p>
        </div>
        <div class="d-flex gap-2">
          <VBtn variant="tonal" prepend-icon="tabler-arrow-left" :href="route('assets.settings.index')">{{ t('assets_scheduled_reports.settings') }}</VBtn>
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('assets_scheduled_reports.new_schedule') }}</VBtn>
        </div>
      </div>
      <VCard>
        <VDataTable :headers="tableHeaders" :items="reports" :items-per-page="15" hover>
          <template #item.report_key="{ item }">{{ reportKeys[item.report_key] ?? item.report_key }}</template>
          <template #item.cadence="{ item }"><VChip :color="cadenceColor(item.cadence)" size="small" label>{{ item.cadence }}</VChip></template>
          <template #item.format="{ item }"><VChip size="small" label variant="tonal">{{ formatLabel(item.format) }}</VChip></template>
          <template #item.recipients="{ item }"><span class="text-caption">{{ item.recipients.length > 40 ? item.recipients.slice(0, 38) + '…' : item.recipients }}</span></template>
          <template #item.active="{ item }"><VChip :color="item.active ? 'success' : 'default'" size="small" label>{{ item.active ? t('assets_scheduled_reports.active') : t('assets_scheduled_reports.paused') }}</VChip></template>
          <template #item.last_run_at="{ item }"><span class="text-caption text-medium-emphasis">{{ item.last_run_at ?? '—' }}</span></template>
          <template #item.actions="{ item }">
            <VBtn icon size="small" variant="text" @click="openEdit(item)"><VIcon icon="tabler-pencil" /></VBtn>
            <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)"><VIcon icon="tabler-trash" /></VBtn>
          </template>
          <template #no-data>
            <div class="pa-8 text-center text-medium-emphasis">
              <VIcon icon="tabler-calendar-clock" size="48" class="mb-3 d-block mx-auto opacity-40" />
              <p class="mb-1">{{ t('assets_scheduled_reports.empty.title') }}</p>
              <p class="text-caption">{{ t('assets_scheduled_reports.empty.description') }}</p>
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VCol></VRow>
  </VContainer>

  <VNavigationDrawer v-model="drawer" temporary location="right" width="480">
    <VForm @submit.prevent="submitForm" class="h-100 d-flex flex-column">
      <div class="pa-5 border-b"><h5 class="text-h5">{{ drawerTitle }}</h5></div>
      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <VCol cols="12"><VTextField v-model="form.name" :label="t('assets_scheduled_reports.drawer.name')" :placeholder="t('assets_scheduled_reports.drawer.name_placeholder')" :error-messages="form.errors.name" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VSelect v-model="form.report_key" :items="reportKeyOptions" :label="t('assets_scheduled_reports.drawer.report_type')" :error-messages="form.errors.report_key" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12" sm="6"><VSelect v-model="form.cadence" :items="cadenceOptions" :label="t('assets_scheduled_reports.drawer.cadence')" :error-messages="form.errors.cadence" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12" sm="6"><VTextField v-model="form.run_time" :label="t('assets_scheduled_reports.drawer.run_time')" type="time" :error-messages="form.errors.run_time" variant="outlined" density="comfortable" /></VCol>
          <VCol v-if="form.cadence === 'weekly'" cols="12"><VSelect v-model="form.day_of_week" :items="dayOfWeekOptions" :label="t('assets_scheduled_reports.drawer.day_of_week')" :error-messages="form.errors.day_of_week" variant="outlined" density="comfortable" /></VCol>
          <VCol v-if="form.cadence === 'monthly'" cols="12"><VTextField v-model="form.day_of_month" :label="t('assets_scheduled_reports.drawer.day_of_month')" type="number" :min="1" :max="28" :hint="t('assets_scheduled_reports.drawer.day_of_month_hint')" persistent-hint :error-messages="form.errors.day_of_month" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VSelect v-model="form.format" :items="formatOptions" :label="t('assets_scheduled_reports.drawer.format')" :error-messages="form.errors.format" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VTextField v-model="form.recipients" :label="t('assets_scheduled_reports.drawer.recipients')" :placeholder="t('assets_scheduled_reports.drawer.recipients_placeholder')" :hint="t('assets_scheduled_reports.drawer.recipients_hint')" persistent-hint :error-messages="form.errors.recipients" variant="outlined" density="comfortable" /></VCol>
          <VCol cols="12"><VSwitch v-model="form.active" :label="t('assets_scheduled_reports.drawer.active')" color="primary" density="compact" hide-details /></VCol>
        </VRow>
      </div>
      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('assets_scheduled_reports.drawer.cancel') }}</VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="ri-save-line">
          {{ isEdit ? t('assets_scheduled_reports.drawer.save') : t('assets_scheduled_reports.drawer.create') }}
        </VBtn>
      </div>
    </VForm>
  </VNavigationDrawer>

  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">{{ t('assets_scheduled_reports.delete.title') }}</VCardTitle>
      <VCardText class="px-5">{{ t('assets_scheduled_reports.delete.message', { name: deleteTarget?.name }) }}</VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">{{ t('assets_scheduled_reports.delete.cancel') }}</VBtn>
        <VBtn color="error" @click="doDelete">{{ t('assets_scheduled_reports.delete.delete') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
</template>
