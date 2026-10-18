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
  active: boolean
  template_name: string | null
}

interface IndustryType {
  id: number
  industry_type_name: string
  active: boolean
}

interface Category {
  id: number
  name: string
  status: string
}

interface User {
  id: number
  email: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  kyc: boolean
  approverIds: number[]
  documentTypes: DocumentType[]
  industryTypes: IndustryType[]
  categories: Category[]
  users: User[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const tab = ref<string>('kyc')
const kycOn = ref<boolean>(props.kyc)
const approvers = ref<number[]>([...props.approverIds])

const docForm = ref<{
  document_type_name: string
  compulsory: boolean
  active: boolean
  template: File | null
}>({
  document_type_name: '',
  compulsory: false,
  active: true,
  template: null,
})

const industryForm = ref<{
  industry_type_name: string
  active: boolean
}>({
  industry_type_name: '',
  active: true,
})

const categoryForm = ref<{
  name: string
  status: string
}>({
  name: '',
  status: 'active',
})

// ── Methods ────────────────────────────────────────────────────────────────────
function saveKyc(): void {
  router.post(
    route('suppliers.settings.kyc.toggle'),
    { kyc: kycOn.value, approver_ids: approvers.value },
    { preserveScroll: true }
  )
}

function addDoc(): void {
  router.post(
    route('suppliers.settings.document-types.store'),
    docForm.value,
    {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        docForm.value = {
          document_type_name: '',
          compulsory: false,
          active: true,
          template: null,
        }
      },
    }
  )
}

function delDoc(id: number): void {
  if (confirm(t('supplier_settings.documents.delete_confirm'))) {
    router.delete(route('suppliers.settings.document-types.destroy', id), { preserveScroll: true })
  }
}

function addIndustry(): void {
  router.post(
    route('suppliers.settings.industry-types.store'),
    industryForm.value,
    {
      preserveScroll: true,
      onSuccess: () => {
        industryForm.value = { industry_type_name: '', active: true }
      },
    }
  )
}

function delIndustry(id: number): void {
  if (confirm(t('supplier_settings.industries.delete_confirm'))) {
    router.delete(route('suppliers.settings.industry-types.destroy', id), { preserveScroll: true })
  }
}

function addCategory(): void {
  router.post(
    route('suppliers.settings.categories.store'),
    categoryForm.value,
    {
      preserveScroll: true,
      onSuccess: () => {
        categoryForm.value = { name: '', status: 'active' }
      },
    }
  )
}

function delCategory(id: number): void {
  if (confirm(t('supplier_settings.categories.delete_confirm'))) {
    router.delete(route('suppliers.settings.categories.destroy', id), { preserveScroll: true })
  }
}

function handleFileInput(event: Event): void {
  const target = event.target as HTMLInputElement
  const files = target.files
  if (files && files.length > 0) {
    docForm.value.template = files[0]
  }
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_settings.page_title') }}</h1>
    <VCard border>
      <VTabs v-model="tab">
        <VTab value="kyc">{{ t('supplier_settings.tabs.kyc') }}</VTab>
        <VTab value="documents">{{ t('supplier_settings.tabs.documents') }}</VTab>
        <VTab value="industries">{{ t('supplier_settings.tabs.industries') }}</VTab>
        <VTab value="categories">{{ t('supplier_settings.tabs.categories') }}</VTab>
      </VTabs>
      <VCardText>
        <VWindow v-model="tab">
          <!-- ── KYC Tab ─────────────────────────────────────────────────────────── -->
          <VWindowItem value="kyc">
            <VSwitch
              v-model="kycOn"
              :label="t('supplier_settings.kyc.require_approval')"
              color="primary"
              class="mb-3"
            />
            <VSelect
              v-model="approvers"
              :items="users"
              item-title="email"
              item-value="id"
              :label="t('supplier_settings.kyc.approvers')"
              variant="outlined"
              multiple
              chips
              class="mb-3"
            />
            <VBtn color="primary" @click="saveKyc">{{ t('supplier_settings.kyc.save') }}</VBtn>
          </VWindowItem>

          <!-- ── Document Types Tab ────────────────────────────────────────────── -->
          <VWindowItem value="documents">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_settings.documents.name') }}</th>
                  <th>{{ t('supplier_settings.documents.compulsory') }}</th>
                  <th>{{ t('supplier_settings.documents.active') }}</th>
                  <th>{{ t('supplier_settings.documents.template') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in documentTypes" :key="d.id">
                  <td>{{ d.document_type_name }}</td>
                  <td>{{ d.compulsory ? t('supplier_settings.yes') : t('supplier_settings.no') }}</td>
                  <td>{{ d.active ? t('supplier_settings.yes') : t('supplier_settings.no') }}</td>
                  <td>
                    <VBtn
                      v-if="d.template_name"
                      size="x-small"
                      variant="text"
                      prepend-icon="tabler-download"
                      :href="route('suppliers.settings.document-types.template', d.id)"
                      target="_blank"
                    >{{ d.template_name }}</VBtn>
                    <span v-else class="text-medium-emphasis">{{ t('supplier_settings.documents.no_template') }}</span>
                  </td>
                  <td>
                    <VBtn icon size="x-small" variant="text" color="error" @click="delDoc(d.id)">
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="12" md="4">
                <VTextField
                  v-model="docForm.document_type_name"
                  :label="t('supplier_settings.documents.add_name_label')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="4">
                <VFileInput
                  :label="t('supplier_settings.documents.template_label')"
                  density="compact"
                  variant="outlined"
                  prepend-icon="tabler-paperclip"
                  @update:model-value="(f: File | File[]) => { docForm.template = Array.isArray(f) ? f[0] : f }"
                />
              </VCol>
              <VCol cols="6" md="2">
                <VCheckbox
                  v-model="docForm.compulsory"
                  :label="t('supplier_settings.documents.compulsory_label')"
                />
              </VCol>
              <VCol cols="6" md="2">
                <VBtn
                  color="primary"
                  :disabled="!docForm.document_type_name"
                  @click="addDoc"
                >
                  {{ t('supplier_settings.documents.add_button') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- ── Industry Types Tab ────────────────────────────────────────────── -->
          <VWindowItem value="industries">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_settings.industries.name') }}</th>
                  <th>{{ t('supplier_settings.industries.active') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="i in industryTypes" :key="i.id">
                  <td>{{ i.industry_type_name }}</td>
                  <td>{{ i.active ? t('supplier_settings.yes') : t('supplier_settings.no') }}</td>
                  <td>
                    <VBtn icon size="x-small" variant="text" color="error" @click="delIndustry(i.id)">
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="industryForm.industry_type_name"
                  :label="t('supplier_settings.industries.add_name_label')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="2">
                <VBtn
                  color="primary"
                  :disabled="!industryForm.industry_type_name"
                  @click="addIndustry"
                >
                  {{ t('supplier_settings.industries.add_button') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- ── Categories Tab ────────────────────────────────────────────────── -->
          <VWindowItem value="categories">
            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>{{ t('supplier_settings.categories.name') }}</th>
                  <th>{{ t('supplier_settings.categories.status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in categories" :key="c.id">
                  <td>{{ c.name }}</td>
                  <td>
                    <VChip
                      :color="c.status === 'active' ? 'success' : 'default'"
                      size="x-small"
                      variant="tonal"
                    >
                      {{ c.status === 'active' ? t('supplier_settings.statuses.active') : t('supplier_settings.statuses.inactive') }}
                    </VChip>
                  </td>
                  <td>
                    <VBtn icon size="x-small" variant="text" color="error" @click="delCategory(c.id)">
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField
                  v-model="categoryForm.name"
                  :label="t('supplier_settings.categories.add_name_label')"
                  density="compact"
                  variant="outlined"
                />
              </VCol>
              <VCol cols="12" md="2">
                <VBtn
                  color="primary"
                  :disabled="!categoryForm.name"
                  @click="addCategory"
                >
                  {{ t('supplier_settings.categories.add_button') }}
                </VBtn>
              </VCol>
            </VRow>
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>
  </div>
</template>
