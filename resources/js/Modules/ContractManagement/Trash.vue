<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: number; contract_number: string; title: string; deleted_at: string | null }

defineProps<{ contracts: { data: Row[]; links: any[] } }>()

const confirmDialog = ref()

function restore(contract: Row) {
  router.post(route('contracts.restore', contract.id), {}, { preserveScroll: true })
}
function forceDelete(contract: Row) {
  confirmDialog.value.open(
    {
      title: t('contracts_trash.confirm.title'),
      message: t('contracts_trash.confirm.message', { number: contract.contract_number }),
      confirmText: t('contracts_trash.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.force-delete', contract.id), { preserveScroll: true }) },
  )
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.index'))" />
      <h4 class="text-h4 mb-0">{{ t('contracts_trash.page_title') }}</h4>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('contracts_trash.table.number') }}</th>
            <th>{{ t('contracts_trash.table.title') }}</th>
            <th>{{ t('contracts_trash.table.deleted') }}</th>
            <th class="text-right">{{ t('contracts_trash.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!contracts.data.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">{{ t('contracts_trash.empty') }}</td>
          </tr>
          <tr v-for="contract in contracts.data" :key="contract.id">
            <td class="font-weight-medium">{{ contract.contract_number }}</td>
            <td>{{ contract.title }}</td>
            <td>{{ contract.deleted_at ?? '—' }}</td>
            <td class="text-right">
              <VBtn size="small" variant="tonal" color="primary" class="me-2" @click="restore(contract)">
                {{ t('contracts_trash.restore') }}
              </VBtn>
              <VBtn size="small" variant="tonal" color="error" @click="forceDelete(contract)">
                {{ t('contracts_trash.delete') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
