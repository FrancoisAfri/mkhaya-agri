<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Report { id: number; name: string; report_key: string; report_label: string; cadence: string; run_time: string; day_of_week: number | null; day_of_month: number | null; recipients: string; active: boolean; next_run: string; last_run: string | null }

const props = defineProps<{ reports: Report[]; reportKeys: Record<string, string>; cadences: Record<string, string> }>()

const dialog = ref(false)
const days = [
  { title: t('crm_scheduled_reports_index.days.monday'), value: 1 }, { title: t('crm_scheduled_reports_index.days.tuesday'), value: 2 },
  { title: t('crm_scheduled_reports_index.days.wednesday'), value: 3 }, { title: t('crm_scheduled_reports_index.days.thursday'), value: 4 },
  { title: t('crm_scheduled_reports_index.days.friday'), value: 5 }, { title: t('crm_scheduled_reports_index.days.saturday'), value: 6 },
  { title: t('crm_scheduled_reports_index.days.sunday'), value: 7 },
]

const blank = () => ({ id: null as number | null, name: '', report_key: 'funnel', cadence: 'weekly', run_time: '07:00', day_of_week: 1 as number | null, day_of_month: 1 as number | null, format: 'csv', recipients: '', active: true })
const form = useForm(blank())

function openCreate() { form.defaults(blank()); form.reset(); dialog.value = true }
function openEdit(r: Report) { form.defaults({ id: r.id, name: r.name, report_key: r.report_key, cadence: r.cadence, run_time: r.run_time, day_of_week: r.day_of_week ?? 1, day_of_month: r.day_of_month ?? 1, format: 'csv', recipients: r.recipients, active: r.active }); form.reset(); dialog.value = true }
function save() { if (form.id) form.patch(route('crm.scheduled-reports.update', form.id), { preserveScroll: true, onSuccess: () => dialog.value = false }); else form.post(route('crm.scheduled-reports.store'), { preserveScroll: true, onSuccess: () => dialog.value = false }) }
function toggle(r: Report) { useForm({}).post(route('crm.scheduled-reports.toggle', r.id), { preserveScroll: true }) }
function runNow(r: Report) { useForm({}).post(route('crm.scheduled-reports.run', r.id), { preserveScroll: true }) }
function remove(r: Report) { useForm({}).delete(route('crm.scheduled-reports.destroy', r.id), { preserveScroll: true }) }

const headers = [
  { title: t('crm_scheduled_reports_index.table.name'), key: 'name' }, { title: t('crm_scheduled_reports_index.table.report'), key: 'report_label' },
  { title: t('crm_scheduled_reports_index.table.cadence'), key: 'cadence' }, { title: t('crm_scheduled_reports_index.table.next_run'), key: 'next_run' },
  { title: t('crm_scheduled_reports_index.table.recipients'), key: 'recipients' }, { title: t('crm_scheduled_reports_index.table.active'), key: 'active' },
  { title: '', key: 'actions', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_scheduled_reports_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_scheduled_reports_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_scheduled_reports_index.new_schedule') }}</VBtn></div>

    <VCard><VDataTable :headers="headers" :items="reports" :items-per-page="25" density="comfortable">
      <template #item.cadence="{ item }"><span class="text-capitalize">{{ item.cadence }}</span> · {{ item.run_time }}</template>
      <template #item.active="{ item }"><VChip size="small" :color="item.active ? 'success' : 'blue-grey'" label>{{ item.active ? t('crm_scheduled_reports_index.active') : t('crm_scheduled_reports_index.paused') }}</VChip></template>
      <template #item.actions="{ item }"><div class="d-flex gap-1"><VBtn size="x-small" variant="tonal" prepend-icon="tabler-send" @click="runNow(item)">{{ t('crm_scheduled_reports_index.run_now') }}</VBtn><VBtn size="x-small" variant="text" :icon="item.active ? 'tabler-player-pause' : 'tabler-player-play'" @click="toggle(item)" /><VBtn size="x-small" variant="text" icon="tabler-edit" @click="openEdit(item)" /><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(item)" /></div></template>
    </VDataTable></VCard>

    <VDialog v-model="dialog" max-width="560" persistent><VCard><VCardTitle>{{ form.id ? t('crm_scheduled_reports_index.dialog.title_edit') : t('crm_scheduled_reports_index.dialog.title_create') }}</VCardTitle><VCardText><VTextField v-model="form.name" :label="t('crm_scheduled_reports_index.dialog.name')" class="mb-3" /><VRow><VCol cols="6"><VSelect v-model="form.report_key" :label="t('crm_scheduled_reports_index.dialog.report')" :items="Object.entries(reportKeys).map(([v, tp]) => ({ title: tp, value: v }))" /></VCol><VCol cols="6"><VSelect v-model="form.cadence" :label="t('crm_scheduled_reports_index.dialog.cadence')" :items="Object.entries(cadences).map(([v, tp]) => ({ title: tp, value: v }))" /></VCol><VCol cols="6"><VTextField v-model="form.run_time" :label="t('crm_scheduled_reports_index.dialog.run_time')" placeholder="07:00" /></VCol><VCol v-if="form.cadence === 'weekly'" cols="6"><VSelect v-model="form.day_of_week" :label="t('crm_scheduled_reports_index.dialog.day_of_week')" :items="days" /></VCol><VCol v-if="form.cadence === 'monthly'" cols="6"><VTextField v-model.number="form.day_of_month" type="number" :label="t('crm_scheduled_reports_index.dialog.day_of_month')" /></VCol><VCol cols="12"><VTextField v-model="form.recipients" :label="t('crm_scheduled_reports_index.dialog.recipients')" /></VCol></VRow><VSwitch v-model="form.active" :label="t('crm_scheduled_reports_index.dialog.active')" color="success" hide-details /></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_scheduled_reports_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_scheduled_reports_index.dialog.save') }}</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>