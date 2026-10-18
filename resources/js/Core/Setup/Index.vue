<script setup lang="ts">
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import type CompanyIdentity from './type'
import type { CompanyUser } from './type'
import { useI18n } from 'vue-i18n'
import './style.css'

interface SmsConfig {
  provider: string
  sms_username: string
  is_active: boolean
  two_factor_enabled: boolean
  has_password: boolean
}

interface ApiKeys {
  google_maps_api_key: string
}

// ── Props ──
const props = defineProps<{
  company:         CompanyIdentity
  managers:        number[]
  payrollOfficers: number[]
  users:           CompanyUser[]
  smsConfig:       SmsConfig | null
  apiKeys:         ApiKeys
  whiteLabel?:     boolean
  logoUrl?:        string | null
}>()

const { t } = useI18n()

// ── Form ──
const form = useForm({
  company_name:            props.company?.company_name            ?? '',
  full_company_name:       props.company?.full_company_name       ?? '',
  header_name_bold:        props.company?.header_name_bold        ?? '',
  header_name_regular:     props.company?.header_name_regular     ?? '',
  header_acronym_bold:     props.company?.header_acronym_bold     ?? '',
  header_acronym_regular:  props.company?.header_acronym_regular  ?? '',
  company_website:         props.company?.company_website         ?? '',
  mailing_name:            props.company?.mailing_name            ?? '',
  mailing_address:         props.company?.mailing_address         ?? '',
  support_email:           props.company?.support_email           ?? '',
  password_expiring_month: props.company?.password_expiring_month ?? 12,
  brought_to_text:         props.company?.brought_to_text         ?? '',
  managers:                props.managers        ?? [] as number[],
  payroll_officers:        props.payrollOfficers ?? [] as number[],
  company_logo:            null as File | null,
  login_background_image:  null as File | null,
})

// ── Logo ── (stored in the DB; served via the branding route)
// Prefer the tenant's own saved logo so it always shows here once uploaded, even when
// the white-label branding share is off; fall back to the shared branding logo.
const logoPreview = ref<string | null>(
  (props as any).logoUrl || (usePage().props as any).branding?.system_logo || null
)
const bgPreview = ref<string | null>(
  props.company?.login_background_image ? `/storage/${props.company.login_background_image}` : null
)

const removeLogo = () => { logoPreview.value = null; form.company_logo = null }
const removeBg   = () => { bgPreview.value   = null; form.login_background_image = null }

const onLogoChange = (e: Event) => {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  form.company_logo = file
  logoPreview.value = URL.createObjectURL(file)
}
const onBgChange = (e: Event) => {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  form.login_background_image = file
  bgPreview.value = URL.createObjectURL(file)
}

const requiredValidator = (v: unknown) =>
  (v !== null && v !== undefined && v !== '') || t('required_field')

// ── SMS Config form ─────────────────────────────────────────────────────────
const smsForm = useForm({
  provider:           props.smsConfig?.provider           ?? 'bulksms',
  sms_username:       props.smsConfig?.sms_username       ?? '',
  sms_password:       '',
  is_active:          props.smsConfig?.is_active          ?? true,
  two_factor_enabled: props.smsConfig?.two_factor_enabled ?? false,
})

const showSmsPassword  = ref(false)
const smsProviders = [{ title: 'BulkSMS (bulksms.2way.co.za)', value: 'bulksms' }]

// ── API Keys form ──────────────────────────────────────────────────────────────
const apiKeysForm = useForm({
  google_maps_api_key: props.apiKeys?.google_maps_api_key ?? '',
})
const showMapsKey = ref(false)

