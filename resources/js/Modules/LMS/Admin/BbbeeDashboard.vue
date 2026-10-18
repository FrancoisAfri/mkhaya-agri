<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  scorecard: {
    year: number
    total_spend: number; black_spend: number; black_female_spend: number
    disabled_spend: number; accredited_spend: number
    learnerships_count: number
    spend_pct_of_payroll: number; black_pct_of_spend: number; black_female_pct: number
    earned_points: number; max_points: number; compliance_level: string
    demographic_breakdown: Record<string, number>
    annual_leviable_payroll: number
    sub1_spend_points: number
    sub2_black_points: number
    sub3_black_female_points: number
    sub4_learnership_points: number
    sub5_absorption_points: number
    learnerships_enrolled: number
    learnerships_absorbed: number
    sub_minimum_threshold: number
    sub_minimum_breach: boolean
  }
  byType: { training_type: string; total: number; count: number }[]
  monthly: { month: string; total: number }[]
  years: number[]
  year: number
  payroll: number
}>()

const payrollInput = ref(props.payroll)

function applyPayroll() {
  router.post(route('lms.admin.qcto.bbee.payroll'), {
    plan_year: props.year,
    annual_leviable_payroll: payrollInput.value,
  })
}

function changeYear(y: number) {
  router.get(route('lms.admin.qcto.bbee'), { year: y, payroll: props.payroll }, { preserveState: true })
}

const levelColor: Record<string, string> = {
  'Level 1': 'success', 'Level 2': 'success', 'Level 3': 'info',
  'Level 4': 'warning', 'Level 5': 'warning', 'Non-compliant': 'error',
}

const demoLabels: Record<string, string> = {
  african_male:    t('lms_bbee_scorecard.demographics.african_male'),
  african_female:  t('lms_bbee_scorecard.demographics.african_female'),
  coloured_male:   t('lms_bbee_scorecard.demographics.coloured_male'),
  coloured_female: t('lms_bbee_scorecard.demographics.coloured_female'),
  indian_male:     t('lms_bbee_scorecard.demographics.indian_male'),
  indian_female:   t('lms_bbee_scorecard.demographics.indian_female'),
  white_male:      t('lms_bbee_scorecard.demographics.white_male'),
  white_female:    t('lms_bbee_scorecard.demographics.white_female'),
  disabled:        t('lms_bbee_scorecard.demographics.disabled'),
  other:           t('lms_bbee_scorecard.demographics.other'),
}

const totalDemoSpend = Object.values(props.scorecard.demographic_breakdown).reduce((a, b) => a + b, 0)
function demoPct(v: number) {
  return totalDemoSpend > 0 ? ((v / totalDemoSpend) * 100).toFixed(1) : '0'
}

function fmt(n: number) {
  return 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
}

