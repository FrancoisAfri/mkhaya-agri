<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import { humanise, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  types: any[]
  departments: { id: number; name: string }[]
  officers: { id: number; name: string }[]
  suppliers: { id: number; name: string }[]
  supplierModule: boolean
  riskLevels: string[]
  records: any[]
  noCompany?: boolean
}>()

const form = useForm({
  title: '',
  record_id: null as number | null,
  procurement_reference: '',
  company_unit_id: null as number | null,
  requesting_officer_id: null as number | null,
  procurement_value: null as number | null,
  supplier_id: null as number | null,
  supplier_name: '',
  deviation_type_id: null as number | null,
  reason: '',
  business_justification: '',
  circumstances: '',
  risk_assessment: '',
  risk_level: 'MEDIUM',
  proposed_alternative: '',
  financial_impact: '',
  financial_impact_amount: null as number | null,
  requested_approval_date: '',
})

const selectedType = computed(() => props.types.find(t => t.id === form.deviation_type_id))

// The type carries its own defaults; applying them saves the requester answering twice.
watch(() => form.deviation_type_id, () => {
  if (selectedType.value?.default_risk_level) form.risk_level = selectedType.value.default_risk_level
})

watch(() => form.record_id, id => {
  const record = props.records.find(r => r.id === id)
  if (!record) return

  form.procurement_reference ||= record.reference
  form.procurement_value ??= record.estimated_value
  form.company_unit_id ??= record.company_unit_id
  form.title ||= record.title
})

function submit() {
  form.post('/procurement-governance/deviations', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.deviations.new') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.deviations.new_hint') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VForm v-if="!noCompany" @submit.prevent="submit">
      <FormErrors :errors="form.errors" />

      <VRow>
        <VCol cols="12" lg="8">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.deviations.identification') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="12">
                  <VTextField
                    v-model="form.title" :label="t('procurement_governance.common.title')"
                    :error-messages="form.errors.title" variant="outlined" density="comfortable" required
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.deviation_type_id"
                    :items="types.map(t => ({ title: t.name, subtitle: t.description, value: t.id }))"
                    :label="t('procurement_governance.deviations.type')"
                    variant="outlined" density="comfortable" clearable
                  >
                    <template #item="{ props: itemProps, item }">
                      <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                    </template>
                  </VSelect>
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.risk_level"
                    :items="riskLevels.map(r => ({ title: humanise(r), value: r }))"
                    :label="t('procurement_governance.deviations.risk')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VSelect
                    v-model="form.record_id"
                    :items="records.map(r => ({
                      title: `${r.reference} — ${r.title}`, subtitle: money(r.estimated_value), value: r.id,
                    }))"
                    :label="t('procurement_governance.deviations.related_procurement')"
                    variant="outlined" density="comfortable" clearable
                  >
                    <template #item="{ props: itemProps, item }">
                      <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                    </template>
                  </VSelect>
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model.number="form.procurement_value" type="number" min="0" step="0.01"
                    :label="t('procurement_governance.deviations.value')"
                    variant="outlined" density="comfortable"
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
                    v-model="form.requesting_officer_id"
                    :items="officers.map(o => ({ title: o.name, value: o.id }))"
                    :label="t('procurement_governance.deviations.requesting_officer')"
                    variant="outlined" density="comfortable" clearable
                  />
                </VCol>
                <VCol v-if="supplierModule" cols="12" md="6">
                  <VSelect
                    v-model="form.supplier_id"
                    :items="suppliers.map(s => ({ title: s.name, value: s.id }))"
                    :label="t('procurement_governance.deviations.supplier')"
                    variant="outlined" density="comfortable" clearable
                    @update:model-value="id => form.supplier_name = suppliers.find(s => s.id === id)?.name ?? ''"
                  />
                </VCol>
                <VCol v-else cols="12" md="6">
                  <VTextField
                    v-model="form.supplier_name" :label="t('procurement_governance.deviations.supplier')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.deviations.motivation') }}
            </VCardTitle>
            <VCardSubtitle class="text-caption pb-2">
              {{ t('procurement_governance.deviations.motivation_hint') }}
            </VCardSubtitle>
            <VCardText class="pt-0">
              <VTextarea
                v-for="field in ['reason', 'business_justification', 'circumstances', 'risk_assessment', 'proposed_alternative', 'financial_impact']"
                :key="field"
                v-model="(form as any)[field]"
                :label="t(`procurement_governance.deviations.fields.${field}`)"
                :hint="t(`procurement_governance.deviations.fields.${field}_hint`)"
                persistent-hint variant="outlined" rows="2" auto-grow class="mb-4"
              />
              <VRow dense>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model.number="form.financial_impact_amount" type="number" step="0.01"
                    :label="t('procurement_governance.deviations.financial_impact_amount')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="6">
                  <VTextField
                    v-model="form.requested_approval_date" type="date"
                    :label="t('procurement_governance.deviations.requested_approval_date')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="4">
          <!-- §5.4 runs on submit, not on save; the form says so rather than surprising them. -->
          <VAlert type="info" variant="tonal" class="mb-4 text-body-2" icon="mdi-information-outline">
            {{ t('procurement_governance.deviations.draft_notice') }}
          </VAlert>

          <VCard v-if="selectedType" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">{{ selectedType.name }}</VCardTitle>
            <VCardText class="text-body-2">
              <p v-if="selectedType.description" class="mb-2">{{ selectedType.description }}</p>
              <ul class="ps-4 mb-0">
                <li v-if="selectedType.requires_supplier">{{ t('procurement_governance.deviations.requires_supplier') }}</li>
                <li v-if="selectedType.requires_risk_assessment">{{ t('procurement_governance.deviations.requires_risk') }}</li>
              </ul>
            </VCardText>
          </VCard>

          <VBtn
            type="submit" color="primary" block size="large"
            :loading="form.processing" :disabled="form.processing"
            prepend-icon="mdi-content-save-outline"
          >
            {{ t('procurement_governance.deviations.save_draft') }}
          </VBtn>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
