<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface RfxEvent {
  id: number
  rfx_number: string
  title: string
  type: string
  status: string
  closing_date: string | null
  responses_count: number
}

interface Pagination {
  data: RfxEvent[]
  links: any[]
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  events: Pagination
  filters: { status?: string }
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const statusFilter = ref<string>(props.filters.status ?? '')

// ── Constants ──────────────────────────────────────────────────────────────────
const typeLabel: Record<string, string> = {
  rfq: t('supplier_sourcing_rfx.types.rfq'),
  rfp: t('supplier_sourcing_rfx.types.rfp'),
  rfi: t('supplier_sourcing_rfx.types.rfi'),
}

const statusColor: Record<string, string> = {
  draft: 'secondary',
  published: 'info',
  closed: 'warning',
  awarded: 'success',
  cancelled: 'error',
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    draft: t('supplier_sourcing_rfx.statuses.draft'),
    published: t('supplier_sourcing_rfx.statuses.published'),
    closed: t('supplier_sourcing_rfx.statuses.closed'),
    awarded: t('supplier_sourcing_rfx.statuses.awarded'),
    cancelled: t('supplier_sourcing_rfx.statuses.cancelled'),
  }
  return map[status] || status
}

// ── Methods ────────────────────────────────────────────────────────────────────
function applyFilters(): void {
  router.get(
    route('suppliers.sourcing.index'),
    { status: statusFilter.value },
    { preserveState: true, replace: true }
  )
}

function goToCreate(): void {
  router.get(route('suppliers.sourcing.create'))
}

function goToShow(id: number): void {
  router.get(route('suppliers.sourcing.show', id))
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

const statusOptions = [
  { title: t('supplier_sourcing_rfx.filters.all'), value: '' },
  { title: t('supplier_sourcing_rfx.statuses.draft'), value: 'draft' },
  { title: t('supplier_sourcing_rfx.statuses.published'), value: 'published' },
  { title: t('supplier_sourcing_rfx.statuses.closed'), value: 'closed' },
  { title: t('supplier_sourcing_rfx.statuses.awarded'), value: 'awarded' },
  { title: t('supplier_sourcing_rfx.statuses.cancelled'), value: 'cancelled' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <h1 class="text-h4 mb-0">{{ t('supplier_sourcing_rfx.page_title') }}</h1>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
        {{ t('supplier_sourcing_rfx.new_rfx') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VSelect
          v-model="statusFilter"
          :items="statusOptions"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('supplier_sourcing_rfx.filters.status')"
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_sourcing_rfx.table.number') }}</th>
            <th>{{ t('supplier_sourcing_rfx.table.title') }}</th>
            <th>{{ t('supplier_sourcing_rfx.table.type') }}</th>
            <th>{{ t('supplier_sourcing_rfx.table.status') }}</th>
            <th>{{ t('supplier_sourcing_rfx.table.closing') }}</th>
            <th>{{ t('supplier_sourcing_rfx.table.responses') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!events.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_sourcing_rfx.no_events') }}
            </td>
          </tr>
          <tr
            v-for="e in events.data"
            :key="e.id"
            style="cursor:pointer"
            @click="goToShow(e.id)"
          >
            <td>{{ e.rfx_number }}</td>
            <td class="font-weight-medium">{{ e.title }}</td>
            <td>{{ typeLabel[e.type] ?? e.type }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor[e.status] ?? 'default'"
                variant="tonal"
              >
                {{ getStatusLabel(e.status) }}
              </VChip>
            </td>
            <td>{{ formatDate(e.closing_date) }}</td>
            <td>{{ e.responses_count }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
