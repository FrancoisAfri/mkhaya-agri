<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Row = { asset_id: number; due_date: string; type: string; asset?: { name: string; uuid: string } }
const props = defineProps<{ items: Row[] }>()
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.upcoming_maintenance.title') }}</VCardTitle>
      <VTable density="compact">
        <tbody>
          <tr v-for="row in props.items" :key="row.asset_id + row.due_date">
            <td>{{ row.asset?.name ?? `#${row.asset_id}` }}</td>
            <td>{{ row.type }}</td>
            <td>{{ new Date(row.due_date).toLocaleDateString() }}</td>
          </tr>
          <tr v-if="!props.items.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_widgets.upcoming_maintenance.no_data') }}</td></tr>
        </tbody>
      </VTable>
    </VCardItem>
  </VCard>
</template>
