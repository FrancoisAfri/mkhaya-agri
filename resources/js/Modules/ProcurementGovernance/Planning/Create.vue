<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import { money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  defaults: { financial_year_label: string | null; currency: string }
  departments: { id: number; name: string }[]
  projects: { id: number; code: string | null; name: string }[]
  budgets: { id: number; code: string | null; name: string; available: number }[]
  budgetModule: boolean
  categories: string[]
  noCompany?: boolean
}>()

const form = useForm({
  title: '',
  description: '',
  financial_year_label: props.defaults.financial_year_label ?? '',
  company_unit_id: null as number | null,
  project_id: null as number | null,
  cost_centre: '',
  budget_id: null as number | null,
  category: '',
  currency: props.defaults.currency ?? 'ZAR',
})

function submit() {
  form.post('/procurement-governance/plans', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.planning.new_plan') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.planning.new_plan_hint') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VForm v-if="!noCompany" @submit.prevent="submit">
      <FormErrors :errors="form.errors" />

      <VRow>
        <VCol cols="12" lg="8">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardText class="pa-4">
              <VRow dense>
                <VCol cols="12">
                  <VTextField
                    v-model="form.title" :label="t('procurement_governance.common.title')"
                    :error-messages="form.errors.title" variant="outlined" density="comfortable"
                    autofocus required
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.description" :label="t('procurement_governance.common.description')"
                    :error-messages="form.errors.description" variant="outlined" rows="3" auto-grow
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.financial_year_label"
                    :label="t('procurement_governance.planning.financial_year')"
                    :hint="t('procurement_governance.planning.financial_year_hint')" persistent-hint
                    :error-messages="form.errors.financial_year_label"
                    variant="outlined" density="comfortable" required
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <!-- A configured list, not free text: a category people type is a category
                       nobody can group by in a spend report. Managed in settings. -->
                  <VSelect
                    v-model="form.category" :items="categories"
                    :label="t('procurement_governance.common.category')"
                    :error-messages="form.errors.category"
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.company_unit_id"
                    :items="departments.map(d => ({ title: d.name, value: d.id }))"
                    :label="t('procurement_governance.common.department')"
                    :error-messages="form.errors.company_unit_id"
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <!-- Absent when Project Management is not installed: the select is simply not
                       offered rather than shown empty. -->
                  <VSelect
                    v-if="projects.length"
                    v-model="form.project_id"
                    :items="projects.map(p => ({ title: p.code ? `${p.code} — ${p.name}` : p.name, value: p.id }))"
                    :label="t('procurement_governance.common.project')"
                    variant="outlined" density="comfortable" clearable
                  />
                  <VTextField
                    v-else v-model="form.cost_centre"
                    :label="t('procurement_governance.common.cost_centre')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="4">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.planning.budget') }}
            </VCardTitle>
            <VCardText>
              <VSelect
                v-if="budgetModule"
                v-model="form.budget_id"
                :items="budgets.map(b => ({
                  title: `${b.code ? b.code + ' — ' : ''}${b.name}`,
                  subtitle: money(b.available),
                  value: b.id,
                }))"
                item-title="title" item-value="value"
                :label="t('procurement_governance.planning.budget')"
                variant="outlined" density="comfortable" clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VSelect>

              <!-- §11: the module's absence degrades this panel, it does not block the plan. -->
              <VAlert v-else type="info" variant="tonal" density="compact" class="text-body-2">
                {{ t('procurement_governance.planning.no_budget_module') }}
              </VAlert>

              <VTextField
                v-model="form.currency" :label="t('procurement_governance.common.currency')"
                variant="outlined" density="comfortable" class="mt-3" maxlength="3"
              />
            </VCardText>
          </VCard>

          <VBtn
            type="submit" color="primary" block size="large"
            :loading="form.processing" :disabled="form.processing"
            prepend-icon="mdi-content-save-outline"
          >
            {{ t('procurement_governance.planning.create_plan') }}
          </VBtn>
          <p class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('procurement_governance.planning.create_plan_hint') }}
          </p>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
