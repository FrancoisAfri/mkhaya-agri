<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Onboarding {
  company_name: string
  company_registration_number: string | null
  contact_person: string | null
  contact_person_email: string | null
  contact_person_phone: string | null
  expires_at: string | null
}

interface DocumentType {
  id: number
  name: string
  compulsory: boolean
}

const props = defineProps<{
  token: string
  onboarding: Onboarding
  documentTypes: DocumentType[]
}>()

// Prefilled from the invitation. The buyer already typed the company name and the contact once;
// asking for it again is how a registration form loses the person filling it in.
const form = ref({
  company_name: props.onboarding.company_name ?? '',
  trading_name: '',
  company_registration_number: props.onboarding.company_registration_number ?? '',
  vat_registration_number: '',
  tax_reference_number: '',
  business_entity_type: '',
  email: props.onboarding.contact_person_email ?? '',
  phone_number: props.onboarding.contact_person_phone ?? '',
  website_url: '',
  physical_address: '',
  postal_address: '',
  bank_name: '',
  account_holder_name: '',
  account_type: '',
  bank_account_number: '',
  branch_code: '',
  contacts: [] as Array<{ first_name: string; surname: string; email: string; tel: string }>,
  directors: [] as Array<{ first_name: string; surname: string; email: string; tel: string }>,
})

// The named contact from the invitation becomes the first entry, split on the first space so a
// single-word name lands in the first-name field rather than being silently dropped.
if (props.onboarding.contact_person) {
  const [first, ...rest] = props.onboarding.contact_person.trim().split(' ')
  form.value.contacts.push({
    first_name: first,
    surname: rest.join(' '),
    email: props.onboarding.contact_person_email ?? '',
    tel: props.onboarding.contact_person_phone ?? '',
  })
}

const submitting = ref(false)

function addContact(): void {
  form.value.contacts.push({ first_name: '', surname: '', email: '', tel: '' })
}

function addDirector(): void {
  form.value.directors.push({ first_name: '', surname: '', email: '', tel: '' })
}

function submit(): void {
  submitting.value = true
  router.post(route('supplier.registration.store', props.token), form.value, {
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <VContainer class="py-8" style="max-width: 62rem">
    <h1 class="text-h4 mb-1">{{ t('supplier_registration.page_title') }}</h1>
    <p class="text-body-1 text-medium-emphasis mb-6">
      {{ t('supplier_registration.intro', { company: onboarding.company_name }) }}
    </p>

    <!--
      The documents are listed before the form rather than after it. A supplier who learns about the
      tax clearance requirement on the last screen abandons the form; one told at the start arrives
      with the file already to hand.
    -->
    <VAlert
      v-if="documentTypes.some(d => d.compulsory)"
      type="info"
      variant="tonal"
      class="mb-6"
      icon="tabler-file-check"
    >
      <div class="font-weight-medium mb-1">{{ t('supplier_registration.documents_needed') }}</div>
      <ul class="ms-4">
        <li v-for="d in documentTypes.filter(x => x.compulsory)" :key="d.id">{{ d.name }}</li>
      </ul>
      <div class="text-body-2 mt-2">{{ t('supplier_registration.documents_after') }}</div>
    </VAlert>

    <VForm @submit.prevent="submit">
      <VCard border class="mb-4">
        <VCardTitle>{{ t('supplier_registration.sections.company') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.company_name"
                :label="t('supplier_registration.fields.company_name')"
                variant="outlined"
                density="comfortable"
                required
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.trading_name" :label="t('supplier_registration.fields.trading_name')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.company_registration_number" :label="t('supplier_registration.fields.registration')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.vat_registration_number" :label="t('supplier_registration.fields.vat')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.email" type="email" :label="t('supplier_registration.fields.email')" variant="outlined" density="comfortable" required />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.phone_number" :label="t('supplier_registration.fields.phone')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.physical_address" :label="t('supplier_registration.fields.physical_address')" variant="outlined" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard border class="mb-4">
        <VCardTitle>{{ t('supplier_registration.sections.banking') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VTextField v-model="form.bank_name" :label="t('supplier_registration.fields.bank_name')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.account_holder_name" :label="t('supplier_registration.fields.account_holder')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.bank_account_number" :label="t('supplier_registration.fields.account_number')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.branch_code" :label="t('supplier_registration.fields.branch_code')" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard border class="mb-4">
        <VCardTitle class="d-flex align-center justify-space-between">
          {{ t('supplier_registration.sections.contacts') }}
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addContact">
            {{ t('supplier_registration.add_contact') }}
          </VBtn>
        </VCardTitle>
        <VCardText>
          <p v-if="!form.contacts.length" class="text-medium-emphasis">
            {{ t('supplier_registration.no_contacts') }}
          </p>
          <VRow v-for="(c, i) in form.contacts" :key="`c-${i}`" class="align-center">
            <VCol cols="12" md="3"><VTextField v-model="c.first_name" :label="t('supplier_registration.fields.first_name')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="c.surname" :label="t('supplier_registration.fields.surname')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="c.email" type="email" :label="t('supplier_registration.fields.email')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="2"><VTextField v-model="c.tel" :label="t('supplier_registration.fields.phone')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="1">
              <VBtn
                icon
                size="small"
                variant="text"
                color="error"
                :aria-label="t('supplier_registration.remove_row')"
                :title="t('supplier_registration.remove_row')"
                @click="form.contacts.splice(i, 1)"
              >
                <VIcon icon="tabler-trash" />
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard border class="mb-6">
        <VCardTitle class="d-flex align-center justify-space-between">
          {{ t('supplier_registration.sections.directors') }}
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addDirector">
            {{ t('supplier_registration.add_director') }}
          </VBtn>
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('supplier_registration.directors_hint') }}
          </p>
          <VRow v-for="(d, i) in form.directors" :key="`d-${i}`" class="align-center">
            <VCol cols="12" md="3"><VTextField v-model="d.first_name" :label="t('supplier_registration.fields.first_name')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="d.surname" :label="t('supplier_registration.fields.surname')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="d.email" type="email" :label="t('supplier_registration.fields.email')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="2"><VTextField v-model="d.tel" :label="t('supplier_registration.fields.phone')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" md="1">
              <VBtn
                icon
                size="small"
                variant="text"
                color="error"
                :aria-label="t('supplier_registration.remove_row')"
                :title="t('supplier_registration.remove_row')"
                @click="form.directors.splice(i, 1)"
              >
                <VIcon icon="tabler-trash" />
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <div class="d-flex justify-end">
        <VBtn type="submit" color="primary" size="large" :loading="submitting">
          {{ t('supplier_registration.submit') }}
        </VBtn>
      </div>
    </VForm>
  </VContainer>
</template>
