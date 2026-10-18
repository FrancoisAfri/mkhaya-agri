<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; title: string; severity: string; status: string; origin_lot: string | null; lots_count: number; opened_at: string | null; closed_at: string | null }

const props = defineProps<{
  recalls: { data: Row[]; current_page: number; last_page: number }
  filters: { status: string | null }
}>()

const status = ref(props.filters.status)
watch(status, v => router.get(route('manufacturing.quality.recalls.index'), { status: v || undefined }, { preserveState: true, replace: true }))

const headers = [
  { title: t('manufacturing.quality.number'), key: 'number' },
  { title: t('manufacturing.trace.recall_title'), key: 'title', sortable: false },
  { title: t('manufacturing.trace.class'), key: 'severity' },
  { title: t('manufacturing.trace.origin_lot'), key: 'origin_lot' },
  { title: t('manufacturing.trace.lots'), key: 'lots_count', align: 'end' as const },
  { title: t('manufacturing.trace.opened'), key: 'opened_at' },
  { title: t('manufacturing.bom.status'), key: 'status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.trace.recalls') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.trace.recalls_subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-hierarchy" :href="route('manufacturing.quality.traceability')">{{ t('manufacturing.trace.title') }}</VBtn>
    </div>

    <VCard border>
      <VCardText>
        <VSelect v-model="status" clearable hide-details style="max-width: 220px"
                 :items="['OPEN', 'CLOSED'].map(s => ({ title: t(`manufacturing.trace.status.${s}`), value: s }))" :label="t('manufacturing.bom.status')" />
      </VCardText>
      <VDataTable :headers="headers" :items="recalls.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.quality.recalls.show', item.id))">
        <template #item.severity="{ item }"><VChip size="small" label :color="item.severity === 'CLASS_I' ? 'error' : item.severity === 'CLASS_II' ? 'warning' : 'info'">{{ t(`manufacturing.trace.severity.${item.severity}`) }}</VChip></template>
        <template #item.status="{ item }"><VChip size="small" label variant="tonal" :color="item.status === 'OPEN' ? 'error' : 'success'">{{ t(`manufacturing.trace.status.${item.status}`) }}</VChip></template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.trace.no_recalls') }}</div></template>
      </VDataTable>
      <VCardText v-if="recalls.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="recalls.current_page" :length="recalls.last_page" total-visible="7"
                     @update:model-value="(p: number) => router.get(route('manufacturing.quality.recalls.index'), { status: status || undefined, page: p }, { preserveState: true })" />
      </VCardText>
    </VCard>
  </div>
</template>
