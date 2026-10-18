<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Chip = { value: string; label: string; color: string }

type Order = {
  uuid: string
  work_order_number: string
  title: string
  type: string
  priority: Chip
  status: Chip
  asset: { uuid: string; name: string; asset_number: string } | null
  assignee_name: string | null
  scheduled_for: string | null
  total_cost: string
  created_at: string | null
}

const props = defineProps<{
  orders: {
    data: Order[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
  stats: { open: number; in_progress: number; on_hold: number; completed: number }
  filters: { status?: string; priority?: string; asset?: string; search?: string }
  options: {
    priorities: Chip[]
    statuses: Chip[]
    types: { value: string; label: string }[]
  }
}>()

const statusFilter = ref(props.filters.status ?? '')
const priorityFilter = ref(props.filters.priority ?? '')
const search = ref(props.filters.search ?? '')

const statusOptions = computed(() => [
  { title: 'All statuses', value: '' },
  ...props.options.statuses.map(s => ({ title: s.label, value: s.value })),
])

const priorityOptions = computed(() => [
  { title: 'All priorities', value: '' },
  ...props.options.priorities.map(p => ({ title: p.label, value: p.value })),
])

const statCards = computed(() => [
  { label: 'Open', value: props.stats.open, color: 'primary' },
  { label: 'In Progress', value: props.stats.in_progress, color: 'info' },
  { label: 'On Hold', value: props.stats.on_hold, color: 'warning' },
  { label: 'Completed', value: props.stats.completed, color: 'success' },
])

function applyFilters() {
  router.get(route('assets.work-orders.index'), {
    status: statusFilter.value || undefined,
    priority: priorityFilter.value || undefined,
    search: search.value || undefined,
  }, { preserveState: true, replace: true })
}

function formatCurrency(val: string | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 2 }).format(Number(val))
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">Work Orders</h4>
        <p class="text-medium-emphasis mb-0">Plan, execute and cost asset maintenance work.</p>
      </div>
      <VBtn :href="route('assets.work-orders.create')" color="primary" prepend-icon="tabler-plus">
        New Work Order
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol v-for="c in statCards" :key="c.label" cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ c.label }}</p>
            <p class="text-h5 font-weight-bold mb-0" :class="`text-${c.color}`">{{ c.value }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4 d-flex gap-3 flex-wrap align-center">
        <VTextField
          v-model="search"
          label="Search number or title"
          density="compact"
          hide-details
          clearable
          style="max-width:260px"
          prepend-inner-icon="tabler-search"
          @keyup.enter="applyFilters"
          @click:clear="applyFilters"
        />
        <VSelect v-model="statusFilter" :items="statusOptions" item-title="title" item-value="value" label="Status" density="compact" hide-details style="max-width:180px" @update:model-value="applyFilters" />
        <VSelect v-model="priorityFilter" :items="priorityOptions" item-title="title" item-value="value" label="Priority" density="compact" hide-details style="max-width:180px" @update:model-value="applyFilters" />
        <VBtn variant="tonal" size="small" @click="applyFilters">Apply</VBtn>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>WO #</th>
            <th>Title</th>
            <th>Asset</th>
            <th>Type</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Assignee</th>
            <th>Scheduled</th>
            <th class="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="o in orders.data"
            :key="o.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.work-orders.show', o.uuid))"
          >
            <td class="font-weight-medium text-primary">{{ o.work_order_number }}</td>
            <td>{{ o.title }}</td>
            <td>
              <span v-if="o.asset">{{ o.asset.name }}<br><span class="text-caption text-medium-emphasis">{{ o.asset.asset_number }}</span></span>
              <span v-else>—</span>
            </td>
            <td>{{ o.type }}</td>
            <td><VChip size="small" :color="o.priority.color">{{ o.priority.label }}</VChip></td>
            <td><VChip size="small" :color="o.status.color">{{ o.status.label }}</VChip></td>
            <td>{{ o.assignee_name ?? '—' }}</td>
            <td>{{ formatDate(o.scheduled_for) }}</td>
            <td class="text-right">{{ formatCurrency(o.total_cost) }}</td>
          </tr>
          <tr v-if="!orders.data.length">
            <td colspan="9" class="text-center text-medium-emphasis py-8">No work orders found.</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="orders.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!orders.links.prev" @click="router.visit(orders.links.prev!)">Prev</VBtn>
        <span class="text-body-2 align-self-center">Page {{ orders.meta.current_page }} / {{ orders.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!orders.links.next" @click="router.visit(orders.links.next!)">Next</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
