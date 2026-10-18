<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Budget {
  id: number
  code: string
  name: string
  currency: string | null
}

interface Category {
  id: number
  name: string
}

type Item = {
  item_type: string
  name: string
  budget_category_id: number | null
  quantity: number
  unit_price: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  budgets: Budget[]
  categories: Category[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const submitting = ref(false)

interface FormData {
  budget_id: number | null
  department: string
  project_code: string
  urgency: string
  justification: string
  delivery_location: string
  needed_by: string
  currency: string
}

const form = ref<FormData>({
  budget_id: null,
  department: '',
  project_code: '',
  urgency: 'normal',
  justification: '',
  delivery_location: '',
  needed_by: '',
  currency: 'ZAR',
})

const items = ref<Item[]>([
  { item_type: 'custom', name: '', budget_category_id: null, quantity: 1, unit_price: 0 },
])

// ── Constants ──────────────────────────────────────────────────────────────────
const urgencyOptions =  computed(()=>[
  { value: 'low', label: t('purchase_request_create.urgencies.low') },
  { value: 'normal', label: t('purchase_request_create.urgencies.normal') },
  { value: 'high', label: t('purchase_request_create.urgencies.high') },
  { value: 'emergency', label: t('purchase_request_create.urgencies.emergency') },
])

const itemTypeOptions = computed(()=> [
  { value: 'catalog', label: t('purchase_request_create.item_types.catalog') },
  { value: 'custom', label: t('purchase_request_create.item_types.custom') },
  { value: 'service', label: t('purchase_request_create.item_types.service') },
])

const currencyOptions = computed(()=>[
  { value: 'ZAR', label: t('purchase_request_create.currencies.zar') },
  { value: 'USD', label: t('purchase_request_create.currencies.usd') },
  { value: 'EUR', label: t('purchase_request_create.currencies.eur') },
  { value: 'GBP', label: t('purchase_request_create.currencies.gbp') },
])

// ── Helpers ────────────────────────────────────────────────────────────────────
function getUrgencyLabel(value: string): string {
  const map: Record<string, string> = {
    low: t('purchase_request_create.urgencies.low'),
    normal: t('purchase_request_create.urgencies.normal'),
    high: t('purchase_request_create.urgencies.high'),
    emergency: t('purchase_request_create.urgencies.emergency'),
  }
  return map[value] || value
}

function getItemTypeLabel(value: string): string {
  const map: Record<string, string> = {
    catalog: t('purchase_request_create.item_types.catalog'),
    custom: t('purchase_request_create.item_types.custom'),
    service: t('purchase_request_create.item_types.service'),
  }
  return map[value] || value
}

function formatCurrency(value: number): string {
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: form.value.currency || 'ZAR',
    }).format(value)
  } catch {
    return `${form.value.currency || ''} ${value}`.trim()
  }
}

// ── Methods ────────────────────────────────────────────────────────────────────
function addItem(): void {
  items.value.push({
    item_type: 'custom',
    name: '',
    budget_category_id: null,
    quantity: 1,
    unit_price: 0,
  })
}

function removeItem(i: number): void {
  if (items.value.length > 1) items.value.splice(i, 1)
}

function lineTotal(item: Item): number {
  return (Number(item.quantity) || 0) * (Number(item.unit_price) || 0)
}

function goToIndex(): void {
  router.get(route('purchase-requests.index'))
}

function submit(): void {
  submitting.value = true
  router.post(
    route('purchase-requests.store'),
    { ...form.value, items: items.value },
    {
      onFinish: () => (submitting.value = false),
    }
  )
}

// ── Computed ──────────────────────────────────────────────────────────────────
const grandTotal = computed(() =>
  items.value.reduce((sum, item) => sum + lineTotal(item), 0)
)

