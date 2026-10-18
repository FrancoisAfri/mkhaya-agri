<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Req = {
  id: number; request_number: string; request_title: string; status: string
  contract_value: number | null; criticality: number | null; type: string | null; created_at: string | null
}

const props = defineProps<{
  requests: { data: Req[]; links: any[] }
  filters: { search?: string; status?: string }
  statuses: string[]
}>()

const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')

let timeoutId: ReturnType<typeof setTimeout>

function applyFilters() {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(route('contracts.requests.index'), {
      search: search.value || undefined,
      status: statusFilter.value || undefined
    }, { preserveState: true, replace: true })
  }, 300)
}

const statusColor: Record<string, string> = { pending: 'warning', approved: 'success', rejected: 'error' }

const statusItems = [
  { title: '', value: '' },
  ...props.statuses.map(s => ({ title: s, value: s })),
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="info" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-clipboard-text" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_requests_index.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_requests_index.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('contracts.requests.create'))">
        {{ t('contracts_requests_index.new_request') }}
      </VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('contracts_requests_index.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          style="max-width:260px"
          @keyup.enter="applyFilters"
          clearable
          @click:clear="applyFilters"
        />
        <VSelect
          v-model="statusFilter"
          :items="statusItems"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('contracts_requests_index.filters.status_label')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">{{ t('contracts_requests_index.filters.filter') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('contracts_requests_index.table.number') }}</th>
            <th>{{ t('contracts_requests_index.table.title') }}</th>
            <th>{{ t('contracts_requests_index.table.type') }}</th>
            <th>{{ t('contracts_requests_index.table.criticality') }}</th>
            <th>{{ t('contracts_requests_index.table.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!requests.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">{{ t('contracts_requests_index.no_requests') }}</td>
          </tr>
          <tr v-for="request in requests.data" :key="request.id" style="cursor:pointer" @click="router.get(route('contracts.requests.show', request.id))">
            <td class="font-weight-medium">{{ request.request_number }}</td>
            <td>{{ request.request_title }}</td>
            <td>{{ request.type ?? '—' }}</td>
            <td>{{ request.criticality ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColor[request.status]" variant="tonal">{{ request.status }}</VChip>
            </td>
            <td><VIcon icon="tabler-chevron-right" size="16" /></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
