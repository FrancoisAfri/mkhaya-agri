<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Industry {
  id: number
  industry_type_name: string
}

interface Product {
  id: number
  item_name: string
}

interface SupplierRow {
  id: number
  company_name: string
  registration: string | null
  email: string | null
  phone: string | null
  address: string | null
  status: number
  industries: string[]
  products: string[]
}

interface Pagination {
  data: SupplierRow[]
  links: any[]
  total: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  industries: Industry[]
  products: Product[]
  results?: Pagination
  filters?: any
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const loading = ref<boolean>(false)

const form = ref({
  company_name: props.filters?.company_name ?? '',
  registration: props.filters?.registration ?? '',
  email: props.filters?.email ?? '',
  status: props.filters?.status ?? '',
  industry_ids: props.filters?.industry_ids ?? [],
  product_ids: props.filters?.product_ids ?? [],
})

// ── Constants ──────────────────────────────────────────────────────────────────
const statusItems = computed(()=> [
  { title: t('supplier_advanced_search.filters.any'), value: '' },
  { title: t('supplier_advanced_search.statuses.pending'), value: '0' },
  { title: t('supplier_advanced_search.statuses.active'), value: '1' },
  { title: t('supplier_advanced_search.statuses.refused'), value: '2' },
  { title: t('supplier_advanced_search.statuses.pending_kyc'), value: '3' },
]);

const statusLabel: Record<number, string> = {
  0: t('supplier_advanced_search.statuses.pending'),
  1: t('supplier_advanced_search.statuses.active'),
  2: t('supplier_advanced_search.statuses.refused'),
  3: t('supplier_advanced_search.statuses.pending_kyc'),
}

// ── Methods ────────────────────────────────────────────────────────────────────
function buildParams(): Record<string, any> {
  const params: Record<string, any> = {}
  if (form.value.company_name) params.company_name = form.value.company_name
  if (form.value.registration) params.registration = form.value.registration
  if (form.value.email) params.email = form.value.email
  if (form.value.status !== '') params.status = form.value.status
  if (form.value.industry_ids?.length) params.industry_ids = form.value.industry_ids
  if (form.value.product_ids?.length) params.product_ids = form.value.product_ids
  return params
}

function search(): void {
  loading.value = true
  router.get(
    route('suppliers.search.advanced'),
    buildParams(),
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onFinish: () => { loading.value = false },
    }
  )
}

// A supplier is addressed by uuid, not by its row id.
function openSupplier(uuid: string): void {
  router.get(route('suppliers.show', uuid))
}

function clearFilters(): void {
  form.value.company_name = ''
  form.value.registration = ''
  form.value.email = ''
  form.value.status = ''
  form.value.industry_ids = []
  form.value.product_ids = []
  search()
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_advanced_search.page_title') }}</h1>

    <!-- Search Form -->
    <VCard border class="mb-4">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.company_name"
              :label="t('supplier_advanced_search.filters.company_name')"
              density="compact"
              variant="outlined"
              clearable
              @keyup.enter="search"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.registration"
              :label="t('supplier_advanced_search.filters.registration')"
              density="compact"
              variant="outlined"
              clearable
              @keyup.enter="search"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.email"
              :label="t('supplier_advanced_search.filters.email')"
              density="compact"
              variant="outlined"
              clearable
              @keyup.enter="search"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.status"
              :items="statusItems"
              :label="t('supplier_advanced_search.filters.status')"
              density="compact"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.industry_ids"
              :items="industries"
              item-title="industry_type_name"
              item-value="id"
              :label="t('supplier_advanced_search.filters.industries')"
              density="compact"
              variant="outlined"
              multiple
              chips
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.product_ids"
              :items="products"
              item-title="item_name"
              item-value="id"
              :label="t('supplier_advanced_search.filters.products')"
              density="compact"
              variant="outlined"
              multiple
              chips
            />
          </VCol>
        </VRow>
        <div class="d-flex gap-2">
          <VBtn color="primary" prepend-icon="tabler-search" :loading="loading" @click="search">
            {{ t('supplier_advanced_search.search') }}
          </VBtn>
          <VBtn variant="text" @click="clearFilters">
            {{ t('supplier_advanced_search.filters.any') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Results -->
    <VCard v-if="results" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_advanced_search.table.company') }}</th>
            <th>{{ t('supplier_advanced_search.table.registration') }}</th>
            <th>{{ t('supplier_advanced_search.table.email') }}</th>
            <th>{{ t('supplier_advanced_search.table.phone') }}</th>
            <th>{{ t('supplier_advanced_search.table.status') }}</th>
            <th>{{ t('supplier_advanced_search.table.industries') }}</th>
            <th>{{ t('supplier_advanced_search.table.products') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!results.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_advanced_search.no_results') }}
            </td>
          </tr>
          <tr
            v-for="r in results.data"
            :key="r.id"
            style="cursor:pointer"
            @click="openSupplier(r.uuid)"
          >
            <td class="font-weight-medium">{{ r.company_name }}</td>
            <td>{{ r.registration ?? '—' }}</td>
            <td>{{ r.email ?? '—' }}</td>
            <td>{{ r.phone ?? '—' }}</td>
            <td>{{ statusLabel[r.status] ?? '—' }}</td>
            <td>{{ r.industries.join(', ') || '—' }}</td>
            <td>{{ r.products.join(', ') || '—' }}</td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <div v-if="results.links?.length > 3" class="d-flex flex-wrap gap-1 pa-3">
        <VBtn
          v-for="(link, i) in results.links"
          :key="i"
          :disabled="!link.url"
          :variant="link.active ? 'flat' : 'outlined'"
          :color="link.active ? 'primary' : undefined"
          size="small"
          @click="link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
        >
          <span v-html="link.label" />
        </VBtn>
      </div>
    </VCard>
  </div>
</template>
