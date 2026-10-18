<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const route = ziggyRoute

type Cycle = {
  id: number
  name: string
  cycle_type: string
  status: 'draft' | 'active' | 'closed' | 'archived'
  start_date: string
  end_date: string
}

const props = defineProps<{
  stats: {
    total_cycles: number
    active_cycles: number
    total_templates: number
    total_scales: number
  }
  recentCycles: Cycle[]
}>()

const statusColor = (s: string) => ({ draft: 'default', active: 'success', closed: 'warning', archived: 'secondary' }[s] ?? 'default')
</script>

<template>
  <div class="pa-6">
    <div class="mb-6">
      <h1 class="text-h5 font-weight-bold">{{ t('pa.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.subtitle') }}</p>
    </div>

    <!-- KPI strip -->
    <VRow class="mb-6">
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="primary">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ props.stats.total_cycles }}</div>
            <div class="text-body-2 mt-1">{{ t('pa.dashboard.total_cycles') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="success">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ props.stats.active_cycles }}</div>
            <div class="text-body-2 mt-1">{{ t('pa.dashboard.active_cycles') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="info">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ props.stats.total_templates }}</div>
            <div class="text-body-2 mt-1">{{ t('pa.dashboard.templates_count') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="warning">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ props.stats.total_scales }}</div>
            <div class="text-body-2 mt-1">{{ t('pa.dashboard.scales_count') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Quick actions -->
    <VRow class="mb-6">
      <VCol cols="12">
        <VCard>
          <VCardTitle class="text-subtitle-1 font-weight-medium pa-4 pb-2">{{ t('pa.dashboard.quick_actions') }}</VCardTitle>
          <VCardText class="d-flex gap-3 flex-wrap">
            <VBtn color="primary" prepend-icon="ri-add-line" @click="router.visit(route('performance.cycles.index'))">
              {{ t('pa.dashboard.new_cycle') }}
            </VBtn>
            <VBtn variant="tonal" prepend-icon="ri-file-add-line" @click="router.visit(route('performance.templates.index'))">
              {{ t('pa.dashboard.new_template') }}
            </VBtn>
            <VBtn variant="tonal" prepend-icon="ri-star-line" @click="router.visit(route('performance.rating-scales.index'))">
              {{ t('pa.dashboard.rating_scales') }}
            </VBtn>
            <VBtn variant="text" prepend-icon="ri-settings-3-line" @click="router.visit(route('performance.settings.index'))">
              {{ t('pa.dashboard.settings') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent Cycles -->
    <VCard>
      <VCardTitle class="text-subtitle-1 font-weight-medium pa-4 pb-2 d-flex align-center">
        {{ t('pa.dashboard.recent_cycles') }}
        <VSpacer />
        <VBtn size="small" variant="text" @click="router.visit(route('performance.cycles.index'))">
          {{ t('pa.dashboard.view_all') }}
        </VBtn>
      </VCardTitle>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('pa.cycles.col_name') }}</th>
            <th>{{ t('pa.cycles.col_type') }}</th>
            <th>{{ t('pa.cycles.col_period') }}</th>
            <th>{{ t('pa.cycles.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.recentCycles.length === 0">
            <td colspan="4" class="text-center py-8 text-medium-emphasis">
              {{ t('pa.dashboard.no_cycles') }} <a href="#" @click.prevent="router.visit(route('performance.cycles.index'))">{{ t('pa.dashboard.create_first') }}</a>
            </td>
          </tr>
          <tr v-for="cycle in props.recentCycles" :key="cycle.id">
            <td class="font-weight-medium">{{ cycle.name }}</td>
            <td><VChip size="x-small" variant="tonal">{{ t('pa.cycles.types.' + cycle.cycle_type) }}</VChip></td>
            <td class="text-caption">{{ cycle.start_date }} → {{ cycle.end_date }}</td>
            <td>
              <VChip size="small" :color="statusColor(cycle.status)" label>
                {{ t('pa.cycles.statuses.' + cycle.status) }}
              </VChip>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
