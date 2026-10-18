<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
type Row = { uuid: string; name: string; asset_tag: string | null; asset_status: string }
const props = defineProps<{ items: Row[] }>()
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.idle_assets.title') }}</VCardTitle>
      <VTable density="compact">
        <tbody>
          <tr v-for="row in props.items" :key="row.uuid">
            <td>{{ row.name }}</td><td>{{ row.asset_tag ?? '—' }}</td>
            <td><VChip size="x-small">{{ row.asset_status }}</VChip></td>
          </tr>
          <tr v-if="!props.items.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_widgets.idle_assets.no_data') }}</td></tr>
        </tbody>
      </VTable>
    </VCardItem>
  </VCard>
</template>
