<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface WeeklyTrend    { week: string; attendance_rate: number; late_count: number; overtime: number }
interface AtRiskEmployee { employee: string; department: string; overall_risk: string; risk_color: string; composite_score: number; risk_factors: string[] }
interface OpenViolation  { employee: string; department: string; rule: string; severity: string; status: string; blocked: boolean; days_open: number }
interface DeptEfficiency { department: string; efficiency_score: number; headcount: number; is_overworked: boolean; avg_hours: number }
interface Prediction     { type: string; scope: string; department: string | null; risk_level: string; risk_color: string; confidence: number; predicted_for: string }

interface Props {
  today:               string
  totalActive:         number
  todayKpis:           { present: number; absent: number; late: number; wfh: number; overtime: number }
  weeklyTrend:         WeeklyTrend[]
  burnoutSummary:      { critical: number; high: number; medium: number }
  atRiskEmployees:     AtRiskEmployee[]
  openViolations:      OpenViolation[]
  deptEfficiency:      DeptEfficiency[]
  productivitySummary: { high: number; average: number; low: number; at_risk: number }
  predictions:         Prediction[]
}

const props = defineProps<Props>()

const presenceRate = computed(() => props.totalActive > 0 ? Math.round((props.todayKpis.present / props.totalActive) * 100) : 0)

function riskColor(r: string) { return ({ critical: 'error', high: 'warning', medium: 'orange', low: 'success' } as Record<string, string>)[r] ?? 'default' }
function severityColor(s: string) { return ({ critical: 'error', high: 'warning', medium: 'orange', low: 'info' } as Record<string, string>)[s] ?? 'secondary' }
function efficiencyColor(score: number) { if (score >= 80) return 'success'; if (score >= 60) return 'info'; if (score >= 40) return 'warning'; return 'error' }

const aiLoading = ref(false)
const aiSummary = ref('')

async function generateAiSummary() {
  aiLoading.value = true
  try {
    const res = await fetch('/attendance/executive/ai-summary', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
      body: JSON.stringify({ today: props.today }),
    })
    const json = await res.json()
    aiSummary.value = json.summary ?? ''
  } catch { aiSummary.value = t('attendance_executive.unable_to_generate') }
  finally { aiLoading.value = false }
}
</script>

