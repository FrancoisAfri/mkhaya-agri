<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  utilisation: Array<{ user_id: number; name: string; rostered_minutes: number; logged_minutes: number; operations: number; utilisation: number | null }>
  planned_vs_actual: { planned_minutes: number; actual_minutes: number; efficiency: number | null
    operations: Array<{ order_id: string; order: string; operation: string; work_centre: string | null; planned: number; actual: number; variance: number }> }
  gaps: Array<{ work_centre: string; date: string; needed: number; rostered: number; gap: number; spare: number }>
  filters: { from: string; to: string }
}>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.workforce.labour'), v, { preserveState: true, replace: true }), { deep: true })
const hours = (m: number) => (m / 60).toFixed(1)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.labour.labour_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.labour.labour_subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" :href="route('manufacturing.workforce.labour.hours', f)">{{ t('manufacturing.labour.hours_csv') }}</VBtn>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.to')" />
      </VCardText>
    </VCard>

    <VRow class="mb-1">
      <VCol cols="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.labour.planned_hours') }}</div><div class="text-h5">{{ hours(planned_vs_actual.planned_minutes) }}</div></VCardText></VCard></VCol>
      <VCol cols="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.labour.actual_hours') }}</div><div class="text-h5">{{ hours(planned_vs_actual.actual_minutes) }}</div></VCardText></VCard></VCol>
      <VCol cols="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.labour.efficiency') }}</div><div class="text-h5">{{ planned_vs_actual.efficiency === null ? '—' : `${planned_vs_actual.efficiency}%` }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.by_person') }}</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.labour.person') }}</th><th class="text-end">{{ t('manufacturing.labour.rostered_h') }}</th><th class="text-end">{{ t('manufacturing.labour.logged_h') }}</th><th class="text-end">%</th><th class="text-end">{{ t('manufacturing.labour.ops') }}</th></tr></thead>
            <tbody>
              <tr v-for="u in utilisation" :key="u.user_id"><td>{{ u.name }}</td><td class="text-end">{{ hours(u.rostered_minutes) }}</td><td class="text-end">{{ hours(u.logged_minutes) }}</td>
                <td class="text-end">{{ u.utilisation ?? '—' }}</td><td class="text-end">{{ u.operations }}</td></tr>
              <tr v-if="!utilisation.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.labour.no_labour') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.gaps_title') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.labour.gaps_hint') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.trace.date') }}</th><th>{{ t('manufacturing.floor.work_centre') }}</th><th class="text-end">{{ t('manufacturing.labour.needed_h') }}</th><th class="text-end">{{ t('manufacturing.labour.rostered_h') }}</th><th class="text-end">{{ t('manufacturing.labour.overtime_h') }}</th></tr></thead>
            <tbody>
              <tr v-for="g in gaps" :key="g.date + g.work_centre"><td>{{ g.date }}</td><td>{{ g.work_centre }}</td><td class="text-end">{{ hours(g.needed) }}</td><td class="text-end">{{ hours(g.rostered) }}</td>
                <td class="text-end" :class="{ 'text-error font-weight-medium': g.gap > 0 }">{{ hours(g.gap) }}</td></tr>
              <tr v-if="!gaps.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.labour.no_gaps') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.labour.pva_title') }}</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.floor.operation') }}</th><th class="text-end">{{ t('manufacturing.labour.planned_min') }}</th><th class="text-end">{{ t('manufacturing.labour.actual_min') }}</th><th class="text-end">{{ t('manufacturing.orders.variance') }}</th></tr></thead>
            <tbody>
              <tr v-for="(o, i) in planned_vs_actual.operations" :key="i">
                <td><a :href="route('manufacturing.production.orders.show', o.order_id)">{{ o.order }}</a> · {{ o.operation }}<div class="text-caption text-medium-emphasis">{{ o.work_centre }}</div></td>
                <td class="text-end">{{ o.planned }}</td><td class="text-end">{{ o.actual }}</td><td class="text-end" :class="o.variance > 0 ? 'text-error' : 'text-success'">{{ o.variance }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
