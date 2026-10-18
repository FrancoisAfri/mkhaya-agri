<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The practice's own invoicing details.
 *
 * Entered once and copied onto an invoice when it is opened — never read live, so changing bank
 * next year cannot restate an invoice already issued. The page says so, because "why didn't my
 * old invoices update?" is otherwise a support call.
 */
const props = defineProps<{
  settings: Record<string, any>
  people: Array<{ id: number; label: string }>
}>()

const form = useForm({
  consultant_name: props.settings.consultant_name ?? '',
  consultant_address: props.settings.consultant_address ?? '',
  consultant_reference: props.settings.consultant_reference ?? '',
  consultant_vat_number: props.settings.consultant_vat_number ?? '',
  vendor_number: props.settings.vendor_number ?? '',

  banking_name: props.settings.banking_name ?? '',
  banking_account_name: props.settings.banking_account_name ?? '',
  banking_account_number: props.settings.banking_account_number ?? '',
  banking_branch_code: props.settings.banking_branch_code ?? '',

  account_description: props.settings.account_description ?? '',
  // Whether VAT applies at all. Asked once here rather than implied by a rate, because "not
  // registered" and "registered at zero per cent" are different statements about a practice.
  vat_registered: props.settings.vat_registered ?? true,
  implementing_agent: props.settings.implementing_agent ?? false,
  default_project_funder: props.settings.default_project_funder ?? '',
  // Held as a percentage on screen, stored as the proportion the engine works in.
  vat_rate: props.settings.vat_rate ? String(Number(props.settings.vat_rate) * 100) : '15',

  // Who hears about a monthly report the moment it is issued.
  report_recipient_ids: (props.settings.report_recipient_ids ?? []) as number[],
  report_recipient_emails: props.settings.report_recipient_emails ?? '',
})

function save() {
  form
    .transform(data => ({
      ...Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])),
      vat_registered: data.vat_registered,
      vat_rate: data.vat_rate === '' ? null : Number(data.vat_rate) / 100,
    }))
    .put(route('project-management.setup.invoicing.update'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.invoicing_setup.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('project_management.invoicing_setup.subtitle') }}
      </p>
    </div>

    <VAlert type="info" variant="tonal" density="comfortable" class="mb-4" style="max-width: 62rem">
      {{ t('project_management.invoicing_setup.copied_note') }}
    </VAlert>

    <VCard border style="max-width: 62rem">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing.the_consultant') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing_setup.consultant_sub') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.consultant_name"
              :label="t('project_management.invoicing.consultant_name')"
              :error-messages="form.errors.consultant_name"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model="form.consultant_vat_number"
              :label="t('project_management.invoicing.consultant_vat_number')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model="form.vendor_number"
              :label="t('project_management.invoicing.vendor_number')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="8">
            <VTextarea
              v-model="form.consultant_address" rows="2"
              :label="t('project_management.invoicing.consultant_address')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.consultant_reference"
              :label="t('project_management.invoicing.consultant_reference')"
              variant="outlined" density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border class="mt-4" style="max-width: 62rem">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing.banking') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing_setup.banking_sub') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.banking_name"
              :label="t('project_management.invoicing.banking_name')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.banking_account_name"
              :label="t('project_management.invoicing.banking_account_name')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.banking_account_number"
              :label="t('project_management.invoicing.banking_account_number')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.banking_branch_code"
              :label="t('project_management.invoicing.banking_branch_code')"
              variant="outlined" density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border class="mt-4" style="max-width: 62rem">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing_setup.defaults') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing_setup.defaults_sub') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.account_description"
              :label="t('project_management.invoicing_setup.account_description')"
              :hint="t('project_management.invoicing_setup.account_description_hint')" persistent-hint
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="3">
            <!-- Off for most practices. Switching it on adds the funder's payment certificate as
                 an optional fourth document on an invoice — never automatically. -->
            <VSwitch
              v-model="form.implementing_agent" color="primary" density="compact"
              :label="t('project_management.invoicing_setup.implementing_agent')"
              :hint="t('project_management.invoicing_setup.implementing_agent_hint')" persistent-hint
            />
          </VCol>
          <VCol v-if="form.implementing_agent" cols="12" md="3">
            <VTextField
              v-model="form.default_project_funder"
              :label="t('project_management.invoicing_setup.default_project_funder')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSwitch
              v-model="form.vat_registered" color="primary" density="compact"
              :label="t('project_management.invoicing_setup.vat_registered')"
              :hint="t('project_management.invoicing_setup.vat_registered_hint')" persistent-hint
            />
          </VCol>
          <VCol cols="12" md="3">
            <!-- Disabled rather than hidden: a practice that is not registered should still see
                 the rate that would apply, not a field that vanishes. -->
            <VTextField
              v-model="form.vat_rate" inputmode="decimal" suffix="%"
              :disabled="!form.vat_registered"
              :label="t('project_management.invoicing_setup.vat_rate')"
              :hint="t('project_management.invoicing_setup.vat_rate_hint')" persistent-hint
              :error-messages="form.errors.vat_rate"
              variant="outlined" density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border class="mt-4" style="max-width: 62rem">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing_setup.recipients') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing_setup.recipients_sub') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="7">
            <VAutocomplete
              v-model="form.report_recipient_ids"
              :items="props.people.map(p => ({ title: p.label, value: p.id }))"
              multiple chips closable-chips
              :label="t('project_management.invoicing_setup.recipient_users')"
              variant="outlined" density="compact"
            />
          </VCol>
          <VCol cols="12" md="5">
            <VTextarea
              v-model="form.report_recipient_emails" rows="3"
              :label="t('project_management.invoicing_setup.recipient_emails')"
              :hint="t('project_management.invoicing_setup.recipient_emails_hint')" persistent-hint
              variant="outlined" density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" @click="save">
          {{ t('project_management.actions.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
