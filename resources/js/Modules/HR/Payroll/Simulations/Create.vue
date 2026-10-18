<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const form = useForm({
  name: '',
  simulation_type: '' as string,
  notes: '',
  increase_mode: 'percentage' as string,
  percentage: null as number | null,
  fixed_amount: null as number | null,
  employee_count: null as number | null,
  bonus_mode: 'fixed' as string,
  amount_per_employee: null as number | null,
  percentage_of_gross: null as number | null,
  percentage_of_basic: null as number | null,
  bonus_multiple: null as number | null,
  count: null as number | null,
  avg_salary: null as number | null,
  months: null as number | null,
  hours_per_employee: null as number | null,
  overtime_rate: null as number | null,
  allowance_type: '',
  amount: null as number | null,
  year: null as number | null,
  buffer_percentage: null as number | null,
  growth_percentage: null as number | null,
})

const bonusModes = computed(() => [
  { title: t('payroll_simulations_create.parameters.bonus_mode_fixed'), value: 'fixed' },
  { title: t('payroll_simulations_create.parameters.bonus_mode_pct_gross'), value: 'pct_gross' },
  { title: t('payroll_simulations_create.parameters.bonus_mode_pct_basic'), value: 'pct_basic' },
  { title: t('payroll_simulations_create.parameters.bonus_mode_multiple'), value: 'multiple_salary' },
])
const increaseModes = computed(() => [
  { title: t('payroll_simulations_create.parameters.increase_mode_percentage'), value: 'percentage' },
  { title: t('payroll_simulations_create.parameters.increase_mode_fixed'), value: 'fixed_amount' },
])

const simulationTypes = computed(()=> [
  { title: t('payroll_simulations_create.simulation_types.salary_increase'), value: 'salary_increase', icon: 'tabler-trending-up', desc: t('payroll_simulations_create.simulation_types.salary_increase_desc') },
  { title: t('payroll_simulations_create.simulation_types.bonus'), value: 'bonus', icon: 'tabler-gift', desc: t('payroll_simulations_create.simulation_types.bonus_desc') },
  { title: t('payroll_simulations_create.simulation_types.new_hire'), value: 'new_hire', icon: 'tabler-user-plus', desc: t('payroll_simulations_create.simulation_types.new_hire_desc') },
  { title: t('payroll_simulations_create.simulation_types.overtime'), value: 'overtime', icon: 'tabler-clock-plus', desc: t('payroll_simulations_create.simulation_types.overtime_desc') },
  { title: t('payroll_simulations_create.simulation_types.allowance'), value: 'allowance', icon: 'tabler-cash', desc: t('payroll_simulations_create.simulation_types.allowance_desc') },
  { title: t('payroll_simulations_create.simulation_types.union_increase'), value: 'union_increase', icon: 'tabler-users', desc: t('payroll_simulations_create.simulation_types.union_increase_desc') },
  { title: t('payroll_simulations_create.simulation_types.annual_budget'), value: 'annual_budget', icon: 'tabler-calendar-check', desc: t('payroll_simulations_create.simulation_types.annual_budget_desc') },
])

const selectedType = computed(() => simulationTypes.value.find(st => st.value === form.simulation_type))

const showPercentageEmployee = computed(() => ['salary_increase', 'union_increase'].includes(form.simulation_type))
const showBonus    = computed(() => form.simulation_type === 'bonus')
const showNewHire  = computed(() => form.simulation_type === 'new_hire')
const showOvertime = computed(() => form.simulation_type === 'overtime')
const showAllowance = computed(() => form.simulation_type === 'allowance')
const showBudgetYear = computed(() => form.simulation_type === 'annual_budget')

function submit() {
  form.post(route('payroll.simulations.store'))
}
</script>

