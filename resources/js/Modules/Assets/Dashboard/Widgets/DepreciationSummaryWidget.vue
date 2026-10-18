<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps<{ mtd: number; ytd: number }>()
function money(n: number) { return `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` }
</script>
<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('assets_widgets.depreciation.title') }}</VCardTitle>
      <div class="d-flex flex-column gap-2 mt-2">
        <div><div class="text-overline">{{ t('assets_widgets.depreciation.month_to_date') }}</div><div class="text-h5">{{ money(props.mtd) }}</div></div>
        <div><div class="text-overline">{{ t('assets_widgets.depreciation.year_to_date') }}</div><div class="text-h5">{{ money(props.ytd) }}</div></div>
      </div>
    </VCardItem>
  </VCard>
</template>
