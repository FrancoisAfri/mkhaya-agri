<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute
const props = defineProps<{ report: Record<string, any> | null; filters: { from: string; to: string }; warehouse: string | null; warehouses: Array<{ id: string; title: string }> }>()
const f = reactive({ ...props.filters, warehouse: props.warehouse })
const apply = () => router.get(route('warehouse.work.performance'), f, { preserveState: true })
const v = (x: number | null | undefined, suffix = '') => x === null || x === undefined ? '—' : `${x}${suffix}`
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.work.performance_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.work.performance_subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VSelect v-model="f.warehouse" :items="warehouses" item-value="id" density="compact" hide-details style="min-width: 220px" />
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <template v-if="report">
      <VAlert v-if="report.bottleneck" type="warning" variant="tonal" class="mb-4">{{ t('warehouse_management.work.bottleneck', { stage: t(`warehouse_management.task_type.${report.bottleneck.stage}`), overdue: report.bottleneck.overdue, blocked: report.bottleneck.blocked }) }}</VAlert>
      <div class="d-flex gap-3 mb-4 flex-wrap">
        <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.work.pick_accuracy') }}</div><div class="text-h5">{{ v(report.accuracy.picking, '%') }}</div></VCard>
        <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.work.cycle') }}</div><div class="text-h5">{{ v(report.cycle.median_hours, ' h') }}</div><div class="text-caption">p90 {{ v(report.cycle.p90_hours, ' h') }}</div></VCard>
        <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.work.on_time') }}</div><div class="text-h5">{{ v(report.cycle.on_time, '%') }}</div></VCard>
        <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.work.receipt_lines') }}</div><div class="text-h5">{{ report.throughput.receipt_lines_per_day }}</div></VCard>
        <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.work.pick_lines') }}</div><div class="text-h5">{{ report.throughput.pick_lines_per_day }}</div><div class="text-caption">{{ t('warehouse_management.work.per_pick', { m: v(report.throughput.median_minutes_per_pick) }) }}</div></VCard>
      </div>
      <VRow>
        <VCol cols="12" md="6">
          <VCard border :title="t('warehouse_management.work.queues')">
            <VTable density="compact"><tbody>
              <tr v-for="q in report.queues" :key="q.stage"><td>{{ t(`warehouse_management.task_type.${q.stage}`) }}</td><td class="text-end">{{ q.waiting }}</td>
                <td class="text-end" :class="q.overdue ? 'text-error' : ''">{{ t('warehouse_management.work.overdue_n', { n: q.overdue }) }}</td><td class="text-end">{{ t('warehouse_management.work.blocked_n', { n: q.blocked }) }}</td></tr>
            </tbody></VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="6">
          <VCard border :title="t('warehouse_management.work.rework')">
            <VList density="compact">
              <VListItem :title="t('warehouse_management.work.short_picks')" :subtitle="String(report.rework.short_picks)" />
              <VListItem :title="t('warehouse_management.work.recounted')" :subtitle="`${report.rework.recounted_lines} / ${report.rework.count_lines}`" />
              <VListItem :title="t('warehouse_management.work.reopened')" :subtitle="String(report.rework.reopened_packages)" />
              <VListItem :title="t('warehouse_management.work.scan_errors')" :subtitle="String(report.accuracy.scan_errors)" />
            </VList>
          </VCard>
        </VCol>
      </VRow>
      <p class="text-caption text-medium-emphasis mt-3">{{ t('warehouse_management.work.fairness') }}</p>
      <p v-for="n in report.not_measured" :key="n" class="text-caption text-medium-emphasis mb-0">· {{ n }}</p>
    </template>
  </div>
</template>
