<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Correction {
  id: number; uuid: string; run_id: number
  correction_type: string; description: string; suggested_action: string
  field_affected: string | null
  original_value: number | null; corrected_value: number | null
  status: string; suggested_by: string
  employee_id: number | null; employee_name: string | null
  rejection_reason: string | null
  metadata: Record<string, any> | null
  payroll_run: { uuid: string; ref_number: string; name: string } | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  corrections:     Page<Correction>
  filters:         Record<string, string>
  stats:           { total_suggested: number; total_approved: number; total_applied: number; total_rejected: number }
  runs:            Run[]
  correctionTypes: string[]
}>()

const statusFilter = ref(props.filters.status ?? '')
const typeFilter   = ref(props.filters.type   ?? '')
const runIdFilter  = ref(props.filters.run_id ?? '')

watch([statusFilter, typeFilter, runIdFilter], () => {
  router.get(route('payroll.corrections.index'), {
    status: statusFilter.value, type: typeFilter.value, run_id: runIdFilter.value,
  }, { preserveState: true, replace: true })
})

const suggestDialog   = ref(false)
const selectedRun     = ref<Run | null>(null)
const suggestForm     = useForm({})

function doSuggest() {
  if (!selectedRun.value) return
  suggestForm.post(route('payroll.corrections.suggest', selectedRun.value.uuid), {
    onSuccess: () => { suggestDialog.value = false },
  })
}

const selected      = ref<Correction | null>(null)
const approveForm   = useForm({})
const rejectDialog  = ref(false)
const rejectForm    = useForm({ reason: '' })

function doApprove(correction: Correction) {
  approveForm.post(route('payroll.corrections.approve', correction.id))
}

function openReject(correction: Correction) { selected.value = correction; rejectForm.reset(); rejectDialog.value = true }
function doReject() {
  if (!selected.value) return
  rejectForm.post(route('payroll.corrections.reject', selected.value.id), {
    onSuccess: () => { rejectDialog.value = false },
  })
}

const applyForm = useForm({})
function doApply(correction: Correction) {
  applyForm.post(route('payroll.corrections.apply', correction.id))
}

const statusColor = (s: string) => ({
  suggested: 'info', approved: 'warning', applied: 'success', rejected: 'error',
}[s] ?? 'grey')

const typeColor = (t: string) => ({
  duplicate_payment: 'purple',
  allowance_error:   'orange',
  leave_error:       'warning',
  tax_error:         'deep-orange',
  other:             'grey',
}[t] ?? 'grey')

const typeIcon = (template: string) => ({
  duplicate_payment: 'tabler-copy',
  allowance_error:   'tabler-cash',
  leave_error:       'tabler-umbrella-beach',
  tax_error:         'tabler-receipt',
  other:             'tabler-alert-circle',
}[template] ?? 'tabler-alert-triangle')

const typeLabel = (template: string) => t(`payroll_corrections.correction_types.${template}`)

const fmt = (n: number | null) => n != null
  ? 'R ' + new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2 }).format(n)
  : '—'

const statusItems = computed(() => [
  { title: t('payroll_corrections.filters.all'), value: '' },
  { title: t('payroll_corrections.filters.statuses.suggested'), value: 'suggested' },
  { title: t('payroll_corrections.filters.statuses.approved'), value: 'approved' },
  { title: t('payroll_corrections.filters.statuses.applied'), value: 'applied' },
  { title: t('payroll_corrections.filters.statuses.rejected'), value: 'rejected' },
])

