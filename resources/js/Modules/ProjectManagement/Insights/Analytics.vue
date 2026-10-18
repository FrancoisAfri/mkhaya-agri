<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PmCharts from './PmCharts.vue'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  charts: Record<string, any>
  can: { costs: boolean }
}>()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.insights.analytics_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.insights.analytics_sub') }}</p>
      </div>
      <VBtn
        variant="tonal" prepend-icon="tabler-file-analytics"
        @click="router.get(route('project-management.insights.reports'))"
      >
        {{ t('project_management.insights.reports_title') }}
      </VBtn>
    </div>

    <!-- Cost figures are absent from the payload without the grant, not merely hidden here. -->
    <VAlert v-if="!can.costs" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('project_management.insights.no_cost_access') }}
    </VAlert>

    <PmCharts :charts="charts as any" :height="300" />
  </div>
</template>