const canSubmit = computed(() =>
  items.value.some(item =>
    item.name.trim() &&
    Number(item.quantity) > 0 &&
    Number(item.unit_price) >= 0
  )
)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" @click="goToIndex" />
      <h4 class="text-h4 mb-0">{{ t('purchase_request_create.page_title') }}</h4>
    </div>

    <!-- ── Request Details ────────────────────────────────────────────────── -->
    <VCard border class="mb-4">
      <VCardTitle>{{ t('purchase_request_create.request_details') }}</VCardTitle>
      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.budget_id"
              :items="budgets"
              item-value="id"
              :label="t('purchase_request_create.budget')"
              variant="outlined"
              clearable
            >
              <template #item="{ props: ip, item }">
                <VListItem v-bind="ip" :title="`${item.raw.code} — ${item.raw.name}`" />
              </template>
              <template #selection="{ item }">
                {{ item.raw.code }} — {{ item.raw.name }}
              </template>
            </VSelect>
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model="form.department"
              :label="t('purchase_request_create.department')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model="form.project_code"
              :label="t('purchase_request_create.project_code')"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="3">
            <VSelect
              v-model="form.urgency"
              :items="urgencyOptions"
              item-title="label"
              item-value="value"
              :label="t('purchase_request_create.urgency')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="5">
            <VTextField
              v-model="form.delivery_location"
              :label="t('purchase_request_create.delivery_location')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="2">
            <VTextField
              v-model="form.needed_by"
              :label="t('purchase_request_create.needed_by')"
              type="date"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="2">
            <VSelect
              v-model="form.currency"
              :items="currencyOptions"
              item-title="label"
              item-value="value"
              :label="t('purchase_request_create.currency')"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12">
            <VTextarea
              v-model="form.justification"
              :label="t('purchase_request_create.justification')"
              variant="outlined"
              rows="3"
            />
          </VCol>
        </VRow>

        <VAlert
          v-if="form.urgency === 'emergency'"
          type="warning"
          variant="tonal"
          density="compact"
        >
          {{ t('purchase_request_create.emergency_warning') }}
        </VAlert>
      </VCardText>
    </VCard>

    <!-- ── Items ──────────────────────────────────────────────────────────── -->
    <VCard border class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('purchase_request_create.items') }}</span>
        <span class="text-body-1">
          {{ t('purchase_request_create.grand_total', { total: formatCurrency(grandTotal) }) }}
        </span>
      </VCardTitle>
      <VCardText>
        <div v-for="(item, index) in items" :key="index" class="mb-2">
          <VRow class="align-center">
            <VCol cols="12" md="2">
              <VSelect
                v-model="item.item_type"
                :items="itemTypeOptions"
                item-title="label"
                item-value="value"
                :label="t('purchase_request_create.item_type')"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="item.name"
                :label="t('purchase_request_create.item_name')"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect
                v-model="item.budget_category_id"
                :items="categories"
                item-title="name"
                item-value="id"
                :label="t('purchase_request_create.category')"
                variant="outlined"
                density="compact"
                hide-details
                clearable
              />
            </VCol>
            <VCol cols="6" md="1">
              <VTextField
                v-model.number="item.quantity"
                :label="t('purchase_request_create.quantity')"
                type="number"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField
                v-model.number="item.unit_price"
                :label="t('purchase_request_create.unit_price')"
                type="number"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="1" class="d-flex align-center justify-space-between">
              <span class="text-caption font-weight-medium">
                {{ formatCurrency(lineTotal(item)) }}
              </span>
              <VBtn
                icon="tabler-trash"
                size="x-small"
                variant="text"
                color="error"
                :disabled="items.length === 1"
                @click="removeItem(index)"
              />
            </VCol>
          </VRow>
        </div>

        <VBtn
          variant="text"
          size="small"
          prepend-icon="tabler-plus"
          class="mt-2"
          @click="addItem"
        >
          {{ t('purchase_request_create.add_item') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- ── Actions ────────────────────────────────────────────────────────── -->
    <div class="d-flex justify-end gap-2">
      <VBtn variant="text" @click="goToIndex">
        {{ t('purchase_request_create.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="submitting"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ t('purchase_request_create.submit') }}
      </VBtn>
    </div>
  </div>
</template>