// ── Submit — saves company, SMS, and API keys in one click ──
const onSubmit = () => {
  smsForm.post('/settings/sms-config', { preserveScroll: true })
  apiKeysForm.post('/settings/setup/api-keys', { preserveScroll: true })

  form.transform((data) => {
    const fd = new FormData()
    Object.keys(data).forEach(key => {
      const val = (data as any)[key]
      if (key === 'company_logo' || key === 'login_background_image') return
      if (key === 'managers' || key === 'payroll_officers') {
        ;(val as number[]).forEach((id, i) => fd.append(`${key}[${i}]`, String(id)))
        return
      }
      if (val !== null && val !== undefined) fd.append(key, val)
    })
    if (form.company_logo instanceof File) fd.append('company_logo', form.company_logo)
    if (form.login_background_image instanceof File) fd.append('login_background_image', form.login_background_image)
    return fd
  }).post('/settings/setup/update', {
    preserveScroll: true,
    onSuccess: () => { if (form.company_logo) window.location.reload() },
  })
}

const onCancel = () => window.history.back()
</script>

<template>
  <section class="company-page">
    <VContainer fluid class="pa-6">

      <!-- Header -->
      <div class="page-header mb-8">
        <div class="header-left">
          <h1 class="page-title">{{ t('company_identity') }}</h1>
          <p class="page-subtitle">{{ t('company_identity_subtitle') }}</p>
        </div>
        <VBreadcrumbs
          :items="[{ title: t('settings'), disabled: false }, { title: t('company_identity'), disabled: true }]"
          density="compact" class="pa-0 breadcrumb-custom"
        >
          <template #divider>
            <i class="fa-solid fa-chevron-right" style="font-size:10px;opacity:.4" />
          </template>
        </VBreadcrumbs>
      </div>

      <VForm @submit.prevent="onSubmit">
        <VRow :gutter="6">

          <!-- ══ LEFT ══ -->
          <VCol cols="12" lg="7">

            <!-- Section: Identity -->
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon"><i class="fa-solid fa-building" /></div>
                <div>
                  <p class="section-title">{{ t('company_details') }}</p>
                  <p class="section-desc">{{ t('company_details_desc') }}</p>
                </div>
              </div>
              <VRow dense>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('company_name') }}</label>
                    <VTextField v-model="form.company_name" :error-messages="form.errors.company_name"
                      variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('full_company_name') }}</label>
                    <VTextField v-model="form.full_company_name" :error-messages="form.errors.full_company_name"
                      variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('company_website') }}</label>
                    <VTextField v-model="form.company_website" :error-messages="form.errors.company_website"
                      variant="outlined" density="compact" prepend-inner-icon="tabler-world" class="field-input" />
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- Section: Header Display -->
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon accent-orange"><i class="fa-solid fa-heading" /></div>
                <div>
                  <p class="section-title">{{ t('header_display') }}</p>
                  <p class="section-desc">{{ t('header_display_desc') }}</p>
                </div>
              </div>
              <div class="header-preview mb-4">
                <span class="preview-bold">{{ form.header_name_bold }}</span>
                <span class="preview-regular">{{ form.header_acronym_bold }}</span>
              </div>
              <VRow dense>
                <VCol cols="6">
                  <div class="field-group">
                    <label class="field-label">{{ t('name_bold') }} <span class="required">*</span></label>
                    <VTextField v-model="form.header_name_bold" :rules="[requiredValidator]"
                      :error-messages="form.errors.header_name_bold" variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="6">
                  <div class="field-group">
                    <label class="field-label">{{ t('acronym_bold') }} <span class="required">*</span></label>
                    <VTextField v-model="form.header_acronym_bold" :rules="[requiredValidator]"
                      :error-messages="form.errors.header_acronym_bold" variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="6">
                  <div class="field-group">
                    <label class="field-label">{{ t('name_regular') }} <span class="required">*</span></label>
                    <VTextField v-model="form.header_name_regular" :rules="[requiredValidator]"
                      :error-messages="form.errors.header_name_regular" variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="6">
                  <div class="field-group">
                    <label class="field-label">{{ t('acronym_regular') }} <span class="required">*</span></label>
                    <VTextField v-model="form.header_acronym_regular" :rules="[requiredValidator]"
                      :error-messages="form.errors.header_acronym_regular" variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- Section: Contact & System -->
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon accent-teal"><i class="fa-solid fa-envelope" /></div>
                <div>
                  <p class="section-title">{{ t('contact_system') }}</p>
                  <p class="section-desc">{{ t('contact_system_desc') }}</p>
                </div>
              </div>
              <VRow dense>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('mailing_name') }}</label>
                    <VTextField v-model="form.mailing_name" :error-messages="form.errors.mailing_name"
                      variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('mailing_address') }}</label>
                    <VTextField v-model="form.mailing_address" :error-messages="form.errors.mailing_address"
                      variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12" sm="8">
                  <div class="field-group">
                    <label class="field-label">{{ t('support_email') }}</label>
                    <VTextField v-model="form.support_email" :error-messages="form.errors.support_email"
                      variant="outlined" density="compact" prepend-inner-icon="tabler-mail" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="field-group">
                    <label class="field-label">{{ t('password_duration') }} <span class="text-caption text-medium-emphasis">({{ t('months') }})</span></label>
                    <VTextField v-model="form.password_expiring_month" :error-messages="form.errors.password_expiring_month"
                      variant="outlined" density="compact" type="number" class="field-input" />
                  </div>
                </VCol>
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">{{ t('physical_address') }}</label>
                    <VTextField v-model="form.brought_to_text" :error-messages="form.errors.brought_to_text"
                      variant="outlined" density="compact" class="field-input" />
                  </div>
                </VCol>
              </VRow>
            </div>

            <!-- ✅ Section: People — Managers & Payroll -->
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon accent-indigo"><i class="fa-solid fa-users-gear" /></div>
                <div>
                  <p class="section-title">{{ t('company_people') ?? 'Company People' }}</p>
                  <p class="section-desc">{{ t('company_people_desc') ?? 'Assign managers and payroll officers' }}</p>
                </div>
              </div>

              <VRow dense>

                <!-- Managers -->
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">
                      <i class="fa-solid fa-shield-halved me-1" style="color:#6366f1;font-size:11px" />
                      Hr Managers
                    </label>
                    <VAutocomplete
                      v-model="form.managers"
                      :items="users"
                      item-title="name"
                      item-value="id"
                      multiple
                      chips
                      closable-chips
                      variant="outlined"
                      density="compact"
                      placeholder="Select managers..."
                      no-data-text="No users found"
                      class="field-input"
                    >
                      <!-- Chip personnalisé -->
                      <template #chip="{ item, props: cp }">
                        <VChip v-bind="cp" size="small" color="indigo" variant="tonal" class="font-weight-medium">
                          <template #prepend>
                            <div class="chip-avatar me-1">
                              <img v-if="item.raw.avatar" :src="item.raw.avatar" class="chip-img" @error="(e: any) => e.target.style.display='none'" />
                              <span v-else class="chip-initial">{{ item.raw.initial }}</span>
                            </div>
                          </template>
                          {{ item.raw.name }}
                        </VChip>
                      </template>

                      <!-- Item dans la liste -->
                      <template #item="{ item, props: ip }">
                        <VListItem v-bind="ip" :title="undefined">
                          <template #prepend>
                            <div class="list-avatar me-3">
                              <img v-if="item.raw.avatar" :src="item.raw.avatar" class="list-avatar-img" @error="(e: any) => e.target.style.display='none'" />
                              <span v-else class="list-avatar-initial" style="background:#6366f120;color:#6366f1">{{ item.raw.initial }}</span>
                            </div>
                          </template>
                          <VListItemTitle class="text-body-2 font-weight-medium">{{ item.raw.name }}</VListItemTitle>
                          <VListItemSubtitle class="text-caption">{{ item.raw.email }}</VListItemSubtitle>
                        </VListItem>
                      </template>
                    </VAutocomplete>
                  </div>
                </VCol>

                <!-- Payroll Officers -->
                <VCol cols="12">
                  <div class="field-group">
                    <label class="field-label">
                      <i class="fa-solid fa-money-check-dollar me-1" style="color:#0d9488;font-size:11px" />
                      Payroll Officers
                    </label>
                    <VAutocomplete
                      v-model="form.payroll_officers"
                      :items="users"
                      item-title="name"
                      item-value="id"
                      multiple
                      chips
                      closable-chips
                      variant="outlined"
                      density="compact"
                      placeholder="Select payroll officers..."
                      no-data-text="No users found"
                      class="field-input"
                    >
                      <template #chip="{ item, props: cp }">
                        <VChip v-bind="cp" size="small" color="teal" variant="tonal" class="font-weight-medium">
                          <template #prepend>
                            <div class="chip-avatar me-1">
                              <img v-if="item.raw.avatar" :src="item.raw.avatar" class="chip-img" @error="(e: any) => e.target.style.display='none'" />
                              <span v-else class="chip-initial">{{ item.raw.initial }}</span>
                            </div>
                          </template>
                          {{ item.raw.name }}
                        </VChip>
                      </template>

                      <template #item="{ item, props: ip }">
                        <VListItem v-bind="ip" :title="undefined">
                          <template #prepend>
                            <div class="list-avatar me-3">
                              <img v-if="item.raw.avatar" :src="item.raw.avatar" class="list-avatar-img" @error="(e: any) => e.target.style.display='none'" />
                              <span v-else class="list-avatar-initial" style="background:#0d948820;color:#0d9488">{{ item.raw.initial }}</span>
                            </div>
                          </template>
                          <VListItemTitle class="text-body-2 font-weight-medium">{{ item.raw.name }}</VListItemTitle>
                          <VListItemSubtitle class="text-caption">{{ item.raw.email }}</VListItemSubtitle>
                        </VListItem>
                      </template>
                    </VAutocomplete>
                  </div>
                </VCol>

              </VRow>
            </div>

          </VCol>

          <!-- ══ RIGHT — Media ══ -->
          <VCol cols="12" lg="5">
            <div class="media-column">

              <!-- Logo -->
              <div class="form-section mb-6">
                <div class="section-header mb-3">
                  <div class="section-icon accent-purple"><i class="fa-solid fa-image" /></div>
                  <div>
                    <p class="section-title">{{ t('company_logo') }}</p>
                    <p class="section-desc">{{ t('company_logo_desc') }}</p>
                  </div>
                </div>
                <template v-if="whiteLabel">
                  <div class="dropzone" :class="{ 'dropzone--filled': logoPreview }"
                    @click="($refs.logoInput as HTMLInputElement).click()">
                    <img v-if="logoPreview" :src="logoPreview" class="dropzone-img contain" />
                    <div v-else class="dropzone-placeholder">
                      <div class="dropzone-icon"><i class="fa-solid fa-cloud-arrow-up" /></div>
                      <p class="dropzone-text">{{ t('click_upload') }}</p>
                      <p class="dropzone-hint">PNG, JPG up to 2MB</p>
                    </div>
                    <button v-if="logoPreview" type="button" class="dropzone-remove" @click.stop="removeLogo">
                      <i class="fa-solid fa-xmark" />
                    </button>
                  </div>
                  <input ref="logoInput" type="file" accept="image/*" style="display:none" @change="onLogoChange" />
                </template>
                <VAlert v-else type="info" variant="tonal" density="compact" class="mt-1">
                  Custom branding (your own logo in the system) is a white-label feature. Contact us to enable it for your account.
                </VAlert>
              </div>

              <!-- Background -->
              <div class="form-section">
                <div class="section-header mb-3">
                  <div class="section-icon accent-indigo"><i class="fa-solid fa-panorama" /></div>
                  <div>
                    <p class="section-title">{{ t('login_background') }}</p>
                    <p class="section-desc">{{ t('login_background_desc') }}</p>
                  </div>
                </div>
                <div class="dropzone" :class="{ 'dropzone--filled': bgPreview }"
                  @click="($refs.bgInput as HTMLInputElement).click()">
                  <img v-if="bgPreview" :src="bgPreview" class="dropzone-img cover" />
                  <div v-else class="dropzone-placeholder">
                    <div class="dropzone-icon"><i class="fa-solid fa-cloud-arrow-up" /></div>
                    <p class="dropzone-text">{{ t('click_upload') }}</p>
                    <p class="dropzone-hint">PNG, JPG up to 5MB</p>
                  </div>
                  <button v-if="bgPreview" type="button" class="dropzone-remove" @click.stop="removeBg">
                    <i class="fa-solid fa-xmark" />
                  </button>
                </div>
                <input ref="bgInput" type="file" accept="image/*" style="display:none" @change="onBgChange" />
              </div>

            </div>
          </VCol>

        </VRow>

        <!-- ══ SMS / 2FA Configuration ══ -->
        <VRow class="mt-2">
          <VCol cols="12">
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">
                  <i class="fa-solid fa-message" style="color:#fff" />
                </div>
                <div>
                  <p class="section-title">{{ t('sms_two_factor_authentication') }}</p>
                  <p class="section-desc">{{ t('sms_two_factor_authentication_desc') }}</p>
                </div>
              </div>

              <VRow dense>
                <!-- Provider -->
                <VCol cols="12" sm="6">
                  <div class="field-group">
                    <label class="field-label">SMS Provider</label>
                    <VSelect
                      v-model="smsForm.provider"
                      :items="smsProviders"
                      item-title="title"
                      item-value="value"
                      variant="outlined"
                      density="compact"
                      prepend-inner-icon="tabler-message-dots"
                      class="field-input"
                      :error-messages="smsForm.errors.provider"
                    />
                  </div>
                </VCol>

                <!-- Active toggle -->
                <VCol cols="12" sm="6" class="d-flex align-center">
                  <VSwitch
                    v-model="smsForm.is_active"
                    label="SMS Active"
                    color="success"
                    density="compact"
                    hide-details
                    inset
                  />
                </VCol>

                <!-- Username -->
                <VCol cols="12" sm="6">
                  <div class="field-group">
                    <label class="field-label">{{ t('sms_username') }} <span class="required">*</span></label>
                    <VTextField
                      v-model="smsForm.sms_username"
                      variant="outlined"
                      density="compact"
                      prepend-inner-icon="tabler-user"
                      class="field-input"
                      :error-messages="smsForm.errors.sms_username"
                    />
                  </div>
                </VCol>

                <!-- Password -->
                <VCol cols="12" sm="6">
                  <div class="field-group">
                    <label class="field-label d-flex align-center gap-2">
                      {{ t('sms_password') }}
                      <VChip
                        v-if="smsConfig?.has_password"
                        size="x-small"
                        color="success"
                        variant="tonal"
                        prepend-icon="tabler-circle-check"
                        class="ms-1"
                      >
                     {{ t('password_saved') }}
                      </VChip>
                    </label>
                    <VTextField
                      v-model="smsForm.sms_password"
                      :type="showSmsPassword ? 'text' : 'password'"
                      variant="outlined"
                      density="compact"
                      prepend-inner-icon="tabler-lock"
                      :append-inner-icon="showSmsPassword ? 'tabler-eye-off' : 'tabler-eye'"
                      @click:append-inner="showSmsPassword = !showSmsPassword"
                      class="field-input"
                      autocomplete="new-password"
                      :placeholder="smsConfig?.has_password ? 'Leave blank to keep existing password' : 'Enter password'"
                      :error-messages="smsForm.errors.sms_password"
                    />
                  </div>
                </VCol>

                <!-- 2FA toggle -->
                <VCol cols="12">
                  <VCard variant="tonal" color="indigo" rounded="lg" class="pa-4">
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                      <div>
                        <div class="text-subtitle-2 font-weight-semibold">
                          <VIcon size="18" class="me-1">{{ t('sms_two_factor_authentication') }}</VIcon>
                          {{ t('sms_two_factor_authentication') }}
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">
                          {{ t('sms_two_factor_authentication_desc') }}
                        </div>
                      </div>
                      <VSwitch
                        v-model="smsForm.two_factor_enabled"
                        color="indigo"
                        density="compact"
                        hide-details
                        inset
                      />
                    </div>
                  </VCard>
                </VCol>
              </VRow>

            </div>
          </VCol>
        </VRow>

        <!-- ══ API Integrations ══ -->
        <VRow class="mt-2">
          <VCol cols="12">
            <div class="form-section mb-6">
              <div class="section-header mb-4">
                <div class="section-icon" style="background:linear-gradient(135deg,#0ea5e9,#2563eb)">
                  <i class="fa-solid fa-plug" style="color:#fff" />
                </div>
                <div>
                  <p class="section-title">{{ t('api_integrations') }}</p>
                  <p class="section-desc">{{ t('api_integrations_desc') }}</p>
                </div>
              </div>

              <VRow dense>
                <VCol cols="12" md="8">
                  <div class="field-group">
                    <label class="field-label d-flex align-center gap-2">
                      <i class="fa-brands fa-google me-1" style="color:#4285f4;font-size:12px" />
                      Google Maps API Key
                      <VChip v-if="apiKeysForm.google_maps_api_key" size="x-small" color="success" variant="tonal" prepend-icon="tabler-circle-check" class="ms-1">
                      {{ t('active') }}
                      </VChip>
                    </label>
                    <VTextField
                      v-model="apiKeysForm.google_maps_api_key"
                      :type="showMapsKey ? 'text' : 'password'"
                      variant="outlined"
                      density="compact"
                      prepend-inner-icon="tabler-map-pin"
                      :append-inner-icon="showMapsKey ? 'tabler-eye-off' : 'tabler-eye'"
                      @click:append-inner="showMapsKey = !showMapsKey"
                      class="field-input"
                      autocomplete="off"
                      placeholder="AIzaSy..."
                      :hint="t('google_maps_api_key_hint')"
                      persistent-hint
                      :error-messages="apiKeysForm.errors.google_maps_api_key"
                    />
                  </div>
                </VCol>
                <VCol cols="12" md="4" class="d-flex align-center pt-4">
                  <VAlert v-if="apiKeysForm.google_maps_api_key" type="success" variant="tonal" density="compact" class="w-100" style="font-size:0.8rem">
                    <i class="fas fa-check-circle me-1"></i>{{ t('google_maps_api_key_active') }}
                  </VAlert>
                  <VAlert v-else type="info" variant="tonal" density="compact" class="w-100" style="font-size:0.8rem">
                    <i class="fas fa-info-circle me-1"></i>{{ t('google_maps_api_key_inactive') }}
                  </VAlert>
                </VCol>
              </VRow>

            </div>
          </VCol>
        </VRow>

        <!-- Actions -->
        <VDivider class="my-6" />
        <div class="d-flex align-center gap-3">
          <button type="submit" class="btn-save" :disabled="form.processing">
            <span v-if="form.processing" class="btn-spinner">
              <VProgressCircular indeterminate size="16" width="2" color="white" />
            </span>
            <i v-else class="fa-solid fa-circle-check" />
            {{ form.processing ? t('saving..') : t('save_changes') }}
          </button>
          <button type="button" class="btn-cancel" :disabled="form.processing" @click="onCancel">
            <i class="fa-solid fa-xmark" /> {{ t('cancel') }}
          </button>
        </div>

      </VForm>

      <!-- Snackbar -->
      <VSnackbar v-model="form.recentlySuccessful" :timeout="3000" color="success" location="bottom right" rounded="xl">
        <div class="d-flex align-center gap-2">
          <i class="fa-solid fa-circle-check" style="font-size:16px" />
          {{ t('company_saved') }}
        </div>
      </VSnackbar>

    </VContainer>
  </section>
</template>


