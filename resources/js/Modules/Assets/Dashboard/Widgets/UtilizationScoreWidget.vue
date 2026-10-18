<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Top = { asset_id: number; asset_name?: string; utilization_rate: number }
const props = defineProps<{ summary_stats: { average?: number } | null; top_utilized: Top[]; least_utilized: Top[] }>()
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.utilization.title') }}</VCardTitle>
      <div class="text-overline mt-2">{{ t('assets_widgets.utilization.average') }}</div>
      <div class="text-h4">{{ props.summary_stats?.average != null ? `${Number(props.summary_stats.average).toFixed(1)}%` : '—' }}</div>
      <div class="text-overline mt-3">{{ t('assets_widgets.utilization.top_utilized') }}</div>
      <div v-for="t in props.top_utilized.slice(0,3)" :key="t.asset_id" class="d-flex justify-space-between">
        <span>{{ t.asset_name ?? `#${t.asset_id}` }}</span>
        <strong>{{ Number(t.utilization_rate).toFixed(1) }}%</strong>
      </div>
    </VCardItem>
  </VCard>
</template>
