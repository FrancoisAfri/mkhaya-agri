<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import { humanise, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  departments: { id: number; name: string }[]
  projects: { id: number; code: string | null; name: string }[]
  officers: { id: number; name: string }[]
  committees: { id: number; name: string }[]
  categories: string[]
  budgets: { id: number; code: string | null; name: string; available: number }[]
  budgetModule: boolean
  methodologies: string[]
  planItems: any[]
  defaults: { currency: string; jurisdiction: string }
  jurisdictions: { code: string; name: string; currency: string }[]
  preferenceFrameworks: {
    code: string; name: string; jurisdiction: string; basis: string
    price_weight: number | null; preference_weight: number | null; is_verified: boolean
  }[]
  noCompany?: boolean
}>()

const form = useForm({
  title: '',
  tender_number: '',
  description: '',
  category: '',
  company_unit_id: null as number | null,
  project_id: null as number | null,
  officer_id: null as number | null,
  estimated_value: null as number | null,
  currency: props.defaults.currency ?? 'ZAR',
  budget_id: null as number | null,
  tender_closing_at: '',
  evaluation_start_date: '',
  evaluation_deadline: '',
  methodology: 'WEIGHTED',
  price_weight: 80,
  preference_weight: 20,
  committee_id: null as number | null,
  required_approval_level: '',
  plan_item_id: null as number | null,
  jurisdiction: props.defaults.jurisdiction ?? 'ZA',
  preference_framework_code: null as string | null,
})

/**
 * Frameworks available for the jurisdiction this tender is being run under.
 *
 * Filtered rather than merely sorted: offering a South African B-BBEE table on a Botswana tender is
 * not a convenience, it is an invitation to score a bid under the wrong country's rules.
 */
const frameworksForJurisdiction = computed(
  () => (props.preferenceFrameworks ?? []).filter(f => f.jurisdiction === form.jurisdiction),
)

const selectedFramework = computed(
  () => (props.preferenceFrameworks ?? []).find(f => f.code === form.preference_framework_code) ?? null,
)

/**
 * Moving jurisdiction moves the money and the rules with it.
 *
 * A tender run in Gaborone is ordinarily priced in pula, and the framework chosen for South Africa
 * cannot apply to it. Both are reset here rather than left stale, because a leftover ZAR on a
 * Botswana tender converts every bid through an exchange rate nobody intended.
 */
watch(() => form.jurisdiction, code => {
  const jurisdiction = props.jurisdictions.find(j => j.code === code)
  if (jurisdiction) form.currency = jurisdiction.currency

  if (form.preference_framework_code
    && !frameworksForJurisdiction.value.some(f => f.code === form.preference_framework_code)) {
    form.preference_framework_code = null
  }
})

/** The statutory split belongs to the framework, so choosing one fills it in. */
watch(() => form.preference_framework_code, () => {
  const framework = selectedFramework.value
  if (!framework?.price_weight) return

  form.price_weight = Number(framework.price_weight)
  form.preference_weight = Number(framework.preference_weight ?? 0)
})

/** §11: raising a review from an approved plan line carries its data across rather than retyping it. */
watch(() => form.plan_item_id, id => {
  const line = props.planItems.find(i => i.id === id)
  if (!line) return

  form.title ||= line.description
  form.category ||= line.category ?? ''
  form.estimated_value ??= line.estimated_value
  form.company_unit_id ??= line.company_unit_id
  form.project_id ??= line.project_id
  form.officer_id ??= line.responsible_officer_id
  form.budget_id ??= line.budget_id
})

const showPreference = computed(() => form.methodology === 'PRICE_PREFERENCE')

const weightsTotal = computed(() => Number(form.price_weight ?? 0) + Number(form.preference_weight ?? 0))

