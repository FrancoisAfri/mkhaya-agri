<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Doc {
  id: number
  type: string | null
  file_name: string | null
  expiry: string | null
}

interface Person {
  name: string
  email: string | null
  phone: string | null
}

interface DocumentType {
  id: number
  name: string
}

interface Supplier {
  company_name: string
  trading_name: string | null
  supplier_number: string | null
  company_registration_number: string | null
  vat_registration_number: string | null
  business_entity_type: string | null
  phone_number: string | null
  email: string | null
  website_url: string | null
  physical_address: string | null
  postal_address: string | null
  bank_name: string | null
  account_holder_name: string | null
  account_type: string | null
  bank_account_number: string | null
  branch_code: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  token: string
  supplier: Supplier
  contacts: Person[]
  directors: Person[]
  documents: Doc[]
  documentTypes: DocumentType[]
  /** Whether this supplier also works on site, and so has a SHEQ compliance page (CON-18). */
  safetyPortal?: boolean
}>()

// ── Helpers ────────────────────────────────────────────────────────────────────
const flash = () => (usePage().props as any).flash?.success

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

// ── Profile form ──────────────────────────────────────────────────────────────
const profile = useForm({
  phone_number: props.supplier.phone_number ?? '',
  email: props.supplier.email ?? '',
  website_url: props.supplier.website_url ?? '',
  physical_address: props.supplier.physical_address ?? '',
  postal_address: props.supplier.postal_address ?? '',
  bank_name: props.supplier.bank_name ?? '',
  account_holder_name: props.supplier.account_holder_name ?? '',
  account_type: props.supplier.account_type ?? '',
  bank_account_number: props.supplier.bank_account_number ?? '',
  branch_code: props.supplier.branch_code ?? '',
})

function saveProfile(): void {
  profile.patch(route('supplier.portal.update', { token: props.token }), { preserveScroll: true })
}

// ── Document upload ─────────────────────────────────────────────────────────────
const upload = useForm({
  file: null as File | null,
  document_type_id: null as number | null,
  expiry_date: '' as string,
})

function submitUpload(): void {
  upload.post(route('supplier.portal.documents.store', { token: props.token }), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => upload.reset(),
  })
}

function downloadDoc(id: number): void {
  window.location.href = route('supplier.portal.documents.download', { token: props.token, document: id })
}

function getDocumentTypeName(id: number | null): string {
  if (!id) return '—'
  const found = props.documentTypes.find(d => d.id === id)
  return found?.name || '—'
}
</script>

