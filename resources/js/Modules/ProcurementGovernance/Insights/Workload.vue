<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import KpiCard from '../Partials/KpiCard.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import StatusChip from '../Partials/StatusChip.vue'
import { count, dateTime, humanise, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  dashboard: any | null
  alerts: any[]
  trend: any[]
  noCompany?: boolean
  can: { manage: boolean }
}>()

const acknowledging = ref<any | null>(null)

const officers = computed(() => props.dashboard?.officers ?? [])
const summary = computed(() => props.dashboard?.summary)
const thresholds = computed(() => props.dashboard?.thresholds)

/** Bar length relative to the overloaded threshold, so the bands are visible at a glance. */
const scaleMax = computed(() =>
  Math.max(thresholds.value?.overloaded ?? 15, ...officers.value.map((o: any) => o.capacity_index ?? 0)))

function updateAlert(uuid: string, status: string) {
  router.post(`/procurement-governance/workload/alerts/${uuid}`, { status }, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.workload.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.workload.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany && dashboard">
      <!-- §4.3: the system says who is overloaded and what could move, rather than leaving a
           manager to derive it from a table. -->
      <VCard
        v-for="alert in alerts" :key="alert.uuid"
        elevation="0" border rounded="lg" class="mb-3"
        :class="alert.severity === 'OVERLOADED' ? 'procgov-alert-critical' : 'procgov-alert-high'"
      >
        <VCardText class="d-flex flex-wrap align-start ga-3 pa-4">
          <VIcon
            :icon="alert.severity === 'OVERLOADED' ? 'mdi-account-alert' : 'mdi-account-clock'"
            :color="alert.severity === 'OVERLOADED' ? 'error' : 'warning'" size="24"
          />
          <div class="min-width-0 flex-grow-1">
            <div class="text-body-2 font-weight-medium mb-1">{{ alert.narrative }}</div>
            <div class="text-caption text-medium-emphasis mb-2">{{ dateTime(alert.detected_at) }}</div>

            <div v-if="alert.suggested_reassignments?.length">
              <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1">
                {{ t('procurement_governance.workload.suggested') }}
              </div>
              <div class="d-flex flex-wrap ga-1">
                <VChip
                  v-for="(suggestion, i) in alert.suggested_reassignments" :key="i"
                  size="small" variant="tonal" label
                  :href="`/procurement-governance/records/${suggestion.record_uuid}`"
                >
                  {{ suggestion.reference }} → {{ suggestion.to_officer ?? '—' }}
                </VChip>
              </div>
            </div>
          </div>
          <div v-if="can.manage && alert.status === 'OPEN'" class="d-flex ga-1">
            <VBtn size="small" variant="text" @click="updateAlert(alert.uuid, 'ACTIONED')">
              {{ t('procurement_governance.workload.actioned') }}
            </VBtn>
            <VBtn size="small" variant="text" @click="updateAlert(alert.uuid, 'DISMISSED')">
              {{ t('procurement_governance.workload.dismiss') }}
            </VBtn>
          </div>
          <StatusChip v-else :status="alert.status" />
        </VCardText>
      </VCard>

      <VRow class="mb-2">
        <VCol cols="6" md="3">
          <KpiCard
            :label="t('procurement_governance.workload.officers')"
            :value="count(summary.officers)" icon="mdi-account-multiple-outline" colour="indigo"
          />
        </VCol>
        <VCol cols="6" md="3">
          <KpiCard
            :label="t('procurement_governance.workload.overloaded')"
            :value="count(summary.overloaded)" :tone="summary.overloaded ? 'error' : 'default'"
            icon="mdi-account-alert-outline" colour="red"
          />
        </VCol>
        <VCol cols="6" md="3">
          <KpiCard
            :label="t('procurement_governance.workload.active_work')"
            :value="count(summary.total_active)" icon="mdi-briefcase-outline" colour="blue"
          />
        </VCol>
        <VCol cols="6" md="3">
          <KpiCard
            :label="t('procurement_governance.workload.overdue_actions')"
            :value="count(summary.total_overdue)" :tone="summary.total_overdue ? 'warning' : 'default'"
            icon="mdi-clock-alert-outline" colour="orange"
          />
        </VCol>
      </VRow>

      <!-- §4.2, with exactly the columns the brief names. -->
      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ t('procurement_governance.workload.by_officer') }}
        </VCardTitle>
        <VCardSubtitle v-if="thresholds" class="text-caption pb-2">
          {{ t('procurement_governance.workload.thresholds', {
            high: thresholds.high, overloaded: thresholds.overloaded,
          }) }}
        </VCardSubtitle>

        <div v-if="!officers.length" class="text-center py-10 px-4 text-medium-emphasis">
          <VIcon icon="mdi-account-off-outline" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('procurement_governance.workload.no_officers') }}</div>
        </div>

        <div v-else class="procgov-scroll">
          <VTable density="comfortable">
            <thead>
              <tr>
                <th scope="col">{{ t('procurement_governance.common.officer') }}</th>
                <th scope="col" class="text-end">{{ t('procurement_governance.workload.active') }}</th>
                <th scope="col" class="text-end">{{ t('procurement_governance.workload.pending') }}</th>
                <th scope="col" class="text-end">{{ t('procurement_governance.workload.overdue') }}</th>
                <th scope="col" class="text-end d-none d-md-table-cell">{{ t('procurement_governance.workload.completed') }}</th>
                <th scope="col" class="text-end d-none d-lg-table-cell">{{ t('procurement_governance.workload.tenders') }}</th>
                <th scope="col" class="text-end d-none d-lg-table-cell">{{ t('procurement_governance.workload.avg_time') }}</th>
                <th scope="col" style="min-width: 180px">{{ t('procurement_governance.workload.capacity') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="officer in officers" :key="officer.officer_id">
                <td class="text-body-2">{{ officer.officer_name }}</td>
                <td class="text-end text-body-2">{{ count(officer.active_procurements) }}</td>
                <td class="text-end text-body-2">{{ count(officer.pending_actions) }}</td>
                <td class="text-end text-body-2" :class="{ 'text-error font-weight-medium': officer.overdue_actions > 0 }">
                  {{ count(officer.overdue_actions) }}
                </td>
                <td class="text-end text-body-2 d-none d-md-table-cell">{{ count(officer.completed_procurements) }}</td>
                <td class="text-end text-body-2 d-none d-lg-table-cell">{{ count(officer.tenders_managed) }}</td>
                <td class="text-end text-body-2 d-none d-lg-table-cell">
                  {{ officer.avg_processing_hours === null
                    ? '—'
                    : t('procurement_governance.workload.hours', { n: officer.avg_processing_hours }) }}
                </td>
                <td>
                  <div class="d-flex align-center ga-2">
                    <VProgressLinear
                      :model-value="Math.min(100, (officer.capacity_index / scaleMax) * 100)"
                      :color="{
                        OVERLOADED: 'error', HIGH: 'warning', NORMAL: 'success', UNDER_UTILISED: 'grey',
                      }[officer.capacity_status as string]"
                      height="8" rounded style="min-inline-size: 70px"
                      :aria-label="humanise(officer.capacity_status)"
                    />
                    <StatusChip :status="officer.capacity_status" size="x-small" />
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </VCard>

      <VCard v-if="trend.length" elevation="0" border rounded="lg" class="mt-4">
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ t('procurement_governance.workload.trend') }}
        </VCardTitle>
        <VCardSubtitle class="text-caption pb-2">
          {{ t('procurement_governance.workload.trend_hint') }}
        </VCardSubtitle>
        <VCardText>
          <!-- A sparkline rather than a chart library: it is one series over 90 days, and the
               table below already carries the exact figures. -->
          <VSparkline
            :model-value="trend.map(t => t.avg_index)"
            :labels="[]" auto-draw line-width="1.5" smooth
            color="primary" height="70"
          />
          <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1">
            <span>{{ trend[0]?.date }}</span>
            <span>{{ trend[trend.length - 1]?.date }}</span>
          </div>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-alert-critical { border-inline-start: 4px solid rgb(var(--v-theme-error)) !important; }
.procgov-alert-high { border-inline-start: 4px solid rgb(var(--v-theme-warning)) !important; }
</style>