function submit() {
  form.post('/procurement-governance/tenders', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.tenders.initiate') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.tenders.initiate_hint') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VForm v-if="!noCompany" @submit.prevent="submit">
      <FormErrors :errors="form.errors" />

      <VRow>
        <VCol cols="12" lg="8">
          <VCard v-if="planItems.length" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.tenders.from_plan') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VSelect
                v-model="form.plan_item_id"
                :items="planItems.map(i => ({
                  title: `${i.reference} — ${i.description}`,
                  subtitle: money(i.estimated_value),
                  value: i.id,
                }))"
                :label="t('procurement_governance.tenders.plan_line')"
                :hint="t('procurement_governance.tenders.plan_line_hint')" persistent-hint
                variant="outlined" density="comfortable" clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VSelect>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.tenders.tender_information') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="12" md="8">
                  <VTextField
                    v-model="form.title" :label="t('procurement_governance.common.title')"
                    :error-messages="form.errors.title" variant="outlined" density="comfortable" required
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.tender_number" :label="t('procurement_governance.tenders.tender_number')"
                    :error-messages="form.errors.tender_number" variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.description" :label="t('procurement_governance.common.description')"
                    variant="outlined" rows="2" auto-grow
                  />
                </VCol>
                <VCol cols="12" md="6">
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
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.officer_id"
                    :items="officers.map(o => ({ title: o.name, value: o.id }))"
                    :label="t('procurement_governance.tenders.procurement_officer')"
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model.number="form.estimated_value" type="number" min="0" step="0.01"
                    :label="t('procurement_governance.tenders.estimated_value')"
                    :error-messages="form.errors.estimated_value"
                    :suffix="form.currency"
                    variant="outlined" density="comfortable"
                  />
                </VCol>

                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.jurisdiction"
                    :items="jurisdictions.map(j => ({ title: `${j.name} (${j.currency})`, value: j.code }))"
                    :label="t('procurement_governance.tenders.jurisdiction')"
                    :hint="t('procurement_governance.tenders.jurisdiction_hint')" persistent-hint
                    :error-messages="form.errors.jurisdiction"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.preference_framework_code"
                    :items="frameworksForJurisdiction.map(f => ({ title: f.name, value: f.code }))"
                    :label="t('procurement_governance.tenders.preference_framework')"
                    :hint="frameworksForJurisdiction.length
                      ? t('procurement_governance.tenders.preference_framework_hint')
                      : t('procurement_governance.tenders.no_framework_for_jurisdiction')"
                    persistent-hint
                    :error-messages="form.errors.preference_framework_code"
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>

                <!-- A seeded points table is a starting point, not settled policy. Saying so at the
                     moment the framework is chosen is worth more than a note on a settings screen
                     the officer running this tender may never open. -->
                <VCol v-if="selectedFramework && !selectedFramework.is_verified" cols="12">
                  <VAlert type="warning" variant="tonal" density="compact" class="text-body-2">
                    {{ t('procurement_governance.tenders.framework_unverified_warning', { name: selectedFramework.name }) }}
                  </VAlert>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.tenders.dates') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.tender_closing_at" type="datetime-local"
                    :label="t('procurement_governance.tenders.closing_at')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.evaluation_start_date" type="date"
                    :label="t('procurement_governance.tenders.evaluation_start')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.evaluation_deadline" type="date"
                    :label="t('procurement_governance.tenders.evaluation_deadline')"
                    :error-messages="form.errors.evaluation_deadline"
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
              {{ t('procurement_governance.tenders.methodology') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VSelect
                v-model="form.methodology"
                :items="methodologies.map(m => ({
                  title: t(`procurement_governance.tenders.methodologies.${m}`),
                  subtitle: t(`procurement_governance.tenders.methodologies.${m}_hint`),
                  value: m,
                }))"
                :label="t('procurement_governance.tenders.methodology')"
                variant="outlined" density="comfortable"
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VSelect>

              <template v-if="showPreference">
                <VRow dense class="mt-1">
                  <VCol cols="6">
                    <VTextField
                      v-model.number="form.price_weight" type="number" min="0" max="100"
                      :label="t('procurement_governance.tenders.price_weight')"
                      variant="outlined" density="comfortable"
                    />
                  </VCol>
                  <VCol cols="6">
                    <VTextField
                      v-model.number="form.preference_weight" type="number" min="0" max="100"
                      :label="t('procurement_governance.tenders.preference_weight')"
                      variant="outlined" density="comfortable"
                    />
                  </VCol>
                </VRow>
                <!-- Flagged before submit rather than as a server error: the fix is obvious and the
                     round trip is not worth it. -->
                <VAlert
                  v-if="weightsTotal !== 100" type="warning" variant="tonal"
                  density="compact" class="text-body-2"
                >
                  {{ t('procurement_governance.tenders.weights_must_total', { total: weightsTotal }) }}
                </VAlert>
              </template>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.tenders.governance') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VSelect
                v-model="form.committee_id"
                :items="committees.map(c => ({ title: c.name, value: c.id }))"
                :label="t('procurement_governance.tenders.committee')"
                :hint="committees.length ? undefined : t('procurement_governance.tenders.no_committees')"
                persistent-hint
                variant="outlined" density="comfortable" clearable class="mb-3"
              />
              <VTextField
                v-model="form.required_approval_level"
                :label="t('procurement_governance.tenders.approval_level')"
                :hint="t('procurement_governance.tenders.approval_level_hint')" persistent-hint
                variant="outlined" density="comfortable" class="mb-3"
              />
              <VSelect
                v-if="budgetModule"
                v-model="form.budget_id"
                :items="budgets.map(b => ({ title: `${b.code ? b.code + ' — ' : ''}${b.name}`, value: b.id }))"
                :label="t('procurement_governance.planning.budget')"
                variant="outlined" density="comfortable" clearable
              />
            </VCardText>
          </VCard>

          <VBtn
            type="submit" color="primary" block size="large"
            :loading="form.processing"
            :disabled="form.processing || (showPreference && weightsTotal !== 100)"
            prepend-icon="mdi-content-save-outline"
          >
            {{ t('procurement_governance.tenders.create_review') }}
          </VBtn>
          <p class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('procurement_governance.tenders.create_review_hint') }}
          </p>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
