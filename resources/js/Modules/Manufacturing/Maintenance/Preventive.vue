<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  rules: Array<{ id: string; title: string; kind: string; machine: string | null; machine_id: string | null; is_active: boolean
    progress: Record<string, any> | null; due: string | null; last_triggered_at: string | null; open_work_order: string | null }>
  overdue: Array<{ id: string; number: string; title: string; machine: string | null; scheduled_for: string | null }>
  can: { manage: boolean }
}>()

const generate = () => router.post(route('manufacturing.maintenance.preventive.generate'), {}, { preserveScroll: true })
const progress = (r: any) => r.kind === 'TIME' ? t('manufacturing.maint.next', { at: r.progress?.next })
  : r.kind === 'USAGE' ? t('manufacturing.maint.used', { used: r.progress?.used, of: r.progress?.of })
    : t('manufacturing.maint.so_far', { n: r.progress?.breakdowns })
const headers = [
  { title: t('manufacturing.maint.rule_title'), key: 'title' },
  { title: t('manufacturing.maint.machine'), key: 'machine' },
  { title: t('manufacturing.maint.trigger'), key: 'kind' },
  { title: t('manufacturing.maint.progress'), key: 'progress', sortable: false },
  { title: t('manufacturing.maint.open_wo'), key: 'open_work_order' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.maint.preventive_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.maint.preventive_subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-player-play" @click="generate">{{ t('manufacturing.maint.raise_due') }}</VBtn>
    </div>

    <VAlert v-if="overdue.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-medium mb-1">{{ t('manufacturing.maint.overdue_n', { n: overdue.length }) }}</div>
      <div v-for="o in overdue" :key="o.id" class="text-body-2"><a :href="route('assets.work-orders.show', o.id)">{{ o.number }}</a> · {{ o.title }} · {{ o.scheduled_for }}</div>
    </VAlert>

    <VCard border>
      <VDataTable :headers="headers" :items="rules" item-value="id" hover :items-per-page="50"
                  @click:row="(_: unknown, { item }: any) => item.machine_id && router.visit(route('manufacturing.maintenance.machines.show', item.machine_id))">
        <template #item.title="{ item }"><span :class="{ 'text-disabled': !item.is_active }">{{ item.title }}</span>
          <VChip v-if="item.due" size="x-small" color="warning" label class="ms-1">{{ t('manufacturing.maint.due', { why: item.due }) }}</VChip></template>
        <template #item.kind="{ item }">{{ t(`manufacturing.maint.kind.${item.kind}`) }}</template>
        <template #item.progress="{ item }">{{ progress(item) }}</template>
        <template #item.open_work_order="{ item }">{{ item.open_work_order ?? '—' }}</template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.maint.no_rules') }}</div></template>
      </VDataTable>
    </VCard>
  </div>
</template>
