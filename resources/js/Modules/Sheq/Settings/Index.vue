<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Settings = Record<string, string | number | boolean | null>

const props = defineProps<{
  settings: Settings
  documentTypes: { code: string; label: string }[]
  formReadiness: { coida_missing: boolean; reportable_incidents: number } | null
  canManage?: boolean
  noCompany?: boolean
}>()

const form = useForm({ ...props.settings })

const save = () => form.post('/sheq/settings', { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.settings.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.settings.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- The consequence stated before the field is asked for, so it reads as a blocker to clear
           rather than as one more optional box. -->
      <VAlert
        v-if="formReadiness?.coida_missing" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite"
      >
        <div class="font-weight-medium">{{ t('sheq.settings.coida_missing_title') }}</div>
        <div class="text-body-2 mt-1">
          {{ formReadiness.reportable_incidents > 0
            ? t('sheq.settings.coida_missing_with_incidents', { count: formReadiness.reportable_incidents })
            : t('sheq.settings.coida_missing_body') }}
        </div>
      </VAlert>

      <VForm @submit.prevent="save">
        <!-- ── Employer particulars ─────────────────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.settings.employer') }}
          </VCardTitle>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('sheq.settings.employer_hint') }}</p>

            <VRow>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.coida_registration_number"
                  :label="t('sheq.settings.coida_number')"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.coida_registration_number"
                  :hint="t('sheq.settings.coida_hint')" persistent-hint
                  prepend-inner-icon="mdi-card-account-details-outline"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.registered_employer_name"
                  :label="t('sheq.settings.registered_name')"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.registered_employer_name"
                  :hint="t('sheq.settings.registered_name_hint')" persistent-hint
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextarea
                  v-model="form.employer_postal_address"
                  :label="t('sheq.settings.postal_address')" rows="3"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.employer_postal_address"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.nature_of_business"
                  :label="t('sheq.settings.nature_of_business')"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.nature_of_business"
                />
                <VTextField
                  v-model="form.provincial_director_office"
                  :label="t('sheq.settings.provincial_director')" class="mt-4"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.provincial_director_office"
                  :hint="t('sheq.settings.provincial_director_hint')" persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- ── Reference prefixes ───────────────────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.settings.references') }}
          </VCardTitle>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('sheq.settings.references_hint') }}</p>
            <VRow>
              <VCol v-for="key in ['incident_prefix', 'hazard_prefix', 'action_prefix',
                                   'permit_prefix', 'assessment_prefix', 'inspection_prefix']"
                    :key="key" cols="6" md="2">
                <VTextField
                  v-model="form[key]" :label="t('sheq.settings.' + key)"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors[key]"
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- ── What a contractor must produce ───────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.settings.contractor_requirements') }}
          </VCardTitle>
          <VCardText>
            <!--
              This list decides who is allowed on site. It has done so since the module shipped
              and there was no screen for it anywhere, so a host could neither see the bar nor
              move it. The consequence is stated plainly because ticking a box here blocks
              contractors who were working yesterday.
            -->
            <p class="text-body-2 text-medium-emphasis mb-1">
              {{ t('sheq.settings.contractor_requirements_hint') }}
            </p>
            <p class="text-caption text-medium-emphasis mb-4">
              {{ t('sheq.settings.contractor_requirements_site_note') }}
            </p>

            <VAlert
              v-if="(form.contractor_required_documents as string[]).length === 0"
              type="warning" variant="tonal" density="comfortable" class="mb-4"
            >
              {{ t('sheq.settings.contractor_requirements_none') }}
            </VAlert>

            <VRow dense>
              <VCol v-for="type in props.documentTypes" :key="type.code" cols="12" sm="6" md="4">
                <VCheckbox
                  v-model="form.contractor_required_documents"
                  :value="type.code" :label="type.label"
                  density="compact" hide-details color="primary" :disabled="!canManage"
                />
              </VCol>
            </VRow>
            <div v-if="form.errors.contractor_required_documents" class="text-error text-caption mt-2">
              {{ form.errors.contractor_required_documents }}
            </div>
          </VCardText>
        </VCard>

        <!-- ── Operating rules ──────────────────────────────────────────────────────── -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ t('sheq.settings.operating') }}
          </VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="12" md="4">
                <VTextField
                  v-model.number="form.hazard_triage_sla_hours" type="number"
                  :label="t('sheq.settings.triage_sla')"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.hazard_triage_sla_hours"
                  :hint="t('sheq.settings.triage_sla_hint')" persistent-hint
                />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField
                  v-model.number="form.capa_effectiveness_review_days" type="number"
                  :label="t('sheq.settings.effectiveness_days')"
                  variant="outlined" density="comfortable" :disabled="!canManage"
                  :error-messages="form.errors.capa_effectiveness_review_days"
                  :hint="t('sheq.settings.effectiveness_days_hint')" persistent-hint
                />
              </VCol>
              <VCol cols="12" md="4">
                <VSwitch
                  v-model="form.allow_anonymous_reporting" color="primary" :disabled="!canManage"
                  :label="t('sheq.settings.anonymous')"
                  :hint="t('sheq.settings.anonymous_hint')" persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <div class="d-flex justify-end gap-2">
          <VBtn
            v-if="canManage" type="submit" color="primary" variant="flat"
            prepend-icon="mdi-content-save-outline"
            :loading="form.processing" :disabled="form.processing || !form.isDirty"
          >{{ t('sheq.registers.save') }}</VBtn>
        </div>
      </VForm>
    </template>
  </div>
</template>
