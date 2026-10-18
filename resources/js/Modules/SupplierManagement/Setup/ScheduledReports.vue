<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
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
  last_run_at: string | null
  next_run_at: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  reports: ScheduledReport[]
  reportKeys: Record<string, string>
}>()

// ── Computed options ──────────────────────────────────────────────────────────
const reportOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ value, title })),
)

const cadenceOptions = computed(()=> [
  { title: t('supplier_scheduled_reports.cadence.daily'), value: 'daily' },
  { title: t('supplier_scheduled_reports.cadence.weekly'), value: 'weekly' },
  { title: t('supplier_scheduled_reports.cadence.monthly'), value: 'monthly' },
])

const formatOptions =  computed(()=>[
  { title: t('supplier_scheduled_reports.formats.pdf'), value: 'pdf' },
  { title: t('supplier_scheduled_reports.formats.excel'), value: 'excel' },
  { title: t('supplier_scheduled_reports.formats.both'), value: 'both' },
])

const dayOfWeekOptions =computed(()=> [
  { title: t('supplier_scheduled_reports.days.monday'), value: 1 },
  { title: t('supplier_scheduled_reports.days.tuesday'), value: 2 },
  { title: t('supplier_scheduled_reports.days.wednesday'), value: 3 },
  { title: t('supplier_scheduled_reports.days.thursday'), value: 4 },
  { title: t('supplier_scheduled_reports.days.friday'), value: 5 },
  { title: t('supplier_scheduled_reports.days.saturday'), value: 6 },
  { title: t('supplier_scheduled_reports.days.sunday'), value: 7 },
])

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

const dialog = ref(false)
const submitting = ref(false)
const editingItem = ref<ScheduledReport | null>(null)

const firstKey = Object.keys(props.reportKeys)[0] ?? 'all'

const form = reactive({
  name: '',
  report_key: firstKey,
  cadence: 'monthly',
  run_time: '07:00',
  day_of_week: 1 as number | null,
  day_of_month: 1 as number | null,
  format: 'pdf',
  recipients: '',
  active: true,
})

// ── Methods ────────────────────────────────────────────────────────────────────
function openCreate(): void {
  editingItem.value = null
  Object.assign(form, {
    name: '',
    report_key: firstKey,
    cadence: 'monthly',
    run_time: '07:00',
    day_of_week: 1,
    day_of_month: 1,
    format: 'pdf',
    recipients: '',
    active: true,
  })
  dialog.value = true
}

function openEdit(r: ScheduledReport): void {
  editingItem.value = r
  Object.assign(form, {
    name: r.name,
    report_key: r.report_key,
    cadence: r.cadence,
    run_time: r.run_time,
    day_of_week: r.day_of_week ?? 1,
    day_of_month: r.day_of_month ?? 1,
    format: r.format,
    recipients: r.recipients,
    active: r.active,
  })
  dialog.value = true
}

function payload() {
  return {
    name: form.name,
    report_key: form.report_key,
    cadence: form.cadence,
    run_time: form.run_time,
    day_of_week: form.cadence === 'weekly' ? form.day_of_week : null,
    day_of_month: form.cadence === 'monthly' ? form.day_of_month : null,
    format: form.format,
    recipients: form.recipients,
    active: form.active,
  }
}

function submitForm(): void {
  submitting.value = true
  const opts = {
    preserveScroll: true,
    onFinish: () => { submitting.value = false },
    onSuccess: () => { dialog.value = false },
  }
  if (editingItem.value) {
    router.patch(
      route('suppliers.scheduled-reports.update', { scheduledReport: editingItem.value.id }),
      payload(),
      opts
    )
  } else {
    router.post(route('suppliers.scheduled-reports.store'), payload(), opts)
  }
}

function deleteReport(r: ScheduledReport): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_scheduled_reports.delete.title'),
      message: t('supplier_scheduled_reports.delete.message', { name: r.name }),
      confirmText: t('supplier_scheduled_reports.delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(
        route('suppliers.scheduled-reports.destroy', { scheduledReport: r.id }),
        { preserveScroll: true }
      )
    }
  )
}

function reportLabel(key: string): string {
  return props.reportKeys[key] ?? key
}

function cadenceLabel(r: ScheduledReport): string {
  if (r.cadence === 'weekly') {
    const d = dayOfWeekOptions.value.find(o => o.value === r.day_of_week)
    return t('supplier_scheduled_reports.weekly_label', { day: d?.title ?? '—' })
  }
  if (r.cadence === 'monthly') {
    return t('supplier_scheduled_reports.monthly_label', { day: r.day_of_month ?? 1 })
  }
  return t('supplier_scheduled_reports.cadence.daily')
}

