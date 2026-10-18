<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Row = { asset_id: number; recommended_replacement_date: string; confidence_score: number; asset?: { uuid: string; name: string } }
const props = defineProps<{ items: Row[] }>()
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.near_replacement.title') }}</VCardTitle>
      <VTable density="compact">
        <tbody>
          <tr v-for="row in props.items" :key="row.asset_id">
            <td>{{ row.asset?.name ?? `#${row.asset_id}` }}</td>
            <td>{{ new Date(row.recommended_replacement_date).toLocaleDateString() }}</td>
            <td><VChip size="x-small">{{ row.confidence_score }}%</VChip></td>
          </tr>
          <tr v-if="!props.items.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_widgets.near_replacement.no_data') }}</td></tr>
        </tbody>
      </VTable>
    </VCardItem>
  </VCard>
</template>
