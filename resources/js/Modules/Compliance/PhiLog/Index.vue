<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Agent = { id: number; name: string }

type LogEntry = {
  id: number
  subject_type: string
  subject_id: number
  field: string
  accessed_by: string
  ip_address: string | null
  user_agent: string | null
  accessed_at: string
}

type Paginator = {
  data: LogEntry[]
  current_page: number
  last_page: number
  total: number
}

const props = defineProps<{
  logs: Paginator
  filters: { accessed_by?: string | number; subject_type?: string; from?: string; to?: string }
  agents: Agent[]
}>()

const filterUser        = ref<number | null>(props.filters.accessed_by ? Number(props.filters.accessed_by) : null)
const filterSubjectType = ref(props.filters.subject_type ?? '')
const filterFrom        = ref(props.filters.from ?? '')
const filterTo          = ref(props.filters.to   ?? '')

function applyFilters() {
  router.get(route('compliance.phi-log.index'), {
    accessed_by:  filterUser.value        || undefined,
    subject_type: filterSubjectType.value || undefined,
    from:         filterFrom.value        || undefined,
    to:           filterTo.value          || undefined,
  }, { preserveState: true, replace: true })
}

function clearFilters() {
  filterUser.value        = null
  filterSubjectType.value = ''
  filterFrom.value        = ''
  filterTo.value          = ''
  applyFilters()
}

function fmt(iso: string) {
  return new Date(iso).toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('compliance_phi_log.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">
          {{ t('compliance_phi_log.subtitle') }}
          <span v-if="logs.total > 0" class="ms-1">{{ t('compliance_phi_log.entries_count', { count: logs.total.toLocaleString() }) }}</span>
        </p>
      </div>
    </div>

    <VCard class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 pa-4 align-end">
        <VSelect
          v-model="filterUser"
          :items="agents"
          item-title="name"
          item-value="id"
          :label="t('compliance_phi_log.filters.user')"
          density="compact"
          clearable
          style="min-width: 180px; max-width: 240px"
        />
        <VTextField
          v-model="filterSubjectType"
          :label="t('compliance_phi_log.filters.subject_type')"
          density="compact"
          :placeholder="t('compliance_phi_log.filters.subject_type_placeholder')"
          style="min-width: 160px; max-width: 200px"
        />
        <VTextField v-model="filterFrom" :label="t('compliance_phi_log.filters.from')" type="date" density="compact" style="min-width: 150px; max-width: 180px" />
        <VTextField v-model="filterTo" :label="t('compliance_phi_log.filters.to')" type="date" density="compact" style="min-width: 150px; max-width: 180px" />
        <VBtn color="primary" @click="applyFilters">{{ t('compliance_phi_log.filters.filter') }}</VBtn>
        <VBtn variant="text" @click="clearFilters">{{ t('compliance_phi_log.filters.clear') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard>
      <VCardText class="pa-0">
        <VTable density="comfortable" class="text-no-wrap">
          <thead>
            <tr>
              <th>{{ t('compliance_phi_log.table.number') }}</th>
              <th>{{ t('compliance_phi_log.table.subject') }}</th>
              <th>{{ t('compliance_phi_log.table.field') }}</th>
              <th>{{ t('compliance_phi_log.table.accessed_by') }}</th>
              <th>{{ t('compliance_phi_log.table.ip_address') }}</th>
              <th>{{ t('compliance_phi_log.table.when') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="logs.data.length === 0">
              <td colspan="6" class="text-center text-medium-emphasis pa-6">{{ t('compliance_phi_log.table.no_entries') }}</td>
            </tr>
            <tr v-for="entry in logs.data" :key="entry.id">
              <td class="text-caption text-medium-emphasis">{{ entry.id }}</td>
              <td class="py-2">
                <div class="text-body-2 font-weight-medium">{{ entry.subject_type }}</div>
                <div class="text-caption text-medium-emphasis">#{{ entry.subject_id }}</div>
              </td>
              <td><code class="text-caption">{{ entry.field }}</code></td>
              <td class="text-body-2">{{ entry.accessed_by }}</td>
              <td class="text-caption text-medium-emphasis">{{ entry.ip_address ?? '—' }}</td>
              <td class="text-caption text-medium-emphasis">{{ fmt(entry.accessed_at) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>

      <VDivider v-if="logs.last_page > 1" />
      <VCardText v-if="logs.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination
          :model-value="logs.current_page"
          :length="logs.last_page"
          @update:model-value="page => router.get(route('compliance.phi-log.index'), { ...props.filters, page }, { preserveState: true })"
        />
      </VCardText>
    </VCard>
  </VContainer>
</template>
