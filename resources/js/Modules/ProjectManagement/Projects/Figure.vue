<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  project: { id: string; code: string; name: string }
  figure: {
    id: string; metric: string; value: number | null; text: string | null
    confidence: number; version: string; overridden: boolean; reason: string | null
    computed_at: string | null
  }
  evidence: Array<{
    id: string; type: string; source: string; occurred_at: string | null
    quantity: number | null; unit: string | null; amount: number | null; currency: string | null
    confidence: number; payload: Record<string, any> | null
  }>
}>()
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.figures', project.id))">
      {{ t('project_management.figures.title') }}
    </VBtn>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-h5">
          {{ figure.value !== null ? figure.value : (figure.text ?? '—') }}
        </VCardTitle>
        <VCardSubtitle>{{ t(`project_management.figures.metric_${figure.metric}`) }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <div class="text-body-2">
          {{ t('project_management.figures.built_from', { count: evidence.length }) }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ t('project_management.figures.method', { version: figure.version }) }}
          <template v-if="figure.computed_at"> · {{ figure.computed_at }}</template>
        </div>
        <VAlert v-if="figure.overridden" type="warning" variant="tonal" density="compact" class="mt-3">
          {{ t('project_management.figures.overridden_because', { reason: figure.reason }) }}
          <div class="text-caption mt-1">{{ t('project_management.figures.override_keeps_evidence') }}</div>
        </VAlert>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.figures.the_evidence') }}</VCardTitle>
      </VCardItem>
      <VDivider />

      <VTable v-if="evidence.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.figures.col_when') }}</th>
            <th>{{ t('project_management.figures.col_what') }}</th>
            <th>{{ t('project_management.figures.col_from') }}</th>
            <th class="text-right">{{ t('project_management.figures.col_amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="event in evidence" :key="event.id">
            <td class="text-no-wrap text-caption">{{ event.occurred_at }}</td>
            <td>
              {{ event.type }}
              <div v-if="event.payload && Object.keys(event.payload).length" class="text-caption text-medium-emphasis">
                <span v-for="(value, key) in event.payload" :key="key" class="me-2">{{ key }}: {{ value }}</span>
              </div>
            </td>
            <td class="text-caption">{{ event.source }}</td>
            <td class="text-right text-no-wrap">
              <template v-if="event.quantity !== null">{{ event.quantity }} {{ event.unit }}</template>
              <template v-else-if="event.amount !== null">{{ event.currency }} {{ event.amount }}</template>
              <template v-else>—</template>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.figures.no_evidence') }}
      </VCardText>
    </VCard>
  </div>
</template>
