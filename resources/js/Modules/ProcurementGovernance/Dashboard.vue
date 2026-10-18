<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import KpiCard from './Partials/KpiCard.vue'
import NoCompanyNotice from './Partials/NoCompanyNotice.vue'
import StatusChip from './Partials/StatusChip.vue'
import { count, dateTime, humanise, money } from './Partials/format'

const { t } = useI18n()

type Pipeline = { stage: string; count: number; value: number }

const props = defineProps<{
  noCompany?: boolean
  context: { capabilities: Record<string, boolean>; integrations: Record<string, boolean> }
  financialYear?: string
  pipeline?: Pipeline[]
  myApprovals?: any[]
  planning?: any | null
  compliance?: any | null
  workload?: any | null
  evaluations?: any | null
  deviations?: any | null
  recentActivity?: any[]
  can?: { configure: boolean; audit: boolean }
}>()

/** §12: a capability that is off is not rendered at all — an empty panel reads as a broken feature. */
const has = (capability: string) => props.context?.capabilities?.[capability] === true

const overdueApprovals = computed(() => (props.myApprovals ?? []).filter(a => a.overdue).length)

/** The widest stage count sets the bar scale, so the pipeline reads as proportions. */
const pipelinePeak = computed(() =>
  Math.max(1, ...(props.pipeline ?? []).map(s => s.count)))

