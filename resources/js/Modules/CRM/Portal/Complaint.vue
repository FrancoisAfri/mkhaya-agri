<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

defineOptions({ layout: null })

defineProps<{ company: string; logo: string | null }>()

const page = usePage()
const submitted = ref(false)
const form = useForm({ subject: '', body: '', customer_name: '', customer_email: '', customer_phone: '' })

function submit() { form.post(route('crm.portal.feedback.store'), { preserveScroll: true, onSuccess: () => { submitted.value = true; form.reset() } }) }
</script>

<template>
  <VApp>
    <VMain style="min-height: 100vh; background: linear-gradient(135deg,#f5f5f7,#ececf4)">
      <VContainer class="py-10" style="max-width: 720px">
        <div class="text-center mb-6"><img v-if="logo" :src="logo" :alt="company" style="max-height: 64px; max-width: 240px" class="mb-3" /><h4 class="text-h4 font-weight-bold">{{ company }}</h4><p class="text-body-1 text-medium-emphasis">{{ t('crm_portal_feedback_index.description') }}</p></div>

        <VCard><VCardText class="pa-6">
          <VAlert v-if="submitted" type="success" variant="tonal" class="mb-4">{{ (page.props.flash as any)?.success ?? t('crm_portal_feedback_index.thank_you') }}</VAlert>
          <template v-if="!submitted">
            <VTextField v-model="form.subject" :label="t('crm_portal_feedback_index.subject_label')" class="mb-3" :error-messages="form.errors.subject" />
            <VTextarea v-model="form.body" :label="t('crm_portal_feedback_index.body_label')" rows="5" class="mb-3" :error-messages="form.errors.body" :hint="t('crm_portal_feedback_index.body_hint')" persistent-hint />
            <VRow><VCol cols="12" sm="6"><VTextField v-model="form.customer_name" :label="t('crm_portal_feedback_index.your_name')" :error-messages="form.errors.customer_name" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.customer_email" :label="t('crm_portal_feedback_index.your_email')" :error-messages="form.errors.customer_email" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.customer_phone" :label="t('crm_portal_feedback_index.phone_optional')" /></VCol></VRow>
            <VBtn block color="primary" size="large" class="mt-2" :loading="form.processing" :disabled="!form.subject || !form.body || !form.customer_name || !form.customer_email" @click="submit">{{ t('crm_portal_feedback_index.submit') }}</VBtn>
          </template>
          <div v-else class="text-center"><VBtn variant="tonal" @click="submitted = false">{{ t('crm_portal_feedback_index.submit_more') }}</VBtn></div>
        </VCardText></VCard>
        <p class="text-center text-caption text-medium-emphasis mt-4">{{ t('crm_portal_feedback_index.footer') }}</p>
      </VContainer>
    </VMain>
  </VApp>
</template>