function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleString('en-ZA', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function goBack(): void {
  router.visit(route('suppliers.reports'))
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="goBack" />
        <div>
          <h1 class="text-h4">{{ t('supplier_scheduled_reports.page_title') }}</h1>
          <p class="text-medium-emphasis mb-0">{{ t('supplier_scheduled_reports.page_description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('supplier_scheduled_reports.new_schedule') }}
      </VBtn>
    </div>

    <VCard elevation="0" border>
      <VList v-if="reports.length">
        <VListItem
          v-for="r in reports"
          :key="r.id"
          :subtitle="`${cadenceLabel(r)} · ${r.run_time} · ${r.format.toUpperCase()} · ${t('supplier_scheduled_reports.active')}: ${formatDate(r.next_run_at)}`"
        >
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ r.name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ reportLabel(r.report_key) }}</span>
          </template>
          <template #prepend>
            <VAvatar :color="r.active ? 'primary' : 'secondary'" variant="tonal" size="40" rounded class="me-3">
              <VIcon icon="tabler-calendar-repeat" size="20" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="r.active ? 'success' : 'secondary'" size="x-small" variant="tonal">
                {{ r.active ? t('supplier_scheduled_reports.active') : t('supplier_scheduled_reports.paused') }}
              </VChip>
              <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEdit(r)" />
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteReport(r)" />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-calendar-repeat" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">{{ t('supplier_scheduled_reports.no_reports') }}</p>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('supplier_scheduled_reports.create_first') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Create/Edit drawer -->
    <VNavigationDrawer v-model="dialog" temporary location="end" width="460">
      <div class="pa-4 d-flex align-center justify-space-between">
        <h5 class="text-h5">
          {{ editingItem ? t('supplier_scheduled_reports.dialog.title_edit') : t('supplier_scheduled_reports.dialog.title_new') }}
        </h5>
        <VBtn icon="tabler-x" size="small" variant="text" @click="dialog = false" />
      </div>
      <VDivider />
      <div class="pa-4">
        <VRow dense>
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('supplier_scheduled_reports.dialog.name_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="form.report_key"
              :items="reportOptions"
              item-title="title"
              item-value="value"
              :label="t('supplier_scheduled_reports.dialog.report_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.cadence"
              :items="cadenceOptions"
              item-title="title"
              item-value="value"
              :label="t('supplier_scheduled_reports.dialog.cadence_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.run_time"
              :label="t('supplier_scheduled_reports.dialog.run_time_label')"
              :placeholder="t('supplier_scheduled_reports.dialog.run_time_placeholder')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol v-if="form.cadence === 'weekly'" cols="12">
            <VSelect
              v-model="form.day_of_week"
              :items="dayOfWeekOptions"
              item-title="title"
              item-value="value"
              :label="t('supplier_scheduled_reports.dialog.day_of_week_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol v-if="form.cadence === 'monthly'" cols="12">
            <VTextField
              v-model.number="form.day_of_month"
              type="number"
              min="1"
              max="31"
              :label="t('supplier_scheduled_reports.dialog.day_of_month_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSelect
              v-model="form.format"
              :items="formatOptions"
              item-title="title"
              item-value="value"
              :label="t('supplier_scheduled_reports.dialog.format_label')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" class="d-flex align-center">
            <VSwitch
              v-model="form.active"
              :label="t('supplier_scheduled_reports.dialog.active_label')"
              hide-details
              density="compact"
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="form.recipients"
              :label="t('supplier_scheduled_reports.dialog.recipients_label')"
              rows="2"
              variant="outlined"
              density="compact"
            />
          </VCol>
        </VRow>
      </div>
      <VDivider />
      <div class="pa-4 d-flex justify-end gap-2">
        <VBtn variant="text" @click="dialog = false">{{ t('supplier_scheduled_reports.dialog.cancel') }}</VBtn>
        <VBtn
          color="primary"
          variant="flat"
          :loading="submitting"
          :disabled="!form.name || !form.recipients"
          @click="submitForm"
        >
          {{ editingItem ? t('supplier_scheduled_reports.dialog.save') : t('supplier_scheduled_reports.dialog.create') }}
        </VBtn>
      </div>
    </VNavigationDrawer>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
