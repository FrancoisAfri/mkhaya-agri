<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Approval {
  id: number
  supplier: { company_name: string } | null
  supplier_name: string | null
  submitted_at: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
defineProps<{
  approvals: Approval[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const comments = ref<Record<number, string>>({})

// ── Methods ────────────────────────────────────────────────────────────────────
function approve(id: number): void {
  router.post(
    route('suppliers.kyc.approve', id),
    { comment: comments.value[id] ?? '' },
    { preserveScroll: true }
  )
}

function reject(id: number): void {
  router.post(
    route('suppliers.kyc.reject', id),
    { comment: comments.value[id] ?? '' },
    { preserveScroll: true }
  )
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

function getSupplierName(approval: Approval): string {
  return approval.supplier?.company_name ?? approval.supplier_name ?? '—'
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_kyc_approvals.page_title') }}</h1>
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_kyc_approvals.table.supplier') }}</th>
            <th>{{ t('supplier_kyc_approvals.table.submitted') }}</th>
            <th>{{ t('supplier_kyc_approvals.table.comment') }}</th>
            <th class="text-right">{{ t('supplier_kyc_approvals.table.decision') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!approvals.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_kyc_approvals.no_approvals') }}
            </td>
          </tr>
          <tr v-for="a in approvals" :key="a.id">
            <td class="font-weight-medium">{{ getSupplierName(a) }}</td>
            <td>{{ formatDate(a.submitted_at) }}</td>
            <td style="min-width:220px">
              <VTextField
                v-model="comments[a.id]"
                density="compact"
                variant="outlined"
                hide-details
                :placeholder="t('supplier_kyc_approvals.placeholder')"
              />
            </td>
            <td class="text-right">
              <VBtn
                size="x-small"
                variant="tonal"
                color="success"
                class="me-2"
                @click="approve(a.id)"
              >
                {{ t('supplier_kyc_approvals.actions.approve') }}
              </VBtn>
              <VBtn
                size="x-small"
                variant="tonal"
                color="error"
                @click="reject(a.id)"
              >
                {{ t('supplier_kyc_approvals.actions.reject') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
