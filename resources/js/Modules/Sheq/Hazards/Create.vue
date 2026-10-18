<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps<{
  sites: { id: number; name: string }[]
  categories: string[]
  allowAnonymous: boolean
}>()

/**
 * SHEQ-HAZ-1 asks for three taps: camera, describe, submit. Only the description is required here —
 * a person standing next to a missing handrail should not be filling in a form. Everything else is
 * a convenience for the practitioner who triages it.
 */
const form = useForm({
  description: '',
  site_id: null as number | null,
  hazard_category: null as string | null,
  location_text: '',
  severity_est: null as number | null,
  is_anonymous: false,
  client_id: crypto.randomUUID(),   // offline idempotency (SHEQ-OFF-2)
})

function submit() {
  form.post('/sheq/hazards', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.hazard.report_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.hazard.report_subtitle') }}</p>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" md="8">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <VTextarea
                v-model="form.description"
                :label="t('sheq.hazard.what_is_wrong')"
                :placeholder="t('sheq.hazard.what_is_wrong_hint')"
                :error-messages="form.errors.description"
                rows="4" variant="outlined" autofocus class="mb-4"
              />

              <VRow>
                <VCol cols="12" sm="6">
                  <VSelect v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                           :label="t('sheq.hazard.site')" variant="outlined" clearable />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.location_text" :label="t('sheq.hazard.where')"
                              :placeholder="t('sheq.hazard.where_hint')" variant="outlined" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect v-model="form.hazard_category" :items="categories" clearable
                           :label="t('sheq.hazard.category')" variant="outlined" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect v-model="form.severity_est" :items="[1, 2, 3, 4, 5]" clearable
                           :label="t('sheq.hazard.how_bad')" variant="outlined"
                           :hint="t('sheq.hazard.how_bad_hint')" persistent-hint />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <VAlert type="success" variant="tonal" density="comfortable" class="mb-4">
            <!-- SHEQ-HAZ-4: the reporter is told what happened. Saying so up front is part of why
                 people keep reporting. -->
            <div class="text-body-2">{{ t('sheq.hazard.you_will_hear_back') }}</div>
          </VAlert>

          <VCard v-if="allowAnonymous" elevation="0" border rounded="lg" class="mb-4">
            <VCardText>
              <VCheckbox v-model="form.is_anonymous" density="compact" hide-details
                         :label="t('sheq.incident.anonymous')" />
              <p class="text-caption text-medium-emphasis mb-0">{{ t('sheq.incident.anonymous_hint') }}</p>
            </VCardText>
          </VCard>

          <VBtn type="submit" color="primary" block size="large" :loading="form.processing">
            {{ t('sheq.hazard.submit') }}
          </VBtn>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
