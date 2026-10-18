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
  next_run: string | null
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
const snack        = ref({ show: false, text: '', color: 'success' })

const isEdit      = computed(() => !!editTarget.value)
const drawerTitle = computed(() => isEdit.value ? t('fleet_scheduled_reports_index.drawer.title_edit') : t('fleet_scheduled_reports_index.drawer.title_create'))

const emptyForm = () => ({
  name:         '',
  report_key:   '',
  cadence:      'weekly' as 'daily' | 'weekly' | 'monthly',
  run_time:     '07:00',
  day_of_week:  null as number | null,
  day_of_month: null as number | null,
  format:       'pdf' as 'pdf' | 'excel' | 'both',
  recipients:   '',
  active:       true,
})

const form = useForm(emptyForm())

const openCreate = () => {
  editTarget.value = null
  Object.assign(form, emptyForm())
  drawer.value = true
}

const openEdit = (r: ScheduledReport) => {
  editTarget.value  = r
  form.name         = r.name
  form.report_key   = r.report_key
  form.cadence      = r.cadence as any
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
    form.patch(`/fleet/reports/scheduled/${editTarget.value.id}`, {
      onSuccess: () => { drawer.value = false; toast(t('fleet_scheduled_reports_index.toasts.updated')) },
    })
  } else {
    form.post('/fleet/reports/scheduled', {
      onSuccess: () => { drawer.value = false; toast(t('fleet_scheduled_reports_index.toasts.created')) },
    })
  }
}

const confirmDelete = (r: ScheduledReport) => {
  deleteTarget.value = r
  deleteDialog.value = true
}

const doDelete = () => {
  if (!deleteTarget.value) return
  router.delete(`/fleet/reports/scheduled/${deleteTarget.value.id}`, {
    onSuccess: () => { deleteDialog.value = false; toast(t('fleet_scheduled_reports_index.toasts.deleted'), 'error') },
  })
}

const toast = (text: string, color = 'success') => {
  snack.value = { show: true, text, color }
}

const cadenceOptions = computed(() => [
  { title: t('fleet_scheduled_reports_index.cadences.daily'),    value: 'daily'   },
  { title: t('fleet_scheduled_reports_index.cadences.weekly'),   value: 'weekly'  },
  { title: t('fleet_scheduled_reports_index.cadences.monthly'),  value: 'monthly' },
])

const formatOptions = computed(() => [
  { title: t('fleet_scheduled_reports_index.formats.pdf'),         value: 'pdf'   },
  { title: t('fleet_scheduled_reports_index.formats.excel'),       value: 'excel' },
  { title: t('fleet_scheduled_reports_index.formats.both'),        value: 'both'  },
])

const dayOfWeekOptions = computed(() => [
  { title: t('fleet_scheduled_reports_index.days.monday'),    value: 1 },
  { title: t('fleet_scheduled_reports_index.days.tuesday'),   value: 2 },
  { title: t('fleet_scheduled_reports_index.days.wednesday'), value: 3 },
  { title: t('fleet_scheduled_reports_index.days.thursday'),  value: 4 },
  { title: t('fleet_scheduled_reports_index.days.friday'),    value: 5 },
  { title: t('fleet_scheduled_reports_index.days.saturday'),  value: 6 },
  { title: t('fleet_scheduled_reports_index.days.sunday'),    value: 7 },
])

const reportKeyOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ title, value }))
)

const tableHeaders = computed(() => [
  { title: t('fleet_scheduled_reports_index.table.name'),       key: 'name',       sortable: true  },
  { title: t('fleet_scheduled_reports_index.table.report'),     key: 'report_key', sortable: true  },
  { title: t('fleet_scheduled_reports_index.table.cadence'),    key: 'cadence',    sortable: true  },
  { title: t('fleet_scheduled_reports_index.table.format'),     key: 'format',     sortable: false },
  { title: t('fleet_scheduled_reports_index.table.recipients'), key: 'recipients', sortable: false },
  { title: t('fleet_scheduled_reports_index.table.next_run'),   key: 'next_run',   sortable: false },
  { title: t('fleet_scheduled_reports_index.table.last_run'),   key: 'last_run_at',sortable: false },
  { title: t('fleet_scheduled_reports_index.table.status'),     key: 'active',     sortable: true  },
  { title: t('fleet_scheduled_reports_index.table.actions'),    key: 'actions',    sortable: false },
])

const cadenceColor = (c: string) =>
  ({ daily: 'info', weekly: 'warning', monthly: 'success' }[c.toLowerCase()] ?? 'default')

