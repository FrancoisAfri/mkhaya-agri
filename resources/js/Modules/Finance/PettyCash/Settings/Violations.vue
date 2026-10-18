<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Violation {
  id: number; uuid: string; type: string; description: string; severity: string
  policy_rule: string | null; status: string; reference: string | null; float_name: string | null
  resolved_by: string | null; detected_at: string; resolved_at: string | null
}

const props = defineProps<{
  violations: { data: Violation[]; links: any; meta: any }
  filters: { status?: string; severity?: string }
}>()

const statusFilter   = ref(props.filters.status ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const apply    = () => router.get(route('petty-cash.settings.violations'), { status: statusFilter.value, severity: severityFilter.value }, { preserveState: true, replace: true })

const resolveDialog = ref(false)
const selected      = ref<Violation | null>(null)
const resolveForm   = useForm({ resolution_notes: '' })

const openResolve = (violation: Violation) => { selected.value = violation; resolveDialog.value = true }
const resolve = () => {
  if (!selected.value) return
  resolveForm.post(route('petty-cash.settings.violations.resolve', selected.value.id), {
    onSuccess: () => { resolveDialog.value = false; resolveForm.reset() },
  })
}

const severityColor = (s: string) => ({ low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error' })[s] ?? 'grey'
const statusColor   = (s: string) => ({ open: 'error', resolved: 'success', escalated: 'warning', waived: 'grey' })[s] ?? 'grey'

const headers = computed(()=> [
  { title: t('petty_cash_violations.table.severity'),     key: 'severity' },
  { title: t('petty_cash_violations.table.type'),         key: 'type' },
  { title: t('petty_cash_violations.table.description'),  key: 'description' },
  { title: t('petty_cash_violations.table.reference'),    key: 'reference' },
  { title: t('petty_cash_violations.table.float'),        key: 'float_name' },
  { title: t('petty_cash_violations.table.status'),       key: 'status' },
  { title: t('petty_cash_violations.table.detected'),     key: 'detected_at' },
  { title: '',                                             key: 'actions', sortable: false },
])

const statusItems =  computed(()=> [
  { title: t('petty_cash_violations.filters.statuses.all'), value: '' },
  { title: t('petty_cash_violations.filters.statuses.open'), value: 'open' },
  { title: t('petty_cash_violations.filters.statuses.resolved'), value: 'resolved' },
  { title: t('petty_cash_violations.filters.statuses.escalated'), value: 'escalated' },
  { title: t('petty_cash_violations.filters.statuses.waived'), value: 'waived' },
])

const severityItems =  computed(()=> [
  { title: t('petty_cash_violations.filters.severities.all'), value: '' },
  { title: t('petty_cash_violations.filters.severities.low'), value: 'low' },
  { title: t('petty_cash_violations.filters.severities.medium'), value: 'medium' },
  { title: t('petty_cash_violations.filters.severities.high'), value: 'high' },
  { title: t('petty_cash_violations.filters.severities.critical'), value: 'critical' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_violations.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_violations.description') }}</p>
      </div>
      <VBtn variant="outlined" :href="route('petty-cash.settings.index')">{{ t('petty_cash_violations.back_to_settings') }}</VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap pa-3">
        <VSelect
          v-model="statusFilter"
          :items="statusItems"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
          :label="t('petty_cash_violations.filters.status_label')"
          @update:model-value="apply"
        />
        <VSelect
          v-model="severityFilter"
          :items="severityItems"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
          :label="t('petty_cash_violations.filters.severity_label')"
          @update:model-value="apply"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="violations.data" :items-per-page="-1" hide-default-footer>
        <template #item.severity="{ item }">
          <VChip :color="severityColor(item.severity)" size="x-small" variant="flat">{{ item.severity }}</VChip>
        </template>
        <template #item.type="{ item }">
          <span class="text-body-2 text-capitalize">{{ item.type.replace(/_/g, ' ') }}</span>
        </template>
        <template #item.description="{ item }">
          <span class="text-body-2" style="max-width:320px;display:block;white-space:normal">{{ item.description }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.detected_at="{ item }">{{ item.detected_at.substring(0, 10) }}</template>
        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="small" variant="tonal" color="success" @click="openResolve(item)">
            {{ t('petty_cash_violations.resolve') }}
          </VBtn>
        </template>
      </VDataTable>
    </VCard>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolveDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('petty_cash_violations.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <div class="text-body-2 mb-3">{{ selected?.description }}</div>
          <VTextarea
            v-model="resolveForm.resolution_notes"
            :label="t('petty_cash_violations.dialog.resolution_notes')"
            variant="outlined"
            rows="3"
            density="comfortable"
            :error-messages="resolveForm.errors.resolution_notes"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('petty_cash_violations.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="resolve">
            {{ t('petty_cash_violations.dialog.mark_resolved') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
