<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute
const props = defineProps<{ quality: Record<string, number | null>; flagged: Array<{ question: string; topic: string | null; verdict: string; note: string | null; created_at: string }>; filters: { from: string; to: string } }>()
const f = reactive({ ...props.filters })
const keys = ['questions', 'refused', 'accuracy', 'flagged_incorrect', 'usefulness', 'drafts_made', 'false_alert_rate', 'missed_events', 'latency_p50_ms', 'latency_p90_ms', 'tokens', 'cost']
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.assistant.index')">{{ t('warehouse_management.assistant.title') }}</a></div>
      <h4 class="text-h4">{{ t('warehouse_management.assistant.quality') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.assistant.quality_subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-4">
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" />
      <VBtn color="primary" @click="router.get(route('warehouse.assistant.quality'), f)">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard v-for="k in keys" :key="k" border class="pa-3" min-width="160">
        <div class="text-caption">{{ t(`warehouse_management.assistant_metric.${k}`) }}</div><div class="text-h6">{{ quality[k] ?? '—' }}</div>
      </VCard>
    </div>
    <VCard border :title="t('warehouse_management.assistant.flagged')">
      <VCardText v-if="!flagged.length" class="text-medium-emphasis">{{ t('warehouse_management.assistant.none_flagged') }}</VCardText>
      <VList v-else lines="two"><VListItem v-for="(x, i) in flagged" :key="i" :title="x.question" :subtitle="`${x.verdict}${x.note ? ' · ' + x.note : ''}`" /></VList>
    </VCard>
  </div>
</template>