const formatColor = (f: string) =>
  ({ pdf: 'red-darken-1', excel: 'green-darken-1', both: 'blue-darken-1' }[f] ?? 'default')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t('fleet_scheduled_reports_index.page_title') }}</div>
            <h4 class="text-h4">{{ t('fleet_scheduled_reports_index.page_title') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ t('fleet_scheduled_reports_index.description') }}</p>
          </div>
          <div class="d-flex gap-2">
            <VBtn variant="tonal" prepend-icon="tabler-arrow-left" href="/fleet/reports">
              {{ t('fleet_scheduled_reports_index.back_to_reports') }}
            </VBtn>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
              {{ t('fleet_scheduled_reports_index.new_schedule') }}
            </VBtn>
          </div>
        </div>

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

            <template #item.format="{ item }">
              <VChip :color="formatColor(item.format)" size="small" label>
                {{ item.format.toUpperCase() }}
              </VChip>
            </template>

            <template #item.recipients="{ item }">
              <span class="text-caption" :title="item.recipients">
                {{ item.recipients.length > 40 ? item.recipients.slice(0, 40) + '…' : item.recipients }}
              </span>
            </template>

            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'default'" size="small" label>
                {{ item.active ? t('fleet_scheduled_reports_index.table.active') : t('fleet_scheduled_reports_index.table.paused') }}
              </VChip>
            </template>

            <template #item.actions="{ item }">
              <VBtn icon size="small" variant="text" @click="openEdit(item)">
                <VIcon icon="tabler-pencil" />
              </VBtn>
              <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)">
                <VIcon icon="tabler-trash" />
              </VBtn>
            </template>

            <template #no-data>
              <div class="pa-8 text-center text-medium-emphasis">
                <VIcon icon="tabler-calendar-clock" size="48" class="mb-3 d-block mx-auto" />
                {{ t('fleet_scheduled_reports_index.empty.message') }}
              </div>
            </template>
          </VDataTable>
        </VCard>

      </VCol>
    </VRow>
  </VContainer>

  <VNavigationDrawer v-model="drawer" temporary location="right" width="480">
    <VForm @submit.prevent="submitForm" class="h-100 d-flex flex-column">

      <div class="pa-5 border-b">
        <h5 class="text-h5">{{ drawerTitle }}</h5>
      </div>

      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('fleet_scheduled_reports_index.drawer.schedule_name')"
              :placeholder="t('fleet_scheduled_reports_index.drawer.schedule_name_placeholder')"
              :error-messages="form.errors.name"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VSelect
              v-model="form.report_key"
              :items="reportKeyOptions"
              :label="t('fleet_scheduled_reports_index.drawer.report_type')"
              :error-messages="form.errors.report_key"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.cadence"
              :items="cadenceOptions"
              :label="t('fleet_scheduled_reports_index.drawer.cadence')"
              :error-messages="form.errors.cadence"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.run_time"
              :label="t('fleet_scheduled_reports_index.drawer.run_time')"
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
              :label="t('fleet_scheduled_reports_index.drawer.day_of_week')"
              :error-messages="form.errors.day_of_week"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol v-if="form.cadence === 'monthly'" cols="12">
            <VTextField
              v-model="form.day_of_month"
              :label="t('fleet_scheduled_reports_index.drawer.day_of_month')"
              type="number"
              :min="1"
              :max="28"
              :error-messages="form.errors.day_of_month"
              variant="outlined"
              density="comfortable"
              :hint="t('fleet_scheduled_reports_index.drawer.day_of_month_hint')"
              persistent-hint
            />
          </VCol>

          <VCol cols="12">
            <VSelect
              v-model="form.format"
              :items="formatOptions"
              :label="t('fleet_scheduled_reports_index.drawer.output_format')"
              :error-messages="form.errors.format"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VTextField
              v-model="form.recipients"
              :label="t('fleet_scheduled_reports_index.drawer.recipients')"
              :placeholder="t('fleet_scheduled_reports_index.drawer.recipients_placeholder')"
              :error-messages="form.errors.recipients"
              variant="outlined"
              density="comfortable"
              :hint="t('fleet_scheduled_reports_index.drawer.recipients_hint')"
              persistent-hint
            />
          </VCol>

          <VCol cols="12">
            <VSwitch
              v-model="form.active"
              :label="t('fleet_scheduled_reports_index.drawer.active')"
              color="primary"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
      </div>

      <div class="pa-5 border-t d-flex gap-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('fleet_scheduled_reports_index.drawer.cancel') }}</VBtn>
        <VBtn
          type="submit"
          color="primary"
          :loading="form.processing"
          prepend-icon="ri-save-line"
        >
          {{ isEdit ? t('fleet_scheduled_reports_index.drawer.save_edit') : t('fleet_scheduled_reports_index.drawer.save_create') }}
        </VBtn>
      </div>

    </VForm>
  </VNavigationDrawer>

  <VDialog v-model="deleteDialog" max-width="420">
    <VCard>
      <VCardTitle class="pt-5 px-5">{{ t('fleet_scheduled_reports_index.delete_dialog.title') }}</VCardTitle>
      <VCardText class="px-5">
        {{ t('fleet_scheduled_reports_index.delete_dialog.message', { name: deleteTarget?.name }) }}
      </VCardText>
      <VCardActions class="pa-5 pt-0 justify-end">
        <VBtn variant="tonal" @click="deleteDialog = false">{{ t('fleet_scheduled_reports_index.delete_dialog.cancel') }}</VBtn>
        <VBtn color="error" @click="doDelete">{{ t('fleet_scheduled_reports_index.delete_dialog.confirm') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">
    {{ snack.text }}
  </VSnackbar>
</template>
