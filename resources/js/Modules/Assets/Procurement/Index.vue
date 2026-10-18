<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type PR = {
  uuid: string
  request_number: string
  item_description: string
  quantity: number
  estimated_total_cost: string
  priority: { value: string; label: string; color: string }
  status: { value: string; label: string; color: string }
  requestedBy: { name: string } | null
  created_at: string
}

const props = defineProps<{
  requests: {
    data: PR[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
  stats: { draft: number; submitted: number; approved: number; ordered: number }
  filters: { status?: string; priority?: string }
}>()

const statusFilter   = ref(props.filters.status ?? '')
const priorityFilter = ref(props.filters.priority ?? '')

const statusOptions = computed(() => [
  { title: t('assets_procurement_index.table.status'), value: '' },
  { title: 'Draft', value: 'draft' },
  { title: 'Submitted', value: 'submitted' },
  { title: 'Approved', value: 'approved' },
  { title: 'Rejected', value: 'rejected' },
  { title: 'Ordered', value: 'ordered' },
  { title: 'Delivered', value: 'delivered' },
  { title: 'Cancelled', value: 'cancelled' },
])

const priorityOptions = computed(() => [
  { title: t('assets_procurement_index.table.priority'), value: '' },
  { title: t('assets_procurement_create.priorities.low'), value: 'low' },
  { title: t('assets_procurement_create.priorities.medium'), value: 'medium' },
  { title: t('assets_procurement_create.priorities.high'), value: 'high' },
  { title: t('assets_procurement_create.priorities.urgent'), value: 'urgent' },
])

function applyFilters() {
  router.get(route('assets.procurement.index'), {
    status:   statusFilter.value || undefined,
    priority: priorityFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function formatCurrency(val: string | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val))
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_procurement_index.page_title') }} </h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_procurement_index.description') }}</p>
      </div>
      <VBtn :href="route('assets.procurement.create')" color="primary" prepend-icon="tabler-plus">
        {{ t('assets_procurement_index.new_request') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_procurement_index.stats.draft') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.draft }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_procurement_index.stats.submitted') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.submitted }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_procurement_index.stats.approved') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.approved }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_procurement_index.stats.ordered') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.ordered }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4 d-flex gap-3 flex-wrap">
        <VSelect v-model="statusFilter" :items="statusOptions" item-title="title" item-value="value" label="Status" density="compact" hide-details style="max-width:160px" @update:model-value="applyFilters" />
        <VSelect v-model="priorityFilter" :items="priorityOptions" item-title="title" item-value="value" label="Priority" density="compact" hide-details style="max-width:140px" @update:model-value="applyFilters" />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_procurement_index.table.pr_number') }}</th>
            <th>{{ t('assets_procurement_index.table.description') }}</th>
            <th class="text-center">{{ t('assets_procurement_index.table.qty') }}</th>
            <th class="text-right">{{ t('assets_procurement_index.table.est_total') }}</th>
            <th>{{ t('assets_procurement_index.table.priority') }}</th>
            <th>{{ t('assets_procurement_index.table.status') }}</th>
            <th>{{ t('assets_procurement_index.table.requested_by') }}</th>
            <th>{{ t('assets_procurement_index.table.date') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in requests.data"
            :key="r.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.procurement.show', r.uuid))"
          >
            <td class="font-weight-medium text-primary">{{ r.request_number }}</td>
            <td>{{ r.item_description }}</td>
            <td class="text-center">{{ r.quantity }}</td>
            <td class="text-right">{{ formatCurrency(r.estimated_total_cost) }}</td>
            <td><VChip size="small" :color="r.priority.color">{{ r.priority.label }}</VChip></td>
            <td><VChip size="small" :color="r.status.color">{{ r.status.label }}</VChip></td>
            <td>{{ r.requestedBy?.name ?? '—' }}</td>
            <td>{{ formatDate(r.created_at) }}</td>
          </tr>
          <tr v-if="!requests.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('assets_procurement_index.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="requests.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!requests.links.prev" @click="router.visit(requests.links.prev!)">{{ t('assets_procurement_index.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_procurement_index.pagination.page') }} {{ requests.meta.current_page }} / {{ requests.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!requests.links.next" @click="router.visit(requests.links.next!)">{{ t('assets_procurement_index.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