const subIndicators = [
  { label: t('lms_bbee_scorecard.sub_indicators.si1'), max: 8, points: () => props.scorecard.sub1_spend_points },
  { label: t('lms_bbee_scorecard.sub_indicators.si2'), max: 4, points: () => props.scorecard.sub2_black_points },
  { label: t('lms_bbee_scorecard.sub_indicators.si3'), max: 4, points: () => props.scorecard.sub3_black_female_points },
  { label: t('lms_bbee_scorecard.sub_indicators.si4'), max: 4, points: () => props.scorecard.sub4_learnership_points },
  { label: t('lms_bbee_scorecard.sub_indicators.si5'), max: 5, points: () => props.scorecard.sub5_absorption_points },
]
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-5 flex-wrap">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_bbee_scorecard.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center">
        <span class="text-caption">{{ t('lms_bbee_scorecard.year_label') }}</span>
        <VBtnToggle :model-value="year" variant="outlined" density="compact" @update:model-value="changeYear">
          <VBtn v-for="y in years" :key="y" :value="y">{{ y }}</VBtn>
        </VBtnToggle>
      </div>
    </div>

    <!-- Sub-minimum breach warning -->
    <VAlert v-if="scorecard.sub_minimum_breach" type="error" variant="tonal" class="mb-4" icon="tabler-alert-triangle">
      <strong>{{ t('lms_bbee_scorecard.sub_minimum_breach_title') }}</strong>
      {{ t('lms_bbee_scorecard.sub_minimum_breach_text', { earned: scorecard.earned_points.toFixed(1), max: scorecard.max_points, threshold: scorecard.sub_minimum_threshold.toFixed(1) }) }}
    </VAlert>

    <!-- Score header -->
    <VCard rounded="lg" elevation="0" border class="mb-4">
      <VCardText class="pa-5">
        <VRow align="center">
          <VCol cols="12" md="4" class="text-center">
            <div class="text-h2 font-weight-bold" :class="`text-${levelColor[scorecard.compliance_level]}`">
              {{ scorecard.earned_points.toFixed(1) }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('lms_bbee_scorecard.out_of', { max: scorecard.max_points }) }}</div>
            <VChip :color="levelColor[scorecard.compliance_level]" variant="tonal" class="mt-2">
              {{ scorecard.compliance_level }}
            </VChip>
            <div v-if="scorecard.sub_minimum_breach" class="mt-2">
              <VChip color="error" size="small" variant="outlined" prepend-icon="tabler-alert-triangle">
                {{ t('lms_bbee_scorecard.sub_minimum_badge') }}
              </VChip>
            </div>
          </VCol>
          <VCol cols="12" md="8">
            <VRow dense>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.total_training_spend') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ fmt(scorecard.total_spend) }}</p>
              </VCol>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.black_beneficiaries_spend') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ fmt(scorecard.black_spend) }}
                  <span class="text-caption text-medium-emphasis">({{ scorecard.black_pct_of_spend }}%)</span>
                </p>
              </VCol>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.black_female_spend') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ fmt(scorecard.black_female_spend) }}</p>
              </VCol>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.accredited_training') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ fmt(scorecard.accredited_spend) }}</p>
              </VCol>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.learnerships_enrolled') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">
                  {{ scorecard.learnerships_enrolled }}
                  <span class="text-caption text-medium-emphasis">
                    ({{ scorecard.learnerships_absorbed }} {{ t('lms_bbee_scorecard.absorbed') }})
                  </span>
                </p>
              </VCol>
              <VCol cols="6" sm="4">
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.spend_as_pct_of_payroll') }}</p>
                <p class="text-body-2 font-weight-semibold mb-0">{{ scorecard.spend_pct_of_payroll }}%
                  <span class="text-caption text-medium-emphasis">{{ t('lms_bbee_scorecard.of_target') }}</span>
                </p>
              </VCol>
            </VRow>

            <!-- Payroll input -->
            <div class="d-flex gap-2 align-center mt-3">
              <VTextField
                v-model.number="payrollInput"
                :label="t('lms_bbee_scorecard.payroll_label')"
                type="number" min="0" density="compact"
                variant="outlined" hide-details style="max-width:260px"
              />
              <VBtn size="small" variant="tonal" @click="applyPayroll">{{ t('lms_bbee_scorecard.recalculate') }}</VBtn>
            </div>
            <p class="text-caption text-medium-emphasis mt-1">
              {{ t('lms_bbee_scorecard.payroll_hint') }}
            </p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Sub-indicator breakdown -->
    <VCard rounded="lg" elevation="0" border class="mb-4">
      <VCardText class="pa-4">
        <p class="text-body-1 font-weight-semibold mb-1">{{ t('lms_bbee_scorecard.sub_indicator_title') }}</p>
        <p class="text-caption text-medium-emphasis mb-4">
          {{ t('lms_bbee_scorecard.sub_indicator_threshold', { threshold: scorecard.sub_minimum_threshold.toFixed(1) }) }}
        </p>
        <div v-for="si in subIndicators" :key="si.label" class="mb-3">
          <div class="d-flex align-center justify-space-between mb-1">
            <span class="text-caption">{{ si.label }}</span>
            <span class="text-caption font-weight-semibold">
              {{ t('lms_bbee_scorecard.sub_indicator_pts', { points: si.points().toFixed(1), max: si.max }) }}
            </span>
          </div>
          <VProgressLinear
            :model-value="(si.points() / si.max) * 100"
            :color="si.points() >= si.max * 0.6 ? 'success' : si.points() >= si.max * 0.3 ? 'warning' : 'error'"
            height="8" rounded
          />
        </div>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Demographic breakdown -->
      <VCol cols="12" md="6">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3">{{ t('lms_bbee_scorecard.demographic_title') }}</p>
            <div v-for="(val, key) in scorecard.demographic_breakdown" :key="key" class="mb-2">
              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-caption">{{ demoLabels[key] ?? key }}</span>
                <span class="text-caption font-weight-medium">{{ fmt(val) }} ({{ demoPct(val) }}%)</span>
              </div>
              <VProgressLinear
                :model-value="Number(demoPct(val))"
                :color="key.includes('african') ? 'primary' : key.includes('coloured') ? 'info' : key.includes('indian') ? 'success' : key === 'disabled' ? 'warning' : 'default'"
                height="6" rounded
              />
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- By training type -->
      <VCol cols="12" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3">{{ t('lms_bbee_scorecard.by_type_title') }}</p>
            <div v-for="tr in byType" :key="tr.training_type" class="d-flex justify-space-between py-1">
              <span class="text-caption text-capitalize">{{ tr.training_type.replace('_',' ') }}</span>
              <div class="text-right">
                <p class="text-caption font-weight-semibold mb-0">{{ fmt(tr.total) }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_bbee_scorecard.by_type_people', { count: tr.count }) }}</p>
              </div>
            </div>
            <VAlert v-if="!byType.length" type="info" variant="tonal" density="compact">{{ t('lms_bbee_scorecard.no_data') }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Monthly trend -->
      <VCol cols="12" md="3">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-3">{{ t('lms_bbee_scorecard.monthly_title') }}</p>
            <div v-for="m in monthly" :key="m.month" class="d-flex justify-space-between py-1">
              <span class="text-caption">{{ m.month }}</span>
              <span class="text-caption font-weight-semibold">{{ fmt(m.total) }}</span>
            </div>
            <VAlert v-if="!monthly.length" type="info" variant="tonal" density="compact">{{ t('lms_bbee_scorecard.no_data') }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
