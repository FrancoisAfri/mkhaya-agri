<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  report: any
  month: string
  months: string[]
}>()

function pick(month: string) {
  router.get(route('suppliers.monitoring.report'), { month }, { preserveState: true, replace: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('supplier_management.monitoring.eyebrow') }}</div>
        <h2 class="text-h5 mb-0">{{ t('supplier_management.monitoring.report_title') }}</h2>
        <div class="text-caption text-medium-emphasis">
          {{ report.period.from }} — {{ report.period.to }}
        </div>
      </div>
      <VSelect :model-value="month" :items="months" density="compact" variant="outlined" hide-details
               style="max-width: 180px" @update:model-value="pick" />
    </div>

    <!-- Coverage first, and honestly: swept in the period, not "on the books" -->
    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.active_suppliers') }}</div>
          <div class="text-h6">{{ report.coverage.active_suppliers }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.monitored') }}</div>
          <div class="text-h6">{{ report.coverage.monitored }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.in_this_period') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.never_monitored') }}</div>
          <div class="text-h6" :class="report.coverage.never_monitored ? 'text-warning' : ''">
            {{ report.coverage.never_monitored }}
          </div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.monitoring.unreviewed_alerts') }}</div>
          <div class="text-h6" :class="report.alerts.unreviewed ? 'text-error' : ''">{{ report.alerts.unreviewed }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VAlert v-if="report.coverage.runs_with_no_source" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('supplier_management.monitoring.runs_no_source', { n: report.coverage.runs_with_no_source }) }}
    </VAlert>

    <VRow>
      <VCol cols="12" md="6">
        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.monitoring.alerts') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between text-body-2"><span>{{ t('supplier_management.monitoring.critical') }}</span><span>{{ report.alerts.critical }}</span></div>
            <div class="d-flex justify-space-between text-body-2"><span>{{ t('supplier_management.monitoring.high') }}</span><span>{{ report.alerts.high }}</span></div>
            <div class="d-flex justify-space-between text-body-2"><span>{{ t('supplier_management.monitoring.medium') }}</span><span>{{ report.alerts.medium }}</span></div>
            <div class="d-flex justify-space-between text-body-2"><span>{{ t('supplier_management.monitoring.low') }}</span><span>{{ report.alerts.low }}</span></div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between text-body-2 font-weight-medium">
              <span>{{ t('supplier_management.monitoring.unreviewed_alerts') }}</span><span>{{ report.alerts.unreviewed }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 text-medium-emphasis">
              <span>{{ t('supplier_management.monitoring.false_positives') }}</span><span>{{ report.alerts.false_positives }}</span>
            </div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.monitoring.risk_movement') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between text-body-2">
              <span class="text-error">{{ t('supplier_management.monitoring.increased') }}</span><span>{{ report.risk.increased }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2">
              <span class="text-success">{{ t('supplier_management.monitoring.decreased') }}</span><span>{{ report.risk.decreased }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 text-medium-emphasis">
              <span>{{ t('supplier_management.monitoring.unchanged') }}</span><span>{{ report.risk.unchanged }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.monitoring.needs_attention') }}</VCardTitle>
          <VCardText>
            <!-- Named, not counted: a count of nine tells nobody which nine -->
            <div v-if="!report.attention.suppliers.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.monitoring.nothing_outstanding') }}
            </div>
            <div v-for="s in report.attention.suppliers" :key="s.supplier_id" class="mb-2">
              <div class="d-flex align-center gap-2">
                <VChip size="x-small" variant="tonal" :color="s.worst === 'critical' ? 'error' : 'warning'">
                  {{ s.worst }}
                </VChip>
                <a :href="`/suppliers/${s.supplier_id}/passport`" class="font-weight-medium">{{ s.name }}</a>
              </div>
              <div class="text-caption text-medium-emphasis" style="max-width: 60ch">{{ s.summary }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
