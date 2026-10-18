<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t, te } = useI18n()

/*
 * Translate where somebody has written a term for this value, and otherwise show the value itself.
 * The data behind these keys is open-ended, so no set of entries can ever cover it — the fallback
 * is what stops the page printing a dotted key at a reader.
 */
function label(key: string, fallback: string | null | undefined): string {
  return te(key) ? t(key) : (fallback ?? '')
}


type Agent = { id: number; name: string }

type Incident = {
  uuid: string
  title: string
  severity: string
  status: string
  detected_at: string
  notified_regulator_at: string | null
  affected_record_count: number | null
  hours_until_deadline: number
  is_overdue: boolean
  reported_by: string | null
  assigned_to: string | null
  created_at: string
}

type Paginator = {
  data: Incident[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

const props = defineProps<{
  incidents: Paginator
  filters: { status?: string; severity?: string }
  agents: Agent[]
}>()

const filterStatus   = ref(props.filters.status   ?? '')
const filterSeverity = ref(props.filters.severity ?? '')

function applyFilters() {
  router.get(route('compliance.breaches.index'), {
    status:   filterStatus.value   || undefined,
    severity: filterSeverity.value || undefined,
  }, { preserveState: true, replace: true })
}

function clearFilters() {
  filterStatus.value   = ''
  filterSeverity.value = ''
  applyFilters()
}

const showDialog = ref(false)

const form = useForm({
  title:                  '',
  description:            '',
  severity:               'medium' as string,
  detected_at:            new Date().toISOString().slice(0, 16),
  affected_record_count:  null as number | null,
  affected_subject_types: [] as string[],
  assigned_to:            null as number | null,
})

function submit() {
  form.post(route('compliance.breaches.store'), {
    onSuccess: () => { showDialog.value = false; form.reset() },
  })
}

function severityColor(s: string) {
  return { low: 'info', medium: 'warning', high: 'orange', critical: 'error' }[s] ?? 'default'
}

function statusColor(s: string) {
  return { open: 'error', notified: 'warning', contained: 'info', closed: 'success' }[s] ?? 'default'
}

function fmt(iso: string) {
  return new Date(iso).toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' })
}

const SUBJECT_TYPES = [
  'Employee',
  'Former Employee',
  'Candidate',
  'Contractor',
  'Vendor',
  'Customer',
  'Beneficiary',
  'User',
]

const statusItems = computed(() => [
  { title: t('compliance_breaches.index.filters.all_statuses'), value: '' },
  { title: t('compliance_breaches.index.statuses.open'), value: 'open' },
  { title: t('compliance_breaches.index.statuses.notified'), value: 'notified' },
  { title: t('compliance_breaches.index.statuses.contained'), value: 'contained' },
  { title: t('compliance_breaches.index.statuses.closed'), value: 'closed' },
])

const severityItems = computed(() => [
  { title: t('compliance_breaches.index.filters.all_severities'), value: '' },
  { title: t('compliance_breaches.index.severities.low'), value: 'low' },
  { title: t('compliance_breaches.index.severities.medium'), value: 'medium' },
  { title: t('compliance_breaches.index.severities.high'), value: 'high' },
  { title: t('compliance_breaches.index.severities.critical'), value: 'critical' },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('compliance_breaches.index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('compliance_breaches.index.subtitle') }}</p>
      </div>
      <VBtn color="error" prepend-icon="tabler-plus" @click="showDialog = true">
        {{ t('compliance_breaches.index.report_breach') }}
      </VBtn>
    </div>

    <VCard class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 pa-4 align-end">
        <VSelect
          v-model="filterStatus"
          :items="statusItems"
          :label="t('compliance_breaches.index.filters.status')"
          density="compact"
          clearable
          style="min-width: 140px; max-width: 180px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="filterSeverity"
          :items="severityItems"
          :label="t('compliance_breaches.index.filters.severity')"
          density="compact"
          clearable
          style="min-width: 140px; max-width: 180px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="text" @click="clearFilters">{{ t('compliance_breaches.index.filters.clear') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard>
      <VCardText class="pa-0">
        <VTable density="comfortable" class="text-no-wrap">
          <thead>
            <tr>
              <th>{{ t('compliance_breaches.index.table.title') }}</th>
              <th>{{ t('compliance_breaches.index.table.severity') }}</th>
              <th>{{ t('compliance_breaches.index.table.status') }}</th>
              <th>{{ t('compliance_breaches.index.table.deadline') }}</th>
              <th>{{ t('compliance_breaches.index.table.records') }}</th>
              <th>{{ t('compliance_breaches.index.table.assigned_to') }}</th>
              <th>{{ t('compliance_breaches.index.table.detected') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-if="incidents.data.length === 0">
              <td colspan="8" class="text-center text-medium-emphasis pa-6">{{ t('compliance_breaches.index.table.no_incidents') }}</td>
            </tr>
            <tr
              v-for="b in incidents.data"
              :key="b.uuid"
              class="cursor-pointer"
              @click="router.visit(route('compliance.breaches.show', b.uuid))"
            >
              <td class="py-2">
                <div class="font-weight-medium">{{ b.title }}</div>
                <div v-if="b.reported_by" class="text-caption text-medium-emphasis">
                  {{ t('compliance_breaches.index.table.reported_by', { name: b.reported_by }) }}
                </div>
              </td>
              <td>
                <VChip :color="severityColor(b.severity)" size="x-small" label class="text-capitalize">
                  {{ t(`compliance_breaches.index.severities.${b.severity}`) }}
                </VChip>
              </td>
              <td>
                <VChip :color="statusColor(b.status)" size="x-small" label class="text-capitalize">
                  {{ t(`compliance_breaches.index.statuses.${b.status}`) }}
                </VChip>
              </td>
              <td>
                <VChip
                  v-if="b.status === 'open'"
                  :color="b.is_overdue ? 'error' : b.hours_until_deadline < 12 ? 'warning' : 'success'"
                  size="x-small"
                  label
                >
                  {{ b.is_overdue ? t('compliance_breaches.index.table.overdue') : t('compliance_breaches.index.table.hours_left', { hours: Math.round(b.hours_until_deadline) }) }}
                </VChip>
                <VChip v-else color="success" size="x-small" label>{{ t('compliance_breaches.index.table.notified') }}</VChip>
              </td>
              <td class="text-medium-emphasis">
                {{ b.affected_record_count?.toLocaleString() ?? '—' }}
              </td>
              <td class="text-medium-emphasis">{{ b.assigned_to ?? '—' }}</td>
              <td class="text-caption text-medium-emphasis">{{ fmt(b.detected_at) }}</td>
              <td>
                <VBtn icon="tabler-chevron-right" size="small" variant="text" @click.stop="router.visit(route('compliance.breaches.show', b.uuid))" />
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>

      <VDivider v-if="incidents.last_page > 1" />
      <VCardText v-if="incidents.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination
          :model-value="incidents.current_page"
          :length="incidents.last_page"
          @update:model-value="page => router.get(route('compliance.breaches.index'), { ...props.filters, page }, { preserveState: true })"
        />
      </VCardText>
    </VCard>

    <VDialog v-model="showDialog" max-width="640" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1 d-flex align-center justify-space-between">
          {{ t('compliance_breaches.index.dialog.title') }}
          <VBtn icon="tabler-x" variant="text" size="small" @click="showDialog = false" />
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12">
              <VTextField v-model="form.title" :label="t('compliance_breaches.index.dialog.title_label')" :error-messages="form.errors.title" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" :label="t('compliance_breaches.index.dialog.description_label')" :error-messages="form.errors.description" density="compact" rows="3" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.severity" :items="severityItems" :label="t('compliance_breaches.index.dialog.severity_label')" :error-messages="form.errors.severity" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.detected_at" :label="t('compliance_breaches.index.dialog.detected_label')" type="datetime-local" :error-messages="form.errors.detected_at" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="form.affected_record_count" :label="t('compliance_breaches.index.dialog.records_label')" type="number" min="0" :error-messages="form.errors.affected_record_count" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.assigned_to" :items="agents" item-title="name" item-value="id" :label="t('compliance_breaches.index.dialog.assign_label')" :error-messages="form.errors.assigned_to" density="compact" clearable />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="form.affected_subject_types" :items="SUBJECT_TYPES" :label="t('compliance_breaches.index.dialog.subject_types_label')" :error-messages="form.errors.affected_subject_types" density="compact" multiple chips closable-chips>
                <template #selection="{ item }">
                  <VChip size="small">{{ label(`compliance_breaches.index.subject_types.${item.raw}`, item.raw) }}</VChip>
                </template>
              </VSelect>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-3">
          <VSpacer />
          <VBtn variant="tonal" @click="showDialog = false">{{ t('compliance_breaches.index.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="form.processing" @click="submit">
            {{ t('compliance_breaches.index.dialog.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
