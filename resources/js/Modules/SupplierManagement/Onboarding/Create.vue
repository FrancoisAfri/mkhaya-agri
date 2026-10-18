<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface DocumentType {
  id: number
  document_type_name: string
  compulsory: boolean
}

interface Onboarding {
  id: number
  company_name: string
  company_registration_number: string | null
  supplier_name: string | null
  contact_person: string | null
  contact_person_email: string | null
  contact_person_phone: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  onboarding?: Onboarding
  documentTypes: DocumentType[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const isEdit = !!props.onboarding
const submitting = ref<boolean>(false)

interface OnboardingForm {
  company_name: string
  company_registration_number: string
  supplier_name: string
  contact_person: string
  contact_person_email: string
  contact_person_phone: string
}

const form = ref<OnboardingForm>({
  company_name: props.onboarding?.company_name ?? '',
  company_registration_number: props.onboarding?.company_registration_number ?? '',
  supplier_name: props.onboarding?.supplier_name ?? '',
  contact_person: props.onboarding?.contact_person ?? '',
  contact_person_email: props.onboarding?.contact_person_email ?? '',
  contact_person_phone: props.onboarding?.contact_person_phone ?? '',
})

// ── Methods ────────────────────────────────────────────────────────────────────
function submit(): void {
  submitting.value = true
  if (isEdit) {
    router.patch(
      route('suppliers.onboarding.update', props.onboarding!.id),
      form.value,
      { onFinish: () => (submitting.value = false) }
    )
  } else {
    router.post(
      route('suppliers.onboarding.store'),
      form.value,
      { onFinish: () => (submitting.value = false) }
    )
  }
}

function goToIndex(): void {
  router.get(route('suppliers.onboarding.index'))
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">
      {{ isEdit ? t('supplier_onboarding.edit_invite') : t('supplier_onboarding.new_invite') }}
    </h1>
    <VRow>
      <!-- ── Form ────────────────────────────────────────────────────────────── -->
      <VCol cols="12" md="8">
        <VCard border>
          <VCardText>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.company_name"
                  :label="t('supplier_onboarding.form.company_name')"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.company_registration_number"
                  :label="t('supplier_onboarding.form.registration_number')"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.supplier_name"
                  :label="t('supplier_onboarding.form.supplier_name')"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.contact_person"
                  :label="t('supplier_onboarding.form.contact_person')"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.contact_person_email"
                  :label="t('supplier_onboarding.form.contact_email')"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.contact_person_phone"
                  :label="t('supplier_onboarding.form.contact_phone')"
                  variant="outlined"
                />
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn variant="text" @click="goToIndex">
              {{ t('supplier_onboarding.actions.cancel') }}
            </VBtn>
            <VBtn
              color="primary"
              :loading="submitting"
              :disabled="!form.company_name"
              @click="submit"
            >
              {{ isEdit ? t('supplier_onboarding.actions.update') : t('supplier_onboarding.actions.create') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- ── Documents Sidebar ────────────────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard border>
          <VCardTitle>{{ t('supplier_onboarding.documents.title') }}</VCardTitle>
          <VList density="compact">
            <VListItem
              v-for="d in documentTypes"
              :key="d.id"
              :title="d.document_type_name"
            >
              <template #append>
                <VChip
                  v-if="d.compulsory"
                  size="x-small"
                  color="error"
                  variant="tonal"
                >
                  {{ t('supplier_onboarding.documents.required') }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