<template>
  <VContainer fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_simulations_create.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_simulations_create.description') }}</p>
      </div>
      <VBtn variant="outlined" :href="route('payroll.simulations.index')" prepend-icon="tabler-arrow-left">
        {{ t('payroll_simulations_create.back') }}
      </VBtn>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" lg="8">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon start size="18" color="primary">tabler-calculator</VIcon>
              {{ t('payroll_simulations_create.simulation_type') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow dense>
                <VCol v-for="st in simulationTypes" :key="st.value" cols="12" sm="6" md="4">
                  <VCard
                    :variant="form.simulation_type === st.value ? 'tonal' : 'outlined'"
                    :color="form.simulation_type === st.value ? 'primary' : undefined"
                    class="cursor-pointer pa-3"
                    @click="form.simulation_type = st.value"
                  >
                    <div class="d-flex align-center ga-2 mb-1">
                      <VIcon :color="form.simulation_type === st.value ? 'primary' : 'grey'" size="18">{{ st.icon }}</VIcon>
                      <span class="text-body-2 font-weight-medium">{{ st.title }}</span>
                    </div>
                    <div class="text-caption text-medium-emphasis">{{ st.desc }}</div>
                  </VCard>
                </VCol>
              </VRow>
              <div v-if="form.errors.simulation_type" class="text-error text-caption mt-2">{{ form.errors.simulation_type }}</div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon start size="18" color="primary">tabler-info-circle</VIcon>
              {{ t('payroll_simulations_create.details.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VTextField
                v-model="form.name"
                :label="t('payroll_simulations_create.details.name')"
                variant="outlined"
                density="compact"
                :placeholder="t('payroll_simulations_create.details.name_placeholder')"
                :error-messages="form.errors.name"
              />
            </VCardText>
          </VCard>

          <VCard v-if="form.simulation_type" elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon start size="18" color="primary">tabler-adjustments</VIcon>
              {{ t('payroll_simulations_create.parameters.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <template v-if="showPercentageEmployee">
                  <VCol v-if="form.simulation_type === 'salary_increase'" cols="12" md="6">
                    <VSelect v-model="form.increase_mode" :items="increaseModes" item-title="title" item-value="value" :label="t('payroll_simulations_create.parameters.increase_mode')" variant="outlined" density="compact" />
                  </VCol>
                  <VCol v-if="form.simulation_type === 'salary_increase' && form.increase_mode === 'fixed_amount'" cols="12" md="6">
                    <VTextField v-model="form.fixed_amount" :label="t('payroll_simulations_create.parameters.fixed_amount')" type="number" prefix="R" variant="outlined" density="compact" :error-messages="form.errors.fixed_amount" />
                  </VCol>
                  <VCol v-else cols="12" md="6">
                    <VTextField v-model="form.percentage" :label="t('payroll_simulations_create.parameters.increase_percentage')" type="number" suffix="%" variant="outlined" density="compact" :error-messages="form.errors.percentage" />
                  </VCol>
                </template>
                <template v-if="showBonus">
                  <VCol cols="12" md="6">
                    <VSelect v-model="form.bonus_mode" :items="bonusModes" item-title="title" item-value="value" :label="t('payroll_simulations_create.parameters.bonus_mode')" variant="outlined" density="compact" :hint="t('payroll_simulations_create.parameters.bonus_mode_hint')" persistent-hint />
                  </VCol>
                  <VCol v-if="form.bonus_mode === 'fixed'" cols="12" md="6">
                    <VTextField v-model="form.amount_per_employee" :label="t('payroll_simulations_create.parameters.bonus_amount')" type="number" prefix="R" variant="outlined" density="compact" :error-messages="form.errors.amount_per_employee" />
                  </VCol>
                  <VCol v-else-if="form.bonus_mode === 'pct_gross'" cols="12" md="6">
                    <VTextField v-model="form.percentage_of_gross" :label="t('payroll_simulations_create.parameters.pct_of_gross')" type="number" suffix="%" variant="outlined" density="compact" :error-messages="form.errors.percentage_of_gross" />
                  </VCol>
                  <VCol v-else-if="form.bonus_mode === 'pct_basic'" cols="12" md="6">
                    <VTextField v-model="form.percentage_of_basic" :label="t('payroll_simulations_create.parameters.pct_of_basic')" type="number" suffix="%" variant="outlined" density="compact" :error-messages="form.errors.percentage_of_basic" />
                  </VCol>
                  <VCol v-else-if="form.bonus_mode === 'multiple_salary'" cols="12" md="6">
                    <VTextField v-model="form.bonus_multiple" :label="t('payroll_simulations_create.parameters.bonus_multiple')" type="number" suffix="×" step="0.5" variant="outlined" density="compact" :hint="t('payroll_simulations_create.parameters.bonus_multiple_hint')" persistent-hint :error-messages="form.errors.bonus_multiple" />
                  </VCol>
                </template>
                <template v-if="showNewHire">
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.count" :label="t('payroll_simulations_create.parameters.new_hire_count')" type="number" variant="outlined" density="compact" :error-messages="form.errors.count" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.avg_salary" :label="t('payroll_simulations_create.parameters.avg_salary')" type="number" variant="outlined" density="compact" :error-messages="form.errors.avg_salary" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.months" :label="t('payroll_simulations_create.parameters.months')" type="number" variant="outlined" density="compact" :error-messages="form.errors.months" />
                  </VCol>
                </template>
                <template v-if="showOvertime">
                  <VCol cols="12" md="6">
                    <VTextField v-model="form.hours_per_employee" :label="t('payroll_simulations_create.parameters.overtime_hours')" type="number" variant="outlined" density="compact" :error-messages="form.errors.hours_per_employee" />
                  </VCol>
                  <VCol cols="12" md="6">
                    <VTextField v-model="form.overtime_rate" :label="t('payroll_simulations_create.parameters.overtime_rate')" type="number" variant="outlined" density="compact" :error-messages="form.errors.overtime_rate" />
                  </VCol>
                </template>
                <template v-if="showAllowance">
                  <VCol cols="12" md="6">
                    <VTextField v-model="form.allowance_type" :label="t('payroll_simulations_create.parameters.allowance_type')" variant="outlined" density="compact" :placeholder="t('payroll_simulations_create.parameters.allowance_type_placeholder')" :error-messages="form.errors.allowance_type" />
                  </VCol>
                  <VCol cols="12" md="6">
                    <VTextField v-model="form.amount" :label="t('payroll_simulations_create.parameters.allowance_amount')" type="number" variant="outlined" density="compact" :error-messages="form.errors.amount" />
                  </VCol>
                </template>
                <template v-if="showBudgetYear">
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.year" :label="t('payroll_simulations_create.parameters.budget_year')" type="number" variant="outlined" density="compact" :placeholder="t('payroll_simulations_create.parameters.budget_year_placeholder')" :error-messages="form.errors.year" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.growth_percentage" :label="t('payroll_simulations_create.parameters.growth_percentage')" type="number" suffix="%" variant="outlined" density="compact" :hint="t('payroll_simulations_create.parameters.growth_percentage_hint')" persistent-hint :error-messages="form.errors.growth_percentage" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model="form.buffer_percentage" :label="t('payroll_simulations_create.parameters.buffer_percentage')" type="number" suffix="%" variant="outlined" density="compact" :hint="t('payroll_simulations_create.parameters.buffer_percentage_hint')" persistent-hint :error-messages="form.errors.buffer_percentage" />
                  </VCol>
                </template>
              </VRow>
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              <VIcon start size="18">tabler-note</VIcon>
              {{ t('payroll_simulations_create.notes.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VTextarea v-model="form.notes" :label="t('payroll_simulations_create.notes.notes_label')" variant="outlined" density="compact" rows="3" :placeholder="t('payroll_simulations_create.notes.notes_placeholder')" />
            </VCardText>
          </VCard>

          <div class="d-flex justify-end ga-3">
            <VBtn variant="outlined" :href="route('payroll.simulations.index')">{{ t('payroll_simulations_create.cancel') }}</VBtn>
            <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-calculator" :disabled="!form.simulation_type || !form.name">
              {{ t('payroll_simulations_create.run_simulation') }}
            </VBtn>
          </div>
        </VCol>

        <VCol cols="12" lg="4">
          <VCard v-if="selectedType" elevation="0" border class="mb-4">
            <VCardText class="pa-4">
              <div class="d-flex align-center ga-2 mb-3">
                <VAvatar color="primary" size="36">
                  <VIcon size="20">{{ selectedType.icon }}</VIcon>
                </VAvatar>
                <div>
                  <div class="text-body-2 font-weight-semibold">{{ selectedType.title }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.simulation_type') }}</div>
                </div>
              </div>
              <p class="text-body-2 text-medium-emphasis">{{ selectedType.desc }}</p>
            </VCardText>
          </VCard>

          <VCard v-else elevation="0" border class="mb-4">
            <VCardText class="pa-4 text-center">
              <VIcon color="primary" size="36" class="mb-2">tabler-calculator</VIcon>
              <div class="text-body-2 font-weight-medium mb-1">{{ t('payroll_simulations_create.select_type') }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.select_type_desc') }}</div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border>
            <VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('payroll_simulations_create.how_it_works') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <div class="d-flex flex-column ga-3">
                <div class="d-flex align-start ga-3">
                  <VAvatar color="primary" size="28" class="flex-shrink-0 mt-1"><span class="text-caption font-weight-bold text-white">1</span></VAvatar>
                  <div>
                    <div class="text-body-2 font-weight-medium">{{ t('payroll_simulations_create.steps.configure') }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.steps.configure_desc') }}</div>
                  </div>
                </div>
                <div class="d-flex align-start ga-3">
                  <VAvatar color="primary" size="28" class="flex-shrink-0 mt-1"><span class="text-caption font-weight-bold text-white">2</span></VAvatar>
                  <div>
                    <div class="text-body-2 font-weight-medium">{{ t('payroll_simulations_create.steps.calculate') }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.steps.calculate_desc') }}</div>
                  </div>
                </div>
                <div class="d-flex align-start ga-3">
                  <VAvatar color="primary" size="28" class="flex-shrink-0 mt-1"><span class="text-caption font-weight-bold text-white">3</span></VAvatar>
                  <div>
                    <div class="text-body-2 font-weight-medium">{{ t('payroll_simulations_create.steps.review') }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.steps.review_desc') }}</div>
                  </div>
                </div>
                <div class="d-flex align-start ga-3">
                  <VAvatar color="success" size="28" class="flex-shrink-0 mt-1"><span class="text-caption font-weight-bold text-white">4</span></VAvatar>
                  <div>
                    <div class="text-body-2 font-weight-medium">{{ t('payroll_simulations_create.steps.decide') }}</div>
                    <div class="text-caption text-medium-emphasis">{{ t('payroll_simulations_create.steps.decide_desc') }}</div>
                  </div>
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VForm>
  </VContainer>
</template>
