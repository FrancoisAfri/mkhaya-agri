<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

defineProps<{ reports: string[]; filters: Record<string, any> }>()

const icons: Record<string, string> = { cards: 'tabler-clipboard-list', notes: 'tabler-notes', parts: 'tabler-tool' }
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 900px;">
    <h1 class="text-h5 font-weight-bold mb-1">{{ t('fleet_job_cards.reports_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">{{ t('fleet_job_cards.reports_subtitle') }}</p>

    <VRow>
      <VCol v-for="r in reports" :key="r" cols="12" md="4">
        <VCard :href="route('fleet.job-cards.reports.show', r)" class="text-center py-6" hover>
          <VIcon :icon="icons[r] ?? 'tabler-file'" size="36" color="primary" class="mb-2" />
          <VCardTitle class="text-subtitle-1">{{ t(`fleet_job_cards.report.${r}`) }}</VCardTitle>
          <VCardSubtitle>{{ t(`fleet_job_cards.report_desc.${r}`) }}</VCardSubtitle>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
