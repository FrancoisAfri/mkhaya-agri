<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  company_name: string
  supplier_number: string | null
  is_active: number
  created_at: string | null
}

interface Stats {
  total_suppliers: number
  pending_kyc: number
  performance_reviews: number
  open_risk_flags: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  stats: Stats
  recent: Supplier[]
}>()

// ── Constants ──────────────────────────────────────────────────────────────────
const statusLabel: Record<number, string> = {
  0: t('supplier_dashboard.statuses.pending'),
  1: t('supplier_dashboard.statuses.active'),
  2: t('supplier_dashboard.statuses.refused'),
  3: t('supplier_dashboard.statuses.pending_kyc'),
}

const statusColor: Record<number, string> = {
  0: 'warning',
  1: 'success',
  2: 'error',
  3: 'info',
}

// ── Methods ────────────────────────────────────────────────────────────────────
function goToSuppliers(): void {
  router.get(route('suppliers.index'))
}

function goToCreate(): void {
  router.get(route('suppliers.create'))
}

// A supplier is addressed by uuid, not by its row id.
function goToShow(uuid: string): void {
  router.get(route('suppliers.show', uuid))
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-building-store" size="26" />
        </VAvatar>
        <div>
          <h1 class="text-h4 mb-0">{{ t('supplier_dashboard.page_title') }}</h1>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('supplier_dashboard.page_description') }}
          </p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-list" @click="goToSuppliers">
          {{ t('supplier_dashboard.suppliers') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
          {{ t('supplier_dashboard.new_supplier') }}
        </VBtn>
      </div>
    </div>

    <!-- Stats -->
    <VRow class="mb-2">
      <VCol cols="12" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_dashboard.stats.total_suppliers') }}</div>
            <div class="text-h3">{{ stats.total_suppliers }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_dashboard.stats.pending_kyc') }}</div>
            <div class="text-h3">{{ stats.pending_kyc }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_dashboard.stats.performance_reviews') }}</div>
            <div class="text-h3">{{ stats.performance_reviews }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="3">
        <VCard
          border
          :color="stats.open_risk_flags > 0 ? 'error' : undefined"
          :variant="stats.open_risk_flags > 0 ? 'tonal' : undefined"
        >
          <VCardText>
            <div class="d-flex align-center gap-2">
              <VIcon
                icon="tabler-alert-triangle"
                size="18"
                :color="stats.open_risk_flags > 0 ? 'error' : 'medium-emphasis'"
              />
              <div class="text-caption text-medium-emphasis">{{ t('supplier_dashboard.stats.open_risk_flags') }}</div>
            </div>
            <div class="text-h3">{{ stats.open_risk_flags }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent Suppliers -->
    <VCard border>
      <VCardTitle>{{ t('supplier_dashboard.recent_suppliers') }}</VCardTitle>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_dashboard.table.number') }}</th>
            <th>{{ t('supplier_dashboard.table.company') }}</th>
            <th>{{ t('supplier_dashboard.table.status') }}</th>
            <th>{{ t('supplier_dashboard.table.created') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!recent.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_dashboard.table.no_suppliers') }}
            </td>
          </tr>
          <tr
            v-for="s in recent"
            :key="s.id"
            style="cursor:pointer"
            @click="goToShow(s.uuid)"
          >
            <td>{{ s.supplier_number ?? '—' }}</td>
            <td class="font-weight-medium">{{ s.company_name }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor[s.is_active]"
                variant="tonal"
              >
                {{ statusLabel[s.is_active] }}
              </VChip>
            </td>
            <td>{{ formatDate(s.created_at) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
