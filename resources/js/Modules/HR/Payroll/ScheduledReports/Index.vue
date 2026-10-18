<template>
  <VContainer fluid class="pa-4">
      <div class="d-flex align-center justify-space-between mb-4">
        <div>
          <h1 class="text-h5 font-weight-bold">{{ t('payroll_scheduled_reports.page_title') }}</h1>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('payroll_scheduled_reports.description') }}</p>
        </div>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('payroll_scheduled_reports.new_schedule') }}</VBtn>
      </div>

      <VCard elevation="0" border>
        <VDataTable :headers="headers" :items="reports" density="comfortable" hover>
          <template #item.report_label="{ item }">
            <VChip color="primary" size="small" variant="tonal">{{ item.report_label }}</VChip>
          </template>
          <template #item.cadence_label="{ item }">
            <div class="text-body-2">{{ item.cadence_label }}</div>
          </template>
          <template #item.format="{ item }">
            <VChip :color="item.format === 'both' ? 'secondary' : item.format === 'pdf' ? 'error' : 'success'" size="x-small" variant="tonal">
              {{ item.format.toUpperCase() }}
            </VChip>
          </template>
          <template #item.recipients="{ item }">
            <div class="text-caption text-truncate" style="max-width:200px" :title="item.recipients">{{ item.recipients }}</div>
          </template>
          <template #item.next_run_at="{ item }">
            <div class="text-body-2">{{ formatDate(item.next_run_at) }}</div>
          </template>
          <template #item.last_run_at="{ item }">
            <div class="text-caption text-medium-emphasis">{{ item.last_run_at ? formatDate(item.last_run_at) : t('payroll_scheduled_reports.never') }}</div>
          </template>
          <template #item.active="{ item }">
            <VChip :color="item.active ? 'success' : 'default'" size="x-small" variant="tonal">
              {{ item.active ? t('payroll_scheduled_reports.active') : t('payroll_scheduled_reports.paused') }}
            </VChip>
          </template>
          <template #item.actions="{ item }">
            <div class="d-flex gap-1">
              <VBtn
                icon="tabler-pencil" size="small" variant="text" color="primary" class="row-action"
                :aria-label="`Edit the ${item.name ?? 'scheduled report'} schedule`" @click="openEdit(item)"
              />
              <VBtn
                icon="tabler-trash" size="small" variant="text" color="error" class="row-action"
                :aria-label="`Delete the ${item.name ?? 'scheduled report'} schedule`" @click="confirmDelete(item)"
              />
            </div>
          </template>
          <template #no-data>
            <div class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-calendar-clock" size="48" class="mb-2 d-block" />
              {{ t('payroll_scheduled_reports.empty') }}
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VContainer>

    <VNavigationDrawer v-model="drawer" location="right" width="420" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle>{{ editing ? t('payroll_scheduled_reports.dialog.edit_title') : t('payroll_scheduled_reports.dialog.new_title') }}</VToolbarTitle>
        <VBtn icon="tabler-x" aria-label="Close" class="row-action" @click="drawer = false" />
      </VToolbar>

      <VForm ref="formRef" @submit.prevent="submitForm" class="pa-4">
        <VTextField v-model="form.name" :label="t('payroll_scheduled_reports.dialog.name')"
          :rules="[v => !!v || t('payroll_scheduled_reports.dialog.name_required')]" density="compact" class="mb-3" />

        <VSelect v-model="form.report_key" :items="reportKeyItems" item-title="label" item-value="key"
          :label="t('payroll_scheduled_reports.dialog.report_type')"
          :rules="[v => !!v || t('payroll_scheduled_reports.dialog.name_required')]" density="compact" class="mb-3" />

        <VSelect v-model="form.cadence"
          :items="[
            {title: t('payroll_scheduled_reports.dialog.cadence_daily'), value: 'daily'},
            {title: t('payroll_scheduled_reports.dialog.cadence_weekly'), value: 'weekly'},
            {title: t('payroll_scheduled_reports.dialog.cadence_monthly'), value: 'monthly'}
          ]"
          item-title="title" item-value="value" :label="t('payroll_scheduled_reports.dialog.cadence')" density="compact" class="mb-3" />

        <VTextField v-model="form.run_time" :label="t('payroll_scheduled_reports.dialog.run_time')"
          placeholder="07:00" :rules="[v => /^\d{2}:\d{2}$/.test(v) || t('payroll_scheduled_reports.dialog.run_time_format')]" density="compact" class="mb-3" />

        <VSelect v-if="form.cadence === 'weekly'" v-model="form.day_of_week" :items="weekdays"
          item-title="label" item-value="value" :label="t('payroll_scheduled_reports.dialog.day_of_week')" density="compact" class="mb-3" />

        <VTextField v-if="form.cadence === 'monthly'" v-model.number="form.day_of_month"
          :label="t('payroll_scheduled_reports.dialog.day_of_month')" type="number"
          :rules="[v => (v >= 1 && v <= 28) || t('payroll_scheduled_reports.dialog.day_of_month_rule')]" density="compact" class="mb-3" />

        <VSelect v-model="form.format"
          :items="[
            {title: t('payroll_scheduled_reports.dialog.format_pdf'), value: 'pdf'},
            {title: t('payroll_scheduled_reports.dialog.format_excel'), value: 'excel'},
            {title: t('payroll_scheduled_reports.dialog.format_both'), value: 'both'}
          ]"
          item-title="title" item-value="value" :label="t('payroll_scheduled_reports.dialog.format')" density="compact" class="mb-3" />

        <VTextarea v-model="form.recipients" :label="t('payroll_scheduled_reports.dialog.recipients')"
          :rules="[v => !!v || t('payroll_scheduled_reports.dialog.name_required')]" density="compact" rows="2" class="mb-3"
          :hint="t('payroll_scheduled_reports.dialog.recipients_hint')" persistent-hint />

        <VSwitch v-model="form.active" :label="t('payroll_scheduled_reports.dialog.active')" color="primary" class="mb-3" />

        <div class="d-flex gap-2 justify-end mt-2">
          <VBtn variant="text" @click="drawer = false">{{ t('payroll_scheduled_reports.dialog.cancel') }}</VBtn>
          <VBtn type="submit" color="primary" variant="flat" :loading="submitting">
            {{ editing ? t('payroll_scheduled_reports.dialog.update') : t('payroll_scheduled_reports.dialog.create') }}
          </VBtn>
        </div>
      </VForm>
    </VNavigationDrawer>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard>
        <VCardTitle class="pa-4">{{ t('payroll_scheduled_reports.delete.title') }}</VCardTitle>
        <VCardText class="pa-4 pt-0">
          {{ t('payroll_scheduled_reports.delete.message', { name: deleteTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('payroll_scheduled_reports.delete.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deleting" @click="submitDelete">{{ t('payroll_scheduled_reports.delete.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">
      {{ snackbar.text }}
    </VSnackbar>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  reports:    any[]
  reportKeys: Record<string, string>
}>()

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const headers =  computed (()=>[
  { title: t('payroll_scheduled_reports.table.name'),        key: 'name' },
  { title: t('payroll_scheduled_reports.table.report'),      key: 'report_label' },
  { title: t('payroll_scheduled_reports.table.schedule'),    key: 'cadence_label' },
  { title: t('payroll_scheduled_reports.table.format'),      key: 'format' },
  { title: t('payroll_scheduled_reports.table.recipients'),  key: 'recipients', sortable: false },
  { title: t('payroll_scheduled_reports.table.next_run'),    key: 'next_run_at' },
  { title: t('payroll_scheduled_reports.table.last_run'),    key: 'last_run_at' },
  { title: t('payroll_scheduled_reports.table.status'),      key: 'active' },
  { title: '',            key: 'actions', sortable: false, align: 'end' as const },
])

const weekdays = computed(() => [
  { label: t('payroll_scheduled_reports.weekdays.monday'), value: 1 },
  { label: t('payroll_scheduled_reports.weekdays.tuesday'), value: 2 },
  { label: t('payroll_scheduled_reports.weekdays.wednesday'), value: 3 },
  { label: t('payroll_scheduled_reports.weekdays.thursday'), value: 4 },
  { label: t('payroll_scheduled_reports.weekdays.friday'), value: 5 },
  { label: t('payroll_scheduled_reports.weekdays.saturday'), value: 6 },
  { label: t('payroll_scheduled_reports.weekdays.sunday'), value: 7 },
])

const reportKeyItems = computed(() =>
  Object.entries(props.reportKeys).map(([key, label]) => ({ key, label }))
)

const drawer       = ref(false)
const editing      = ref<any>(null)
const formRef      = ref()
const submitting   = ref(false)
const deleteDialog = ref(false)
const deleteTarget = ref<any>(null)
const deleting     = ref(false)
const snackbar     = ref({ show: false, color: 'success', text: '' })

const form = useForm({
  name: '', report_key: '', cadence: 'monthly', run_time: '07:00',
  day_of_week: 1, day_of_month: 1, format: 'pdf', recipients: '', active: true,
})

function openCreate() { editing.value = null; form.reset(); drawer.value = true }

function openEdit(item: any) {
  editing.value = item
  form.name = item.name; form.report_key = item.report_key
  form.cadence = item.cadence; form.run_time = item.run_time
  form.day_of_week = item.day_of_week ?? 1; form.day_of_month = item.day_of_month ?? 1
  form.format = item.format; form.recipients = item.recipients; form.active = item.active
  drawer.value = true
}

async function submitForm() {
  const { valid } = await formRef.value?.validate()
  if (!valid) return
  submitting.value = true
  if (editing.value) {
    form.patch(`/payroll/settings/scheduled-reports/${editing.value.id}`, {
      onSuccess: () => { drawer.value = false; notify(t('payroll_scheduled_reports.snackbar.updated')) },
      onFinish:  () => { submitting.value = false },
    })
  } else {
    form.post('/payroll/settings/scheduled-reports', {
      onSuccess: () => { drawer.value = false; notify(t('payroll_scheduled_reports.snackbar.created')) },
      onFinish:  () => { submitting.value = false },
    })
  }
}

function confirmDelete(item: any) { deleteTarget.value = item; deleteDialog.value = true }

function submitDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  form.delete(`/payroll/settings/scheduled-reports/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false; notify(t('payroll_scheduled_reports.snackbar.deleted'), 'error') },
    onFinish:  () => { deleting.value = false },
  })
}

function notify(text: string, color = 'success') { snackbar.value = { show: true, color, text } }
</script>