const typeItems = computed(() => [
  { title: t('payroll_corrections.filters.all_types'), value: '' },
  ...props.correctionTypes.map(ct => ({ title: typeLabel(ct), value: ct })),
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_corrections.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_corrections.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_corrections.description', { total: corrections.total }) }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-robot" @click="suggestDialog = true">
        {{ t('payroll_corrections.suggest_corrections') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="info" size="22" class="mb-1">tabler-bulb</VIcon>
            <div class="text-h5 font-weight-bold text-info">{{ stats.total_suggested }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_corrections.stats.suggested') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-rosette-discount-check</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_approved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_corrections.stats.approved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_applied }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_corrections.stats.applied') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-circle-x</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_rejected }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_corrections.stats.rejected') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <span class="text-caption text-medium-emphasis font-weight-medium">{{ t('payroll_corrections.correction_types_label') }}</span>
        <VChip v-for="ct in correctionTypes" :key="ct" size="x-small" variant="tonal" :color="typeColor(ct)">
          <VIcon start size="12">{{ typeIcon(ct) }}</VIcon>
          {{ typeLabel(ct) }}
        </VChip>
      </div>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="4">
            <VSelect v-model="statusFilter" :label="t('payroll_corrections.filters.status')"
              :items="statusItems"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="typeFilter" :label="t('payroll_corrections.filters.type')"
              :items="typeItems"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="runIdFilter" :label="t('payroll_corrections.filters.run')"
              :items="runItems"
              item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="corrections.data"
        :headers="[
          { title: t('payroll_corrections.table.icon'),            key: 'icon',            sortable: false, width: '40px' },
          { title: t('payroll_corrections.table.correction'),  key: 'correction_type', sortable: false },
          { title: t('payroll_corrections.table.employee'),    key: 'employee_name',   sortable: false },
          { title: t('payroll_corrections.table.run'),         key: 'payroll_run',     sortable: false },
          { title: t('payroll_corrections.table.original'),    key: 'original_value',  sortable: false },
          { title: t('payroll_corrections.table.suggested'),   key: 'corrected_value', sortable: false },
          { title: t('payroll_corrections.table.status'),      key: 'status',          sortable: false },
          { title: t('payroll_corrections.table.actions'),     key: 'actions',         sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="typeColor(item.correction_type)">
            {{ typeIcon(item.correction_type) }}
          </VIcon>
        </template>

        <template #item.correction_type="{ item }">
          <div>
            <div class="d-flex align-center ga-1">
              <VChip size="x-small" :color="typeColor(item.correction_type)" variant="tonal">
                {{ typeLabel(item.correction_type) }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis mt-1" style="max-width:360px; white-space:normal;">
              {{ item.description?.substring(0, 100) }}{{ (item.description?.length ?? 0) > 100 ? '…' : '' }}
            </div>
            <div v-if="item.suggested_action" class="text-caption" style="max-width:360px; white-space:normal; font-style:italic; color: #6366f1;">
              → {{ item.suggested_action?.substring(0, 90) }}{{ (item.suggested_action?.length ?? 0) > 90 ? '…' : '' }}
            </div>
          </div>
        </template>

        <template #item.employee_name="{ item }">
          <span class="text-body-2">{{ item.employee_name ?? '—' }}</span>
        </template>

        <template #item.payroll_run="{ item }">
          <span v-if="item.payroll_run" class="text-caption font-weight-medium">{{ item.payroll_run.ref_number }}</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.original_value="{ item }">
          <span class="text-caption text-error">{{ fmt(item.original_value) }}</span>
        </template>

        <template #item.corrected_value="{ item }">
          <span class="text-caption text-success font-weight-medium">{{ fmt(item.corrected_value) }}</span>
        </template>

        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <template v-if="item.status === 'suggested'">
              <VBtn size="x-small" color="success" variant="tonal" prepend-icon="tabler-check"
                :loading="approveForm.processing" @click="doApprove(item)">{{ t('payroll_corrections.actions.approve') }}</VBtn>
              <VBtn size="x-small" color="error" variant="text" icon="tabler-x" aria-label="Close" class="row-action"
                @click="openReject(item)" />
            </template>
            <template v-else-if="item.status === 'approved'">
              <VBtn size="x-small" color="primary" variant="tonal" prepend-icon="tabler-player-play"
                :loading="applyForm.processing" @click="doApply(item)">{{ t('payroll_corrections.actions.apply') }}</VBtn>
            </template>
            <template v-else-if="item.status === 'rejected'">
              <VTooltip :text="item.rejection_reason ?? 'No reason given'" location="left">
                <template #activator="{ props: tp }">
                  <VIcon v-bind="tp" size="16" color="error">tabler-info-circle</VIcon>
                </template>
              </VTooltip>
            </template>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="corrections.last_page > 1" />
      <div v-if="corrections.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="corrections.current_page" :length="corrections.last_page"
          density="compact"
          @update:model-value="p => router.get(route('payroll.corrections.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="suggestDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="primary">tabler-robot</VIcon>{{ t('payroll_corrections.dialog.suggest_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('payroll_corrections.dialog.suggest_info') }}
          </VAlert>
          <VSelect v-model="selectedRun" :label="t('payroll_corrections.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="suggestDialog = false">{{ t('payroll_corrections.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="suggestForm.processing" :disabled="!selectedRun" @click="doSuggest">
            {{ t('payroll_corrections.dialog.analyse_run') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="480">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="error">tabler-circle-x</VIcon>{{ t('payroll_corrections.dialog.reject_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="typeColor(selected.correction_type)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ typeLabel(selected.correction_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea v-model="rejectForm.reason" :label="t('payroll_corrections.dialog.reject_reason')"
            variant="outlined" density="compact" rows="3"
            :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('payroll_corrections.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="doReject">{{ t('payroll_corrections.dialog.reject_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
