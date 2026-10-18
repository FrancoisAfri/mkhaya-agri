<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Requisition = {
  id: number
  requisition_number: string
  title: string
  method: string
  status: string
  estimated_total: number | null
  currency: string | null
  budget: string | null
  reservation_confirmed: boolean
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  requisitions: { data: Requisition[]; links: any[]; meta: any }
  filters: { search?: string; status?: string; method?: string }
  statuses: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')
const methodFilter = ref(props.filters.method ?? '')

const methods = ['rfq', 'direct', 'marketplace', 'contract']

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  open: 'grey',
  sourcing: 'info',
  quoting: 'warning',
  awarded: 'success',
  po_created: 'primary',
  closed: 'grey',
  cancelled: 'error',
}

const methodColor: Record<string, string> = {
  rfq: 'info',
  direct: 'primary',
  marketplace: 'warning',
  contract: 'success',
}

// ═══ CORRECTION: Computed helpers ═══
const statusMap = computed(() => ({
  open: t('requisitions_index.statuses.open'),
  sourcing: t('requisitions_index.statuses.sourcing'),
  quoting: t('requisitions_index.statuses.quoting'),
  awarded: t('requisitions_index.statuses.awarded'),
  po_created: t('requisitions_index.statuses.po_created'),
  closed: t('requisitions_index.statuses.closed'),
  cancelled: t('requisitions_index.statuses.cancelled'),
}))

const methodMap = computed(() => ({
  rfq: t('requisitions_index.methods.rfq'),
  direct: t('requisitions_index.methods.direct'),
  marketplace: t('requisitions_index.methods.marketplace'),
  contract: t('requisitions_index.methods.contract'),
}))

const reservationMap = computed(() => ({
  confirmed: t('requisitions_index.reservation.confirmed'),
  pending: t('requisitions_index.reservation.pending'),
}))

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  return statusMap.value[status as keyof typeof statusMap.value] || status
}

function getMethodLabel(method: string): string {
  return methodMap.value[method as keyof typeof methodMap.value] || method
}

function getReservationLabel(confirmed: boolean): string {
  return confirmed ? reservationMap.value.confirmed : reservationMap.value.pending
}

function formatCurrency(value: number | null, currency: string | null): string {
  if (value == null) return '—'
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${currency || ''} ${value}`.trim()
  }
}

// ── Methods ────────────────────────────────────────────────────────────────────
function applyFilters(): void {
  router.get(
    route('requisitions.index'),
    {
      search: search.value,
      status: statusFilter.value,
      method: methodFilter.value,
    },
    { preserveState: true, replace: true }
  )
}

// Addressed by uuid, not the row id.
function goToShow(uuid: string): void {
  router.get(route('requisitions.show', uuid))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-clipboard-list" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('requisitions_index.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('requisitions_index.page_description') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('requisitions_index.search_placeholder')"
          prepend-inner-icon="tabler-search"
          style="max-width:260px"
          @keyup.enter="applyFilters"
        />
        <VSelect
          v-model="statusFilter"
          :items="['', ...statuses]"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('requisitions_index.status')"
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="methodFilter"
          :items="['', ...methods]"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('requisitions_index.method')"
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">
          {{ t('requisitions_index.filter') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('requisitions_index.table.number') }}</th>
            <th>{{ t('requisitions_index.table.title') }}</th>
            <th>{{ t('requisitions_index.table.method') }}</th>
            <th>{{ t('requisitions_index.table.budget') }}</th>
            <th class="text-right">{{ t('requisitions_index.table.total') }}</th>
            <th class="text-center">{{ t('requisitions_index.table.reservation') }}</th>
            <th>{{ t('requisitions_index.table.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!requisitions.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">
              {{ t('requisitions_index.no_requisitions') }}
            </td>
          </tr>
          <tr
            v-for="r in requisitions.data"
            :key="r.id"
            style="cursor:pointer"
            @click="goToShow(r.uuid)"
          >
            <td class="font-weight-medium">{{ r.requisition_number }}</td>
            <td>{{ r.title }}</td>
            <td>
              <VChip
                size="small"
                :color="methodColor[r.method] || 'grey'"
                variant="tonal"
              >
                {{ getMethodLabel(r.method) }}
              </VChip>
            </td>
            <td>{{ r.budget ?? '—' }}</td>
            <td class="text-right">{{ formatCurrency(r.estimated_total, r.currency) }}</td>
            <td class="text-center">
              <VIcon
                :icon="r.reservation_confirmed ? 'tabler-circle-check' : 'tabler-circle'"
                :color="r.reservation_confirmed ? 'success' : 'grey'"
              />
            </td>
            <td>
              <VChip
                size="small"
                :color="statusColor[r.status] || 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(r.status) }}
              </VChip>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
