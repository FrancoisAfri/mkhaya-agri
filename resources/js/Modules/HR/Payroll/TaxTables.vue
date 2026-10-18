<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

interface Bracket { lower: number; upper: number | null; rate: number; base: number }
interface TaxYear {
  tax_year: number; starts_on: string | null; ends_on: string | null; country: string
  source: string; is_provisional: boolean; confirmed_at: string | null
  brackets: Bracket[]
  rebates: { primary: number; secondary: number; tertiary: number }
  thresholds: { under_65: number; age_65_74: number; age_75: number }
  medical_credits: { main: number; first_dependant: number; additional: number }
  uif: { rate: number; monthly_ceiling: number }
  sdl: { rate: number; annual_threshold: number }
}

const props = defineProps<{ module: string; country: string; countries: string[]; years: TaxYear[] }>()

const selectedCountry = ref(props.country)
const panel = ref<number[]>(props.years.length ? [0] : [])

const backLink = computed(() =>
  props.module === 'bureau' ? ziggyRoute('payroll.bureau.dashboard') : ziggyRoute('payroll.hub.insights'))

function money(n: number): string {
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(n || 0)
}
function pct(n: number): string {
  return `${(Math.round((n || 0) * 10000) / 100)}%`
}
function fmtDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' })
}
function bracketIncome(b: Bracket): string {
  if (b.upper === null) return `${money(b.lower)} ${t('tax_tables.and_above')}`
  if (b.lower === 0) return `${money(0)} – ${money(b.upper)}`
  return `${money(b.lower)} – ${money(b.upper)}`
}
function bracketTaxFormula(b: Bracket): string {
  if (b.lower === 0) return `${pct(b.rate)} ${t('tax_tables.of_taxable_income')}`
  return `${money(b.base)} + ${pct(b.rate)} ${t('tax_tables.of_amount_above')} ${money(b.lower)}`
}

function changeCountry(c: string) {
  const name = props.module === 'bureau' ? 'payroll.bureau.tax-tables' : 'payroll.tax-tables'
  router.get(ziggyRoute(name), { country: c }, { preserveState: false })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center"><VCol cols="12" lg="10">
      <!-- Header -->
      <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
        <div>
          <div class="text-overline text-medium-emphasis">
            {{ module === 'bureau' ? t('tax_tables.bureau_eyebrow') : t('tax_tables.payroll_eyebrow') }}
          </div>
          <h4 class="text-h4">{{ t('tax_tables.page_title') }}</h4>
          <p class="text-medium-emphasis mb-0" style="max-width: 680px">{{ t('tax_tables.subtitle') }}</p>
        </div>
        <div class="d-flex align-center ga-3">
          <VSelect
            v-if="countries.length > 1"
            v-model="selectedCountry" :items="countries" density="comfortable" variant="outlined"
            hide-details style="min-width: 120px" :label="t('tax_tables.country')"
            @update:model-value="changeCountry" />
          <VBtn variant="tonal" prepend-icon="tabler-arrow-left" :href="backLink">{{ t('tax_tables.back') }}</VBtn>
        </div>
      </div>

      <VAlert v-if="!years.length" type="info" variant="tonal">{{ t('tax_tables.empty') }}</VAlert>

      <VExpansionPanels v-model="panel" variant="accordion" multiple>
        <VExpansionPanel v-for="(y, i) in years" :key="y.tax_year" :value="i">
          <VExpansionPanelTitle>
            <div class="d-flex align-center flex-wrap ga-3" style="width: 100%">
              <VIcon icon="tabler-calendar-stats" color="primary" />
              <span class="text-h6">{{ t('tax_tables.year_of_assessment') }} {{ y.tax_year }}</span>
              <span class="text-caption text-medium-emphasis">{{ fmtDate(y.starts_on) }} – {{ fmtDate(y.ends_on) }}</span>
              <VChip v-if="y.is_provisional" size="small" color="warning" variant="tonal" prepend-icon="tabler-alert-triangle">
                {{ t('tax_tables.provisional') }}
              </VChip>
              <VChip v-else size="small" color="success" variant="tonal" prepend-icon="tabler-circle-check">
                {{ t('tax_tables.confirmed') }}
              </VChip>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VAlert v-if="y.is_provisional" type="warning" variant="tonal" density="compact" class="mb-4">
              {{ t('tax_tables.provisional_notice') }}
            </VAlert>

            <!-- Brackets -->
            <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ t('tax_tables.brackets_title') }}</h6>
            <VTable density="comfortable" class="mb-6 border rounded">
              <thead>
                <tr>
                  <th>{{ t('tax_tables.taxable_income') }}</th>
                  <th>{{ t('tax_tables.rates_of_tax') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(b, bi) in y.brackets" :key="bi">
                  <td class="text-no-wrap">{{ bracketIncome(b) }}</td>
                  <td>{{ bracketTaxFormula(b) }}</td>
                </tr>
              </tbody>
            </VTable>

            <VRow>
              <!-- Rebates -->
              <VCol cols="12" md="6">
                <VCard variant="tonal" class="h-100"><VCardText>
                  <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('tax_tables.rebates_title') }}</div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.rebate_primary') }}</span><strong>{{ money(y.rebates.primary) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.rebate_secondary') }}</span><strong>{{ money(y.rebates.secondary) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.rebate_tertiary') }}</span><strong>{{ money(y.rebates.tertiary) }}</strong></div>
                </VCardText></VCard>
              </VCol>
              <!-- Thresholds -->
              <VCol cols="12" md="6">
                <VCard variant="tonal" class="h-100"><VCardText>
                  <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('tax_tables.thresholds_title') }}</div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.threshold_under_65') }}</span><strong>{{ money(y.thresholds.under_65) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.threshold_65_74') }}</span><strong>{{ money(y.thresholds.age_65_74) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.threshold_75') }}</span><strong>{{ money(y.thresholds.age_75) }}</strong></div>
                </VCardText></VCard>
              </VCol>
              <!-- Medical credits -->
              <VCol cols="12" md="6">
                <VCard variant="tonal" class="h-100"><VCardText>
                  <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('tax_tables.medical_title') }}</div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.medical_main') }}</span><strong>{{ money(y.medical_credits.main) }}{{ t('tax_tables.per_month') }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.medical_first') }}</span><strong>{{ money(y.medical_credits.first_dependant) }}{{ t('tax_tables.per_month') }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.medical_additional') }}</span><strong>{{ money(y.medical_credits.additional) }}{{ t('tax_tables.per_month') }}</strong></div>
                </VCardText></VCard>
              </VCol>
              <!-- UIF / SDL -->
              <VCol cols="12" md="6">
                <VCard variant="tonal" class="h-100"><VCardText>
                  <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('tax_tables.contributions_title') }}</div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.uif_rate') }}</span><strong>{{ pct(y.uif.rate) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.uif_ceiling') }}</span><strong>{{ money(y.uif.monthly_ceiling) }}{{ t('tax_tables.per_month') }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.sdl_rate') }}</span><strong>{{ pct(y.sdl.rate) }}</strong></div>
                  <div class="d-flex justify-space-between py-1"><span>{{ t('tax_tables.sdl_threshold') }}</span><strong>{{ money(y.sdl.annual_threshold) }}</strong></div>
                </VCardText></VCard>
              </VCol>
            </VRow>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </VCol></VRow>
  </VContainer>
</template>