<template>
  <VRow>

    <VCol cols="12">
      <VCard class="exec-ribbon">
        <VCardText class="py-6 px-6">
          <div class="d-flex align-center justify-space-between flex-wrap gap-4">
            <div>
              <div class="text-overline text-white opacity-80">{{ t('attendance_executive.breadcrumb_module') }}</div>
              <h4 class="text-h4 text-white mb-1">{{ t('attendance_executive.title') }}</h4>
              <p class="mb-0 text-white opacity-80">{{ t('attendance_executive.subtitle') }}</p>
            </div>
            <div class="d-flex flex-column align-end gap-2">
              <VChip color="white" class="font-weight-bold" label prepend-icon="tabler-calendar-today">{{ today }} {{ t('attendance_executive.today_label') }}</VChip>
              <VChip color="white" class="font-weight-bold" label prepend-icon="tabler-account-group">{{ totalActive }} {{ t('attendance_executive.active_employees') }}</VChip>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-account-check" color="success" size="32" class="mb-2" /><div class="text-h4 font-weight-bold text-success">{{ todayKpis.present }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.present_today') }}</div><VProgressLinear :model-value="presenceRate" color="success" height="4" rounded class="mt-2" /><div class="text-caption mt-1">{{ presenceRate }}% {{ t('attendance_executive.rate_label') }}</div></VCardText></VCard>
    </VCol>
    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-account-off" color="error" size="32" class="mb-2" /><div class="text-h4 font-weight-bold text-error">{{ todayKpis.absent }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.absent_today') }}</div></VCardText></VCard>
    </VCol>
    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-alarm-check" color="warning" size="32" class="mb-2" /><div class="text-h4 font-weight-bold text-warning">{{ todayKpis.late }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.late_arrivals') }}</div></VCardText></VCard>
    </VCol>
    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-home-account" color="info" size="32" class="mb-2" /><div class="text-h4 font-weight-bold text-info">{{ todayKpis.wfh }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.working_from_home') }}</div></VCardText></VCard>
    </VCol>
    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-clock-plus-outline" color="orange" size="32" class="mb-2" /><div class="text-h4 font-weight-bold" style="color: #f57c00">{{ todayKpis.overtime }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.on_overtime') }}</div></VCardText></VCard>
    </VCol>
    <VCol cols="6" sm="4" md="2">
      <VCard><VCardText class="text-center pa-4"><VIcon icon="tabler-fire-alert" color="error" size="32" class="mb-2" /><div class="text-h4 font-weight-bold text-error">{{ burnoutSummary.critical + burnoutSummary.high }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.burnout_risk') }}</div><div class="text-caption mt-1">{{ t('attendance_executive.burnout_detail', { critical: burnoutSummary.critical, high: burnoutSummary.high }) }}</div></VCardText></VCard>
    </VCol>

    <VCol cols="12" md="7">
      <VCard height="100%">
        <VCardTitle class="pa-4 pb-0"><VIcon icon="tabler-chart-line" class="me-2" />{{ t('attendance_executive.weekly_trend_title') }}</VCardTitle>
        <VCardText>
          <div v-if="weeklyTrend.length === 0" class="text-center text-medium-emphasis py-8"><VIcon icon="tabler-chart-timeline-variant" size="48" class="mb-2 d-block mx-auto" />{{ t('attendance_executive.no_trend_data') }}</div>
          <div v-else>
            <table class="trend-table w-100">
              <thead><tr><th>{{ t('attendance_executive.table_week') }}</th><th>{{ t('attendance_executive.table_attendance_pct') }}</th><th>{{ t('attendance_executive.table_late') }}</th><th>{{ t('attendance_executive.table_overtime_h') }}</th></tr></thead>
              <tbody>
                <tr v-for="w in weeklyTrend" :key="w.week">
                  <td class="text-caption">{{ w.week }}</td>
                  <td><div class="d-flex align-center gap-2"><VProgressLinear :model-value="w.attendance_rate" :color="w.attendance_rate >= 90 ? 'success' : w.attendance_rate >= 75 ? 'warning' : 'error'" height="6" rounded style="min-width: 80px" /><span class="text-caption font-weight-bold">{{ w.attendance_rate }}%</span></div></td>
                  <td><VChip size="x-small" :color="w.late_count > 5 ? 'error' : w.late_count > 2 ? 'warning' : 'success'" variant="tonal">{{ w.late_count }}</VChip></td>
                  <td class="text-caption">{{ w.overtime }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="5">
      <VRow>
        <VCol cols="12">
          <VCard>
            <VCardTitle class="pa-4 pb-2"><VIcon icon="tabler-chart-bar" class="me-2" />{{ t('attendance_executive.productivity_tiers_title') }}</VCardTitle>
            <VCardText class="pb-4">
              <div class="d-flex flex-wrap gap-3">
                <div class="text-center flex-grow-1"><div class="text-h5 font-weight-bold text-success">{{ productivitySummary.high }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.high') }}</div></div>
                <div class="text-center flex-grow-1"><div class="text-h5 font-weight-bold text-info">{{ productivitySummary.average }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.average') }}</div></div>
                <div class="text-center flex-grow-1"><div class="text-h5 font-weight-bold text-warning">{{ productivitySummary.low }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.low') }}</div></div>
                <div class="text-center flex-grow-1"><div class="text-h5 font-weight-bold text-error">{{ productivitySummary.at_risk }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_executive.at_risk') }}</div></div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12">
          <VCard>
            <VCardTitle class="pa-4 pb-2"><VIcon icon="tabler-crystal-ball" class="me-2" />{{ t('attendance_executive.active_predictions_title') }}</VCardTitle>
            <VCardText class="pa-0">
              <div v-if="predictions.length === 0" class="px-4 py-4 text-medium-emphasis text-caption">{{ t('attendance_executive.no_predictions') }}</div>
              <VList density="compact">
                <VListItem v-for="p in predictions" :key="`${p.type}-${p.predicted_for}`" class="border-b">
                  <template #prepend><VChip size="x-small" :color="riskColor(p.risk_level)" variant="tonal" class="me-2">{{ p.risk_level }}</VChip></template>
                  <VListItemTitle class="text-caption">{{ p.type }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">{{ p.department ?? p.scope }} · {{ p.predicted_for }} · {{ p.confidence }}% {{ t('attendance_executive.conf_label') }}</VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VCol>

    <VCol cols="12" md="6">
      <VCard>
        <VCardTitle class="pa-4 pb-0"><VIcon icon="tabler-fire-alert" color="error" class="me-2" />{{ t('attendance_executive.at_risk_employees_title') }}</VCardTitle>
        <VCardText class="pa-0">
          <div v-if="atRiskEmployees.length === 0" class="pa-6 text-center text-medium-emphasis"><VIcon icon="tabler-check-circle" color="success" size="40" class="mb-2 d-block mx-auto" />{{ t('attendance_executive.no_at_risk') }}</div>
          <VList density="compact" v-else>
            <VListItem v-for="e in atRiskEmployees" :key="e.employee" class="border-b">
              <template #prepend><VChip size="x-small" :color="riskColor(e.overall_risk)" variant="tonal">{{ e.overall_risk }}</VChip></template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ e.employee }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ e.department }} · {{ t('attendance_executive.score_prefix') }} {{ e.composite_score }}</VListItemSubtitle>
              <template #append><div class="d-flex flex-wrap gap-1"><VChip v-for="f in (e.risk_factors ?? []).slice(0, 2)" :key="f" size="x-small" variant="outlined" color="warning">{{ f }}</VChip></div></template>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="6">
      <VCard>
        <VCardTitle class="pa-4 pb-0"><VIcon icon="tabler-scale-balance" color="deep-orange" class="me-2" />{{ t('attendance_executive.open_violations_title') }}</VCardTitle>
        <VCardText class="pa-0">
          <div v-if="openViolations.length === 0" class="pa-6 text-center text-medium-emphasis"><VIcon icon="tabler-check-circle" color="success" size="40" class="mb-2 d-block mx-auto" />{{ t('attendance_executive.no_violations') }}</div>
          <VList density="compact" v-else>
            <VListItem v-for="v in openViolations" :key="`${v.employee}-${v.rule}`" class="border-b">
              <template #prepend><VChip size="x-small" :color="severityColor(v.severity)" variant="tonal">{{ v.severity }}</VChip></template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ v.employee }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ v.rule }} · {{ v.days_open }}{{ t('attendance_executive.days_open_suffix') }}</VListItemSubtitle>
              <template #append><div class="d-flex gap-1"><VChip v-if="v.blocked" size="x-small" color="error" variant="tonal">{{ t('attendance_executive.blocked') }}</VChip><VChip size="x-small" :color="v.status === 'escalated' ? 'error' : 'warning'" variant="tonal">{{ v.status }}</VChip></div></template>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VCardTitle class="pa-4 pb-0"><VIcon icon="tabler-office-building" class="me-2" />{{ t('attendance_executive.dept_efficiency_title') }}</VCardTitle>
        <VCardText>
          <div v-if="deptEfficiency.length === 0" class="text-center text-medium-emphasis py-6"><VIcon icon="tabler-chart-bar" size="48" class="mb-2 d-block mx-auto" />{{ t('attendance_executive.no_dept_data') }}</div>
          <VRow v-else>
            <VCol v-for="d in deptEfficiency" :key="d.department" cols="12" sm="6" md="4" lg="3">
              <VCard variant="outlined">
                <VCardText class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="text-body-2 font-weight-medium text-truncate">{{ d.department }}</span>
                    <VChip v-if="d.is_overworked" size="x-small" color="error" variant="tonal">{{ t('attendance_executive.overworked') }}</VChip>
                  </div>
                  <VProgressLinear :model-value="d.efficiency_score" :color="efficiencyColor(d.efficiency_score)" height="8" rounded class="mb-2" />
                  <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                    <span>{{ t('attendance_executive.score_prefix') }} <strong>{{ d.efficiency_score }}</strong></span>
                    <span>{{ d.headcount }} {{ t('attendance_executive.staff_count') }} · {{ d.avg_hours }}h {{ t('attendance_executive.avg_hours') }}</span>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VCardTitle class="pa-4 pb-0"><VIcon icon="tabler-robot" color="primary" class="me-2" />{{ t('attendance_executive.ai_summary_title') }}</VCardTitle>
        <VCardText>
          <div v-if="!aiSummary && !aiLoading" class="text-center py-6">
            <p class="text-medium-emphasis mb-4">{{ t('attendance_executive.ai_prompt') }}</p>
            <VBtn color="primary" prepend-icon="tabler-robot" :loading="aiLoading" @click="generateAiSummary">{{ t('attendance_executive.generate_summary') }}</VBtn>
          </div>
          <div v-if="aiLoading" class="text-center py-6"><VProgressCircular indeterminate color="primary" size="48" class="mb-3" /><div class="text-medium-emphasis">{{ t('attendance_executive.generating') }}</div></div>
          <div v-if="aiSummary && !aiLoading">
            <VAlert color="primary" variant="tonal" class="mb-4"><div style="white-space: pre-wrap; line-height: 1.7">{{ aiSummary }}</div></VAlert>
            <VBtn variant="tonal" prepend-icon="tabler-refresh" @click="generateAiSummary">{{ t('attendance_executive.regenerate') }}</VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

  </VRow>
</template>

<style scoped lang="scss">
.exec-ribbon { border: 0; overflow: hidden; background: linear-gradient(135deg, #1a237e 0%, #283593 50%, #1565c0 100%); }
.trend-table { border-collapse: collapse; width: 100%; th { font-size: 0.75rem; font-weight: 600; text-align: left; padding: 4px 8px; color: rgba(var(--v-theme-on-surface), 0.6); border-bottom: 1px solid rgba(var(--v-border-color), 0.3); } td { padding: 6px 8px; border-bottom: 1px solid rgba(var(--v-border-color), 0.15); vertical-align: middle; } tr:hover td { background: rgba(var(--v-theme-primary), 0.04); } }
</style>
