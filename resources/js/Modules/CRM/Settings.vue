<script lang="ts" setup>
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  whatsapp: { phone_number_id: string | null; base_url: string; token_set: boolean; token_hint: string | null; configured: boolean }
  importRules: { fields: Record<string, string[]>; required: Record<string, string[]> }
}>()

const testForm = useForm({ to: '', message: '' })
function sendTest() { testForm.post(route('crm.settings.whatsapp.test'), { preserveScroll: true }) }

// Import rules — required fields per entity (dynamic per company).
const entityLabels: Record<string, string> = { lead: 'Leads', contact: 'Contacts', account: 'Accounts', deal: 'Deals' }
const rulesForm = useForm({
  required: Object.fromEntries(
    Object.keys(props.importRules.fields).map(e => [e, [...(props.importRules.required[e] ?? [])]]),
  ) as Record<string, string[]>,
})
function saveRules() { rulesForm.patch(route('crm.settings.import-rules'), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <h5 class="text-h5 font-weight-semibold mb-1">{{ t('crm_settings_index.page_title') }}</h5>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ t('crm_settings_index.description') }}</p>

    <VRow>
      <VCol cols="12" md="7">
        <VCard><VCardTitle class="d-flex align-center"><VIcon icon="tabler-brand-whatsapp" color="success" class="mr-2" /> {{ t('crm_settings_index.whatsapp_title') }}<VSpacer /><VChip size="small" :color="whatsapp.configured ? 'success' : 'blue-grey'" :label="whatsapp.configured ? t('crm_settings_index.connected') : t('crm_settings_index.not_configured')" /></VCardTitle>
        <VCardText><VAlert type="info" variant="tonal" class="mb-3">{{ t('crm_settings_index.whatsapp_info') }}</VAlert><VBtn color="primary" variant="tonal" prepend-icon="tabler-plug" :href="route('crm.integrations')">{{ t('crm_settings_index.open_integrations') }}</VBtn></VCardText></VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard :title="t('crm_settings_index.test_title')"><VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_settings_index.test_description') }}</p>
          <VTextField v-model="testForm.to" :label="t('crm_settings_index.to_label')" class="mb-2" />
          <VTextField v-model="testForm.message" :label="t('crm_settings_index.message_label')" class="mb-2" />
          <VBtn color="success" variant="tonal" :loading="testForm.processing" :disabled="!testForm.to || !whatsapp.configured" @click="sendTest">{{ t('crm_settings_index.send_test') }}</VBtn>
          <p v-if="!whatsapp.configured" class="text-caption text-medium-emphasis mt-2">{{ t('crm_settings_index.configure_first') }}</p>
        </VCardText></VCard>
      </VCol>

      <!-- Import rules: required fields per import type -->
      <VCol cols="12">
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon icon="tabler-database-cog" class="mr-2" /> Import rules — required fields
            <VSpacer />
            <VBtn color="primary" size="small" :loading="rulesForm.processing" @click="saveRules">Save rules</VBtn>
          </VCardTitle>
          <VCardText>
            <VAlert type="info" variant="tonal" density="compact" class="mb-4">
              Choose which fields are <strong>mandatory</strong> for each import type. Once set, any import missing a required
              column — or any row missing a required value — is blocked, keeping out incomplete/corrupt data. Rules apply to every future import.
            </VAlert>
            <VRow>
              <VCol v-for="(fields, entity) in importRules.fields" :key="entity" cols="12" md="6" lg="3">
                <div class="font-weight-medium mb-2">{{ entityLabels[entity] ?? entity }}</div>
                <VCheckbox v-for="f in fields" :key="f" v-model="rulesForm.required[entity]" :value="f" :label="f"
                  density="compact" hide-details />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>