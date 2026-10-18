<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  checks: Array<{ check: string; state: 'green' | 'amber' | 'red'; detail: string }>
  ledger: { ok: boolean; checked: Record<string, number>; problems: Array<Record<string, any>> } | null
}>()
const color = { green: 'success', amber: 'warning', red: 'error' }
const verify = () => router.get(route('warehouse.health'), { verify: 1 }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.health.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.health.subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" @click="verify">{{ t('warehouse_management.health.verify') }}</VBtn>
    </div>
    <VAlert v-if="ledger" :type="ledger.ok ? 'success' : 'error'" variant="tonal" class="mb-4" role="status">
      {{ ledger.ok ? t('warehouse_management.health.ledger_ok', { n: ledger.checked.item_warehouses }) : t('warehouse_management.health.ledger_bad', { n: ledger.problems.length }) }}
      <div v-for="(p, i) in ledger.problems.slice(0, 30)" :key="i" class="text-caption">{{ t(`warehouse_management.health.kind.${p.kind}`) }} · {{ p.item }} {{ p.warehouse ?? '' }}
        · {{ Object.entries(p).filter(([k]) => !['kind', 'item', 'item_id', 'warehouse', 'warehouse_id'].includes(k)).map(([k, v]) => `${k} ${v}`).join(', ') }}</div>
    </VAlert>
    <VCard border>
      <VList>
        <VListItem v-for="c in checks" :key="c.check" :title="t(`warehouse_management.health.check.${c.check.replace(':', '_').replace('-', '_')}`)" :subtitle="c.detail">
          <template #prepend><VIcon :color="color[c.state]" :icon="c.state === 'green' ? 'mdi-check-circle' : c.state === 'amber' ? 'mdi-alert' : 'mdi-close-circle'" :aria-label="c.state" /></template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
