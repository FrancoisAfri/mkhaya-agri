<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Row = { id: number; description: string; severity: string; status: string; asset?: { uuid: string; name: string } }
const props = defineProps<{ open: number; recent: Row[] }>()
function sevColor(s: string) { return s === 'critical' ? 'error' : s === 'high' ? 'orange' : s === 'medium' ? 'warning' : 'default' }
</script>
<template>
  <VCard>
    <VCardItem>
      <div class="d-flex align-center">
        <VCardTitle>{{ t('assets_widgets.policy_violations.title') }}</VCardTitle>
        <VSpacer />
        <VChip :color="props.open > 0 ? 'error' : 'success'">{{ t('assets_widgets.policy_violations.open_count', { count: props.open }) }}</VChip>
      </div>
      <VTable density="compact" class="mt-2">
        <tbody>
          <tr v-for="row in props.recent" :key="row.id">
            <td>{{ row.asset?.name ?? '—' }}</td><td>{{ row.description }}</td>
            <td><VChip size="x-small" :color="sevColor(row.severity)">{{ row.severity }}</VChip></td>
          </tr>
          <tr v-if="!props.recent.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_widgets.policy_violations.no_data') }}</td></tr>
        </tbody>
      </VTable>
    </VCardItem>
  </VCard>
</template>