const complianceTone = computed(() => ({
  COMPLIANT: 'success', ATTENTION: 'warning', NON_COMPLIANT: 'error',
}[props.compliance?.overall as string] ?? 'default') as any)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.dashboard.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('procurement_governance.dashboard.subtitle') }}
          <template v-if="financialYear"> · {{ financialYear }}</template>
        </p>
      </div>
      <Link v-if="can?.configure" href="/procurement-governance/settings">
        <VBtn variant="tonal" prepend-icon="mdi-cog-outline">
          {{ t('procurement_governance.common.configure') }}
        </VBtn>
      </Link>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- What is waiting for this person, first. Everything else on the page is context. -->
      <VRow class="mb-2">
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.dashboard.my_approvals')"
            :value="count(myApprovals?.length ?? 0)"
            :hint="overdueApprovals ? t('procurement_governance.dashboard.overdue_n', { n: overdueApprovals }) : undefined"
            :tone="overdueApprovals ? 'error' : 'default'"
            icon="mdi-check-all"
            colour="cyan"
            href="/procurement-governance/approvals"
          />
        </VCol>
        <VCol v-if="has('tender_evaluation')" cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.dashboard.tenders_evaluating')"
            :value="count(evaluations?.evaluating ?? 0)"
            :hint="evaluations?.overdue ? t('procurement_governance.dashboard.overdue_n', { n: evaluations.overdue }) : undefined"
            :tone="evaluations?.overdue ? 'warning' : 'default'"
            icon="mdi-scale-balance"
            colour="teal"
            href="/procurement-governance/tenders"
          />
        </VCol>
        <VCol v-if="has('deviations')" cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.dashboard.deviations_pending')"
            :value="count(deviations?.pending ?? 0)"
            :hint="deviations?.high_risk ? t('procurement_governance.dashboard.high_risk_n', { n: deviations.high_risk }) : undefined"
            :tone="deviations?.high_risk ? 'warning' : 'default'"
            icon="mdi-alert-decagram-outline"
            colour="orange"
            href="/procurement-governance/deviations"
          />
        </VCol>
        <VCol v-if="has('governance')" cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.dashboard.compliance')"
            :value="humanise(compliance?.overall)"
            :hint="compliance ? t('procurement_governance.dashboard.checks_n', {
              n: (compliance.counts?.NON_COMPLIANT ?? 0) + (compliance.counts?.ATTENTION ?? 0),
            }) : undefined"
            :tone="complianceTone"
            icon="mdi-traffic-light"
            colour="red"
            href="/procurement-governance/compliance"
          />
        </VCol>
      </VRow>

      <VRow>
        <!-- §11: the chain, as it actually stands right now. -->
        <VCol cols="12" lg="7">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.dashboard.pipeline') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <div v-if="!pipeline?.some(s => s.count)" class="text-body-2 text-medium-emphasis py-6 text-center">
                {{ t('procurement_governance.dashboard.pipeline_empty') }}
              </div>
              <div v-for="stage in pipeline" v-else :key="stage.stage" class="mb-3">
                <div class="d-flex align-center justify-space-between mb-1 ga-2">
                  <span class="text-body-2 font-weight-medium">{{ humanise(stage.stage) }}</span>
                  <span class="text-caption text-medium-emphasis">
                    {{ count(stage.count) }} · {{ money(stage.value) }}
                  </span>
                </div>
                <VProgressLinear
                  :model-value="(stage.count / pipelinePeak) * 100"
                  height="8"
                  rounded
                  color="primary"
                  bg-color="rgba(var(--v-theme-primary), 0.08)"
                  :aria-label="`${humanise(stage.stage)}: ${stage.count}`"
                />
              </div>
            </VCardText>
          </VCard>

          <VCard v-if="has('planning') && planning" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.dashboard.plan_status') }}
              <Link href="/procurement-governance/planning/dashboard" class="text-body-2 text-primary text-decoration-none">
                {{ t('procurement_governance.common.view_all') }}
              </Link>
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol v-for="(value, key) in planning.counts" :key="key" cols="6" sm="4" md="3">
                  <div class="pa-2">
                    <div class="text-h6 font-weight-bold" :class="{ 'text-warning': key === 'delayed' && value > 0 }">
                      {{ count(value as number) }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ t(`procurement_governance.planning.counts.${key}`) }}
                    </div>
                  </div>
                </VCol>
              </VRow>
              <VDivider class="my-3" />
              <div class="d-flex flex-wrap ga-4">
                <div>
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.planning.planned_value') }}</div>
                  <div class="text-body-1 font-weight-bold">{{ money(planning.value?.planned) }}</div>
                </div>
                <div>
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.planning.actual_value') }}</div>
                  <div class="text-body-1 font-weight-bold">{{ money(planning.value?.actual) }}</div>
                </div>
                <div v-if="planning.value?.utilisation_pct !== null">
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.planning.utilisation') }}</div>
                  <div class="text-body-1 font-weight-bold">{{ planning.value.utilisation_pct }}%</div>
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="5">
          <VCard v-if="myApprovals?.length" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.dashboard.awaiting_you') }}
            </VCardTitle>
            <VList density="compact" class="py-0">
              <VListItem
                v-for="item in myApprovals.slice(0, 8)"
                :key="item.step_id"
                :href="`/procurement-governance/approvals/${item.uuid}`"
                class="procgov-list-link"
              >
                <template #prepend>
                  <VIcon :icon="item.overdue ? 'mdi-alert-outline' : 'mdi-clock-outline'"
                         :color="item.overdue ? 'error' : 'warning'" size="20" />
                </template>
                <VListItemTitle class="text-body-2 font-weight-medium">
                  {{ item.subject_label || item.subject_reference }}
                </VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ item.name }}
                  <template v-if="item.amount"> · {{ money(item.amount) }}</template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCard>

          <VCard v-if="has('workload') && workload" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.dashboard.capacity') }}
              <Link href="/procurement-governance/workload" class="text-body-2 text-primary text-decoration-none">
                {{ t('procurement_governance.common.view_all') }}
              </Link>
            </VCardTitle>
            <VCardText class="pt-0">
              <div class="d-flex flex-wrap ga-2">
                <StatusChip status="OVERLOADED" :label="t('procurement_governance.workload.overloaded_n', { n: workload.overloaded })" />
                <StatusChip status="HIGH" :label="t('procurement_governance.workload.high_n', { n: workload.high })" />
                <StatusChip status="NORMAL" :label="t('procurement_governance.workload.normal_n', { n: workload.normal })" />
              </div>
              <div class="text-caption text-medium-emphasis mt-3">
                {{ t('procurement_governance.workload.summary', {
                  officers: workload.officers, active: workload.total_active, overdue: workload.total_overdue,
                }) }}
              </div>
            </VCardText>
          </VCard>

          <VCard v-if="recentActivity?.length" elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.dashboard.recent_activity') }}
            </VCardTitle>
            <VList density="compact" class="py-0">
              <VListItem v-for="(event, i) in recentActivity" :key="i">
                <VListItemTitle class="text-body-2">{{ event.summary }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ dateTime(event.occurred_at) }}
                  <template v-if="event.actor_label"> · {{ event.actor_label }}</template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.procgov-list-link { cursor: pointer; }
</style>
