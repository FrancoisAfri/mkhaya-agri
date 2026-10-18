<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Row = { asset_uuid: string; asset_name: string; asset_tag: string | null; risk_score: number; risk_level: string }
const props = defineProps<{ items: Row[] }>()
function color(level: string) { return level === 'critical' ? 'error' : level === 'high' ? 'orange' : level === 'medium' ? 'warning' : 'success' }
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.high_risk.title') }}</VCardTitle>
      <VTable density="compact">
        <tbody>
          <tr v-for="row in props.items" :key="row.asset_uuid">
            <td>{{ row.asset_name }}</td><td>{{ row.asset_tag ?? '—' }}</td>
            <td><VChip :color="color(row.risk_level)" size="small">{{ row.risk_score }}</VChip></td>
          </tr>
          <tr v-if="!props.items.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_widgets.high_risk.no_data') }}</td></tr>
        </tbody>
      </VTable>
    </VCardItem>
  </VCard>
</template>
