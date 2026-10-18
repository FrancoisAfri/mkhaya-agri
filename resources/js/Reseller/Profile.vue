<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ResellerNav from './ResellerNav.vue'

const route = ziggyRoute
const { t } = useI18n()

interface Profile {
  name: string; type: string; registration_number: string | null; vat_number: string | null
  contact_person: string | null; contact_email: string | null; contact_phone: string | null
  address: string | null; website: string | null; support_email: string | null
  has_logo: boolean; converted_from: string | null; converted_at: string | null; commission: string
}
const props = defineProps<{ profile: Profile; portalRole: 'owner' | 'staff' }>()
const owner = props.portalRole === 'owner'

const form = useForm({
  name: props.profile.name, registration_number: props.profile.registration_number ?? '', vat_number: props.profile.vat_number ?? '',
  contact_person: props.profile.contact_person ?? '', contact_email: props.profile.contact_email ?? '', contact_phone: props.profile.contact_phone ?? '',
  address: props.profile.address ?? '', website: props.profile.website ?? '', support_email: props.profile.support_email ?? '',
})
const save = () => form.put(route('reseller.profile.update'), { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <ResellerNav />
    <div class="d-flex align-center ga-4 mb-4">
      <VAvatar v-if="profile.has_logo" size="64" rounded="lg" class="border"><VImg :src="route('reseller.profile.logo')" :alt="profile.name" /></VAvatar>
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('reseller_portal.profile') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('reseller_portal.profile_subtitle') }}</p>
      </div>
    </div>
    <VAlert v-if="profile.converted_from" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('reseller_portal.converted_from', { name: profile.converted_from, date: profile.converted_at }) }}
    </VAlert>
    <VAlert v-if="!owner" type="info" variant="tonal" density="compact" class="mb-4">{{ t('reseller_portal.owner_only_edit') }}</VAlert>

    <VCard elevation="0" border rounded="lg">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="6"><VTextField v-model="form.name" :label="t('reseller_portal.name')" :readonly="!owner" :error-messages="form.errors.name" /></VCol>
          <VCol cols="12" md="3"><VTextField v-model="form.registration_number" :label="t('reseller_portal.f_reg')" :readonly="!owner" :error-messages="form.errors.registration_number" /></VCol>
          <VCol cols="12" md="3"><VTextField v-model="form.vat_number" :label="t('reseller_portal.f_vat')" :readonly="!owner" :error-messages="form.errors.vat_number" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="form.contact_person" :label="t('reseller_portal.contact_person')" :readonly="!owner" :error-messages="form.errors.contact_person" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="form.contact_email" type="email" :label="t('reseller_portal.contact_email')" :readonly="!owner" :error-messages="form.errors.contact_email" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="form.contact_phone" :label="t('reseller_portal.contact_phone')" :readonly="!owner" :error-messages="form.errors.contact_phone" /></VCol>
          <VCol cols="12"><VTextarea v-model="form.address" :label="t('reseller_portal.address')" rows="2" auto-grow :readonly="!owner" :error-messages="form.errors.address" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="form.website" :label="t('reseller_portal.website')" :readonly="!owner" :error-messages="form.errors.website" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="form.support_email" type="email" :label="t('reseller_portal.support_email')" :readonly="!owner" :error-messages="form.errors.support_email" /></VCol>
        </VRow>
        <div class="text-body-2 mt-2">{{ t('reseller_portal.your_commission') }}: <strong>{{ profile.commission }}</strong> {{ t('reseller_portal.per_client_month') }}</div>
      </VCardText>
      <VCardActions v-if="owner"><VSpacer /><VBtn color="primary" :loading="form.processing" @click="save">{{ t('reseller_portal.save') }}</VBtn></VCardActions>
    </VCard>
  </div>
</template>
