<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  company_name: string
  company_registration_number: string | null
  deleted_at: string
}

interface PaginatedData {
  data: Supplier[]
  links: any[]
}

// ── Props ──────────────────────────────────────────────────────────────────────
defineProps<{
  suppliers: PaginatedData
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// ── Methods ────────────────────────────────────────────────────────────────────
function restore(id: number): void {
  router.post(
    route('suppliers.restore', id),
    {},
    { preserveScroll: true }
  )
}

function forceDelete(id: number): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_trash.actions.delete_confirm_title'),
      message: t('supplier_trash.actions.delete_confirm_message'),
      confirmText: t('supplier_trash.actions.delete_confirm_button'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(route('suppliers.force-delete', id), { preserveScroll: true })
    }
  )
}

function goToIndex(): void {
  router.get(route('suppliers.index'))
}

function formatDate(date: string): string {
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
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h4 mb-0">{{ t('supplier_trash.page_title') }}</h1>
      <VBtn variant="tonal" prepend-icon="tabler-arrow-left" @click="goToIndex">
        {{ t('supplier_trash.back') }}
      </VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_trash.table.company') }}</th>
            <th>{{ t('supplier_trash.table.registration') }}</th>
            <th>{{ t('supplier_trash.table.deleted') }}</th>
            <th class="text-right">{{ t('supplier_trash.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!suppliers.data.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_trash.table.empty') }}
            </td>
          </tr>
          <tr v-for="s in suppliers.data" :key="s.id">
            <td class="font-weight-medium">{{ s.company_name }}</td>
            <td>{{ s.company_registration_number ?? '—' }}</td>
            <td>{{ formatDate(s.deleted_at) }}</td>
            <td class="text-right">
              <VBtn
                size="x-small"
                variant="tonal"
                color="success"
                class="me-2"
                @click="restore(s.id)"
              >
                {{ t('supplier_trash.actions.restore') }}
              </VBtn>
              <VBtn
                size="x-small"
                variant="tonal"
                color="error"
                @click="forceDelete(s.id)"
              >
                {{ t('supplier_trash.actions.delete') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