<template>
  <div class="portal-bg pa-4 d-flex justify-center">
    <div class="portal-wrap">
      <div class="text-center mb-5">
        <VAvatar color="primary" variant="tonal" size="56" rounded class="mb-2">
          <VIcon icon="tabler-building-store" size="30" />
        </VAvatar>
        <h1 class="text-h5 font-weight-bold mb-0">{{ supplier.company_name }}</h1>
        <p class="text-caption text-medium-emphasis">
          {{ t('supplier_portal.page_title') }}
          <span v-if="supplier.supplier_number"> · {{ supplier.supplier_number }}</span>
        </p>
      </div>

      <VAlert v-if="flash()" type="success" variant="tonal" class="mb-4">{{ flash() }}</VAlert>

      <!--
        The safety compliance page, for suppliers who also work on site (CON-18).

        Prominent rather than tucked into a menu: being refused at a boom is the thing that
        actually stops a contractor working, and the page that explains why is worth more to
        them than their banking details. Shown only where they are on the contractor register —
        a link a stationery supplier cannot use is an invitation to telephone somebody.
      -->
      <VCard v-if="safetyPortal" elevation="6" class="portal-card mb-4">
        <VCardText class="pa-5 d-flex align-center flex-wrap ga-4">
          <VAvatar color="warning" variant="tonal" size="44" rounded>
            <VIcon icon="tabler-shield-check" size="24" />
          </VAvatar>
          <div class="flex-grow-1" style="min-width: 220px">
            <div class="text-body-1 font-weight-medium">
              {{ t('supplier_portal.safety_title') }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('supplier_portal.safety_body') }}
            </div>
          </div>
          <VBtn color="warning" variant="flat" :href="`/supplier-portal/${token}/safety`">
            {{ t('supplier_portal.safety_open') }}
          </VBtn>
        </VCardText>
      </VCard>

      <!-- Company summary -->
      <VCard elevation="6" class="portal-card mb-4">
        <VCardText class="pa-6">
          <h6 class="text-h6 mb-3">{{ t('supplier_portal.company_details') }}</h6>
          <VRow dense>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('supplier_portal.trading_name') }}</div>
              <div class="text-body-2">{{ supplier.trading_name || '—' }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('supplier_portal.entity_type') }}</div>
              <div class="text-body-2">{{ supplier.business_entity_type || '—' }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('supplier_portal.registration_number') }}</div>
              <div class="text-body-2">{{ supplier.company_registration_number || '—' }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('supplier_portal.vat_number') }}</div>
              <div class="text-body-2">{{ supplier.vat_registration_number || '—' }}</div>
            </VCol>
          </VRow>

          <template v-if="contacts.length">
            <VDivider class="my-4" />
            <div class="text-caption text-medium-emphasis mb-1">{{ t('supplier_portal.contacts') }}</div>
            <div v-for="(c, i) in contacts" :key="'c' + i" class="text-body-2">
              {{ c.name }}
              <span v-if="c.email" class="text-medium-emphasis">· {{ c.email }}</span>
              <span v-if="c.phone" class="text-medium-emphasis">· {{ c.phone }}</span>
            </div>
          </template>

          <template v-if="directors.length">
            <VDivider class="my-4" />
            <div class="text-caption text-medium-emphasis mb-1">{{ t('supplier_portal.directors') }}</div>
            <div v-for="(d, i) in directors" :key="'d' + i" class="text-body-2">
              {{ d.name }}
              <span v-if="d.email" class="text-medium-emphasis">· {{ d.email }}</span>
            </div>
          </template>
        </VCardText>
      </VCard>

      <!-- Editable profile -->
      <VCard elevation="6" class="portal-card mb-4">
        <VCardText class="pa-6">
          <h6 class="text-h6 mb-3">{{ t('supplier_portal.contact_banking') }}</h6>
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.phone_number"
                :label="t('supplier_portal.phone')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.phone_number"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.email"
                :label="t('supplier_portal.email')"
                type="email"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.email"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.website_url"
                :label="t('supplier_portal.website')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.website_url"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="profile.physical_address"
                :label="t('supplier_portal.physical_address')"
                variant="outlined"
                rows="2"
                :error-messages="profile.errors.physical_address"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="profile.postal_address"
                :label="t('supplier_portal.postal_address')"
                variant="outlined"
                rows="2"
                :error-messages="profile.errors.postal_address"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.bank_name"
                :label="t('supplier_portal.bank_name')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.bank_name"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.account_holder_name"
                :label="t('supplier_portal.account_holder')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.account_holder_name"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.account_type"
                :label="t('supplier_portal.account_type')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.account_type"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.bank_account_number"
                :label="t('supplier_portal.account_number')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.bank_account_number"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="profile.branch_code"
                :label="t('supplier_portal.branch_code')"
                variant="outlined"
                density="comfortable"
                :error-messages="profile.errors.branch_code"
              />
            </VCol>
          </VRow>
          <div class="d-flex justify-end mt-2">
            <VBtn color="primary" :loading="profile.processing" @click="saveProfile">
              {{ t('supplier_portal.save_details') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Documents -->
      <VCard elevation="6" class="portal-card mb-4">
        <VCardText class="pa-6">
          <h6 class="text-h6 mb-3">{{ t('supplier_portal.documents') }}</h6>

          <VTable v-if="documents.length" density="comfortable" class="mb-4">
            <thead>
              <tr>
                <th>{{ t('supplier_portal.type') }}</th>
                <th>{{ t('supplier_portal.file_name') }}</th>
                <th>{{ t('supplier_portal.expiry') }}</th>
                <th class="text-end">{{ t('supplier_portal.download') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doc in documents" :key="doc.id">
                <td>{{ doc.type || '—' }}</td>
                <td>{{ doc.file_name || '—' }}</td>
                <td>{{ formatDate(doc.expiry) }}</td>
                <td class="text-end">
                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    icon="tabler-download"
                    @click="downloadDoc(doc.id)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>
          <p v-else class="text-body-2 text-medium-emphasis mb-4">
            {{ t('supplier_portal.no_documents') }}
          </p>

          <VDivider class="mb-4" />
          <h6 class="text-subtitle-1 mb-2">{{ t('supplier_portal.upload_document') }}</h6>
          <VRow>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="upload.document_type_id"
                :items="documentTypes"
                item-title="name"
                item-value="id"
                :label="t('supplier_portal.document_type')"
                variant="outlined"
                density="comfortable"
                clearable
                :error-messages="upload.errors.document_type_id"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="upload.expiry_date"
                :label="t('supplier_portal.expiry_date')"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="upload.errors.expiry_date"
              />
            </VCol>
            <VCol cols="12">
              <VFileInput
                v-model="upload.file"
                :label="t('supplier_portal.file')"
                variant="outlined"
                density="comfortable"
                prepend-icon="tabler-paperclip"
                :error-messages="upload.errors.file"
              />
            </VCol>
          </VRow>
          <div class="d-flex justify-end">
            <VBtn
              color="primary"
              :loading="upload.processing"
              :disabled="!upload.file"
              @click="submitUpload"
            >
              {{ t('supplier_portal.upload') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </div>
  </div>
</template>

<style scoped>
.portal-bg {
  min-height: 100vh;
  background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%);
}
.portal-wrap {
  width: 100%;
  max-width: 760px;
}
.portal-card {
  border-radius: 16px;
}
</style>
