<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface FunnelRow { stage: string; count: number; value: number }
interface CustomKpi { id: number; name: string; format: string; color: string; value: number }
const props = defineProps<{
  kpis: { open_deals: number; open_value: number; won_this_month: number; won_value: number; new_leads: number; overdue_tasks: number }
  customKpis: CustomKpi[]
  funnel: FunnelRow[]
  pipeline: { id: number; name: string } | null
  recent: { id: number; type: string; subject: string; related: string; when: string }[]
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })
const fmtKpi = (k: CustomKpi) => {
  const v = k.value ?? 0
  if (k.format === 'currency') return money(v)
  if (k.format === 'percent') return v + '%'
  return v.toLocaleString('en-ZA')
}
const maxCount = computed(() => Math.max(1, ...props.funnel.map(f => f.count)))

const cards = computed(() => [
  { label: t('crm_dashboard_index.kpis.open_deals'),    value: props.kpis.open_deals,    sub: money(props.kpis.open_value),   icon: 'tabler-briefcase',  color: 'primary' },
  { label: t('crm_dashboard_index.kpis.won_this_month'),value: props.kpis.won_this_month,sub: money(props.kpis.won_value),    icon: 'tabler-trophy',     color: 'success' },
  { label: t('crm_dashboard_index.kpis.new_leads'),     value: props.kpis.new_leads,     sub: t('crm_dashboard_index.kpis.awaiting_work'), icon: 'tabler-user-plus', color: 'info' },
  { label: t('crm_dashboard_index.kpis.overdue_tasks'), value: props.kpis.overdue_tasks, sub: t('crm_dashboard_index.kpis.need_attention'), icon: 'tabler-alarm', color: 'error' },
])
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_dashboard_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_dashboard_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-layout-kanban" @click="router.visit(route('crm.deals'))">
        {{ t('crm_dashboard_index.open_pipeline') }}
      </VBtn>
    </div>

    <!-- KPI Cards -->
    <VRow class="mb-2">
      <VCol v-for="c in cards" :key="c.label" cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="c.color" variant="tonal" size="44" rounded><VIcon :icon="c.icon" /></VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ c.value }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ c.label }}</div>
              <div class="text-caption text-disabled">{{ c.sub }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Pinned custom metrics -->
    <template v-if="customKpis.length">
      <div class="d-flex align-center justify-space-between mb-2">
        <span class="text-overline text-medium-emphasis">My metrics</span>
        <VBtn size="x-small" variant="text" append-icon="tabler-arrow-right" @click="router.visit(route('crm.metrics'))">Manage metrics</VBtn>
      </div>
      <VRow class="mb-2">
        <VCol v-for="k in customKpis" :key="k.id" cols="12" sm="6" md="3">
          <VCard :color="k.color" variant="tonal">
            <VCardText>
              <div class="text-h5 font-weight-bold">{{ fmtKpi(k) }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ k.name }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <VRow>
      <VCol cols="12" md="7">
        <VCard :title="t('crm_dashboard_index.funnel_title', { pipeline: pipeline?.name ?? t('crm_dashboard_index.no_pipeline') })">
          <VCardText>
            <div v-for="f in funnel" :key="f.stage" class="mb-3">
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="font-weight-medium">{{ f.stage }}</span>
                <span class="text-medium-emphasis">{{ f.count }} · {{ money(f.value) }}</span>
              </div>
              <VProgressLinear :model-value="(f.count / maxCount) * 100" color="primary" height="10" rounded />
            </div>
            <div v-if="!funnel.length" class="text-medium-emphasis text-body-2">{{ t('crm_dashboard_index.no_pipeline_data') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard :title="t('crm_dashboard_index.recent_activity')">
          <VList>
            <VListItem v-for="a in recent" :key="a.id" :title="a.subject" :subtitle="`${a.related} · ${a.when}`">
              <template #prepend>
                <VAvatar size="32" variant="tonal" color="primary">
                  <VIcon icon="tabler-history" size="18" />
                </VAvatar>
              </template>
            </VListItem>
            <VListItem v-if="!recent.length" :title="t('crm_dashboard_index.no_activity')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
