<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Request = {
  id: number
  request_number: string
  status: string
  urgency: string
  is_emergency: boolean
  estimated_total: number | null
  currency: string | null
  budget: string | null
  needed_by: string | null
  created_at: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  requests: { data: Request[]; links: any[]; meta: any }
  filters: { search?: string; status?: string; urgency?: string; mine?: boolean }
  statuses: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')
const urgencyFilter = ref(props.filters.urgency ?? '')
const mine = ref(!!props.filters.mine)

const urgencies = ['low', 'normal', 'high', 'emergency']

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft: 'grey',
  pending_approval: 'warning',
  validation_failed: 'error',
  approved: 'success',
  rejected: 'error',
  cancelled: 'grey',
  converted: 'info',
}

const urgencyColor: Record<string, string> = {
  low: 'grey',
  normal: 'info',
  high: 'warning',
  emergency: 'error',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const statusLabels = computed<Record<string, string>>(() => ({
  draft: t('purchase_requests_index.statuses.draft'),
  pending_approval: t('purchase_requests_index.statuses.pending_approval'),
  validation_failed: t('purchase_requests_index.statuses.validation_failed'),
  approved: t('purchase_requests_index.statuses.approved'),
  rejected: t('purchase_requests_index.statuses.rejected'),
  cancelled: t('purchase_requests_index.statuses.cancelled'),
  converted: t('purchase_requests_index.statuses.converted'),
}))

function getStatusLabel(status: string): string {
  return statusLabels.value[status] || status
}

const urgencyLabels = computed<Record<string, string>>(() => ({
  low: t('purchase_requests_index.urgencies.low'),
  normal: t('purchase_requests_index.urgencies.normal'),
  high: t('purchase_requests_index.urgencies.high'),
  emergency: t('purchase_requests_index.urgencies.emergency'),
}))

function getUrgencyLabel(urgency: string): string {
  return urgencyLabels.value[urgency] || urgency
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

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

// ── Methods ────────────────────────────────────────────────────────────────────
function applyFilters(): void {
  router.get(
    route('purchase-requests.index'),
    {
      search: search.value,
      status: statusFilter.value,
      urgency: urgencyFilter.value,
      mine: mine.value ? 1 : undefined,
    },
    { preserveState: true, replace: true }
  )
}

function goToCreate(): void {
  router.get(route('purchase-requests.create'))
}

// Addressed by uuid, not the row id.
function goToShow(uuid: string): void {
  router.get(route('purchase-requests.show', uuid))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-shopping-cart" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('purchase_requests_index.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('purchase_requests_index.page_description') }}
          </p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
        {{ t('purchase_requests_index.new_request') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('purchase_requests_index.search_placeholder')"
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
          :label="t('purchase_requests_index.status')"
          style="max-width:200px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="urgencyFilter"
          :items="['', ...urgencies]"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('purchase_requests_index.urgency')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VSwitch
          v-model="mine"
          :label="t('purchase_requests_index.my_requests_only')"
          color="primary"
          density="compact"
          hide-details
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">
          {{ t('purchase_requests_index.filter') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('purchase_requests_index.table.number') }}</th>
            <th>{{ t('purchase_requests_index.table.status') }}</th>
            <th>{{ t('purchase_requests_index.table.urgency') }}</th>
            <th>{{ t('purchase_requests_index.table.budget') }}</th>
            <th class="text-right">{{ t('purchase_requests_index.table.total') }}</th>
            <th>{{ t('purchase_requests_index.table.needed_by') }}</th>
            <th>{{ t('purchase_requests_index.table.created') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!requests.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">
              {{ t('purchase_requests_index.no_requests') }}
            </td>
          </tr>
          <tr
            v-for="r in requests.data"
            :key="r.id"
            style="cursor:pointer"
            @click="goToShow(r.uuid)"
          >
            <td class="font-weight-medium">{{ r.request_number }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor[r.status] || 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(r.status) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="r.is_emergency ? 'error' : (urgencyColor[r.urgency] || 'grey')"
                variant="tonal"
              >
                {{ getUrgencyLabel(r.is_emergency ? 'emergency' : r.urgency) }}
              </VChip>
            </td>
            <td>{{ r.budget ?? '—' }}</td>
            <td class="text-right">{{ formatCurrency(r.estimated_total, r.currency) }}</td>
            <td>{{ formatDate(r.needed_by) }}</td>
            <td>{{ formatDate(r.created_at) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
