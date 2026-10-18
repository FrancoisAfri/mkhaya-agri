<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps<{ total: number; active: number; unallocated: number; under_maintenance: number }>()
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.asset_inventory.title') }}</VCardTitle>
      <div class="d-flex flex-wrap gap-4 mt-3">
        <div><div class="text-overline">{{ t('assets_widgets.asset_inventory.total') }}</div><div class="text-h4">{{ props.total }}</div></div>
        <div><div class="text-overline">{{ t('assets_widgets.asset_inventory.active') }}</div><div class="text-h5 text-success">{{ props.active }}</div></div>
        <div><div class="text-overline">{{ t('assets_widgets.asset_inventory.unallocated') }}</div><div class="text-h5 text-warning">{{ props.unallocated }}</div></div>
        <div><div class="text-overline">{{ t('assets_widgets.asset_inventory.maintenance') }}</div><div class="text-h5">{{ props.under_maintenance }}</div></div>
      </div>
    </VCardItem>
  </VCard>
</template>
