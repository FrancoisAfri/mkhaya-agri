<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { item: string; name: string; uom: string; warehouse: string; class: string; qty: number; last_out: string | null; days_idle: number; monthly_use: number; months_cover: number | null; excess_qty: number; excess_value: number; actions: Array<{ kind: string; text: string }> }
const props = defineProps<{ analysis: { thresholds: Record<string, number>; rows: Row[]; totals: Record<string, { items: number; value: number }> } }>()
const f = reactive({ ...props.analysis.thresholds })
const apply = () => router.get(route('warehouse.planning.excess'), f, { preserveState: true })
const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const color = (c: string) => ({ OBSOLETE: 'error', NON_MOVING: 'warning', SLOW: 'info' } as Record<string, string>)[c]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.excess.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.excess.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VTextField v-model.number="f.non_moving_days" type="number" density="compact" hide-details style="max-width: 190px" :label="t('warehouse_management.excess.non_moving_days')" />
      <VTextField v-model.number="f.obsolete_days" type="number" density="compact" hide-details style="max-width: 190px" :label="t('warehouse_management.excess.obsolete_days')" />
      <VTextField v-model.number="f.slow_cover_months" type="number" density="compact" hide-details style="max-width: 190px" :label="t('warehouse_management.excess.slow_cover')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard v-for="(v, k) in analysis.totals" :key="k" border class="pa-3" min-width="180">
        <div class="text-caption">{{ t(`warehouse_management.excess_class.${k}`) }}</div><div class="text-h6">{{ money(v.value) }}</div><div class="text-caption">{{ t('warehouse_management.excess.items', { n: v.items }) }}</div>
      </VCard>
    </div>
    <VCard border>
      <VCardText v-if="!analysis.rows.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.excess.none') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="r in analysis.rows" :key="`${r.item}${r.warehouse}`">
          <VListItemTitle>{{ r.item }} · {{ r.name }} <span class="text-caption">{{ r.warehouse }}</span>
            <VChip size="x-small" :color="color(r.class)" class="ms-1">{{ t(`warehouse_management.excess_class.${r.class}`) }}</VChip></VListItemTitle>
          <VListItemSubtitle>{{ t('warehouse_management.excess.line', { qty: r.qty, uom: r.uom, days: r.days_idle, excess: r.excess_qty, value: money(r.excess_value) }) }}
            <span v-if="r.months_cover !== null"> · {{ t('warehouse_management.excess.cover', { m: r.months_cover }) }}</span></VListItemSubtitle>
          <div v-for="a in r.actions" :key="a.text" class="text-caption"><VIcon icon="tabler-arrow-right" size="12" /> {{ a.text }}</div>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
