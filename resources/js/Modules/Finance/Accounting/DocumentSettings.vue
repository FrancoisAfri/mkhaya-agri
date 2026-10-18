<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Settings { company_name: string | null; trading_name: string | null; address: string | null; email: string | null; phone: string | null; website: string | null; vat_number: string | null; registration_number: string | null; logo: string | null; bank_name: string | null; bank_account_name: string | null; bank_account_number: string | null; branch_code: string | null; swift: string | null; default_message: string | null; reminders_enabled: boolean; reminder_days: number[] | null }
const props = defineProps<{ settings: Settings; business?: string | null }>()

const logoFile = ref<File | File[] | null>(null)
const form = useForm({
  company_name: props.settings.company_name ?? '', trading_name: props.settings.trading_name ?? '',
  address: props.settings.address ?? '', email: props.settings.email ?? '', phone: props.settings.phone ?? '',
  website: props.settings.website ?? '', vat_number: props.settings.vat_number ?? '', registration_number: props.settings.registration_number ?? '',
  logo: props.settings.logo ?? null as string | null,
  bank_name: props.settings.bank_name ?? '', bank_account_name: props.settings.bank_account_name ?? '',
  bank_account_number: props.settings.bank_account_number ?? '', branch_code: props.settings.branch_code ?? '', swift: props.settings.swift ?? '',
  default_message: props.settings.default_message ?? '',
  default_terms: (props.settings as any).default_terms ?? '',
  reminders_enabled: props.settings.reminders_enabled ?? false,
  reminder_days: (props.settings.reminder_days ?? [7, 14, 30]) as number[],
  show_payment_qr: (props.settings as any).show_payment_qr ?? true,
  snapscan_merchant: (props.settings as any).snapscan_merchant ?? '',
})

function onLogo(file: File | File[] | null) {
  const f = Array.isArray(file) ? file[0] : file
  if (!f) return
  const reader = new FileReader()
  reader.onload = () => { form.logo = reader.result as string }
  reader.readAsDataURL(f)
}
function save() { form.post(route('finance.accounting.settings.save'), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
      <h2 class="text-h5 mb-0 d-flex align-center flex-wrap gap-2">
        {{ t('acc.company_details') }}
        <!-- Whose letterhead this is. Without it there is no way to tell from the page that the
             VAT number and bank account being edited belong to the business you meant. -->
        <VChip v-if="business" size="small" color="primary" variant="tonal" prepend-icon="mdi-domain">
          {{ business }}
        </VChip>
      </h2>
      <div class="text-caption text-medium-emphasis">{{ t('acc.doc_settings_sub') }}</div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('acc.business') }}</VCardTitle>
          <VCardText>
            <div class="d-flex gap-3 mb-3">
              <VTextField v-model="form.company_name" :label="t('acc.company_name')" variant="outlined" density="comfortable" />
              <VTextField v-model="form.trading_name" :label="t('acc.trading_name')" variant="outlined" density="comfortable" />
            </div>
            <VTextarea v-model="form.address" :label="t('acc.address')" variant="outlined" density="comfortable" rows="2" auto-grow class="mb-3" />
            <div class="d-flex gap-3 mb-3">
              <VTextField v-model="form.email" :label="t('acc.email_label')" variant="outlined" density="comfortable" :error-messages="form.errors.email" />
              <VTextField v-model="form.phone" :label="t('acc.phone')" variant="outlined" density="comfortable" />
              <VTextField v-model="form.website" :label="t('acc.website')" variant="outlined" density="comfortable" />
            </div>
            <div class="d-flex gap-3">
              <VTextField v-model="form.vat_number" :label="t('acc.vat_number_label')" variant="outlined" density="comfortable" />
              <VTextField v-model="form.registration_number" :label="t('acc.company_reg_no')" variant="outlined" density="comfortable" />
            </div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('acc.banking_details') }}</VCardTitle>
          <VCardText>
            <div class="d-flex gap-3 mb-3">
              <VTextField v-model="form.bank_name" :label="t('acc.bank')" variant="outlined" density="comfortable" />
              <VTextField v-model="form.bank_account_name" :label="t('acc.account_holder')" variant="outlined" density="comfortable" />
            </div>
            <div class="d-flex gap-3">
              <VTextField v-model="form.bank_account_number" :label="t('acc.account_no')" variant="outlined" density="comfortable" />
              <VTextField v-model="form.branch_code" :label="t('acc.branch_code')" variant="outlined" density="comfortable" style="max-width: 180px" />
              <VTextField v-model="form.swift" :label="t('acc.swift')" variant="outlined" density="comfortable" style="max-width: 180px" />
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('acc.logo') }}</VCardTitle>
          <VCardText>
            <div class="d-flex align-center gap-4 mb-3">
              <VAvatar v-if="form.logo" :image="form.logo" size="96" rounded="lg" />
              <VAvatar v-else size="96" rounded="lg" color="grey-lighten-3"><VIcon icon="tabler-building" /></VAvatar>
              <div>
                <VFileInput v-model="logoFile" :label="t('acc.upload_logo')" accept="image/*" variant="outlined" density="comfortable" prepend-icon="tabler-photo" hide-details @update:model-value="onLogo" />
                <VBtn v-if="form.logo" size="small" variant="text" color="error" class="mt-1" @click="form.logo = null; logoFile = null">{{ t('acc.remove_logo') }}</VBtn>
              </div>
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('acc.logo_hint') }}</div>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('acc.default_message') }}</VCardTitle>
          <VCardText>
            <VTextarea v-model="form.default_message" :label="t('acc.message_on_docs')" variant="outlined" density="comfortable" rows="4" auto-grow placeholder="e.g. Thank you for your business. Banking: FNB, Acc 62799179083…" />
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1 d-flex align-center justify-space-between flex-wrap gap-2">
            <span>{{ t('acc.terms_conditions') }}</span>
            <a :href="route('finance.accounting.templates')" class="text-caption text-primary text-decoration-none">{{ t('acc.templates_title') }} →</a>
          </VCardTitle>
          <VCardText>
            <VTextarea v-model="form.default_terms" :label="t('acc.terms_conditions')" :placeholder="t('acc.terms_ph')" variant="outlined" density="comfortable" rows="4" auto-grow />
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('acc.auto_reminders') }}</VCardTitle>
          <VCardText>
            <VSwitch v-model="form.reminders_enabled" :label="t('acc.auto_email_overdue')" color="primary" hide-details class="mb-3" />
            <VCombobox v-model="form.reminder_days" :label="t('acc.days_overdue_remind')" variant="outlined" density="comfortable" multiple chips :disabled="!form.reminders_enabled" :hint="t('acc.reminder_days_hint')" persistent-hint type="number" />
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('acc.payment_qr') }}</VCardTitle>
          <VCardText>
            <VSwitch v-model="form.show_payment_qr" :label="t('acc.show_payment_qr')" color="primary" hide-details class="mb-3" />
            <VTextField v-model="form.snapscan_merchant" :label="t('acc.snapscan_merchant')" variant="outlined" density="comfortable" :disabled="!form.show_payment_qr" :hint="t('acc.snapscan_hint')" persistent-hint />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div class="d-flex justify-end mt-4">
      <VBtn color="primary" size="large" :loading="form.processing" @click="save">{{ t('acc.save_company_details') }}</VBtn>
    </div>
  </div>
</template>
