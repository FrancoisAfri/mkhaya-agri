<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps<{ states: Array<{ state: string; derived: boolean; physical: boolean; available: boolean; fulfilment: boolean; valuation: boolean }> }>()
const cols = ['physical', 'available', 'fulfilment', 'valuation'] as const
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.states.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.states.subtitle') }}</p>
    </div>
    <VCard border>
      <VTable>
        <thead>
          <tr><th>{{ t('warehouse_management.states.state') }}</th><th v-for="c in cols" :key="c" class="text-center">{{ t(`warehouse_management.states.col.${c}`) }}</th><th>{{ t('warehouse_management.states.meaning') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in states" :key="s.state">
            <td class="text-no-wrap">
              <VChip size="small" label variant="tonal" :color="s.state === 'AVAILABLE' ? 'success' : 'warning'">{{ t(`warehouse_management.stock_state.${s.state}`) }}</VChip>
              <span v-if="s.derived" class="text-caption text-medium-emphasis ms-1">{{ t('warehouse_management.states.derived') }}</span>
            </td>
            <td v-for="c in cols" :key="c" class="text-center">
              <VIcon :icon="s[c] ? 'tabler-check' : 'tabler-minus'" :color="s[c] ? 'success' : undefined" :aria-label="s[c] ? t('warehouse_management.common.yes') : t('warehouse_management.common.no')" />
            </td>
            <td class="text-body-2">{{ t(`warehouse_management.states.meaning_of.${s.state}`) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
