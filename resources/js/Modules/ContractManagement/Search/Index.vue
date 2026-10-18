<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface ContractRow {
  id: number
  contract_number: string | null
  title: string | null
  contract_status: string | null
  approval_status: string | null
  contract_value: string | number | null
  start_date: string | null
  end_date: string | null
  type: string | null
  category: string | null
}

const props = defineProps<{
  results: { data: ContractRow[]; links: any[]; total: number }
  filters: {
    q?: string
    status?: string
    type?: number | string
    category?: number | string
    supplier_id?: number | string
  }
  types: { id: number; name: string }[]
  categories: { id: number; name: string }[]
}>()

const form = ref({
  q: props.filters?.q ?? '',
  status: props.filters?.status ?? '',
  type: props.filters?.type ?? '',
  category: props.filters?.category ?? '',
  supplier_id: props.filters?.supplier_id ?? '',
})

const statusItems =  computed(()=>[
  { title: t('contracts_search.filters.any_status'), value: '' },
  { title: t('contracts_search.statuses.draft'), value: 'draft' },
  { title: t('contracts_search.statuses.active'), value: 'active' },
  { title: t('contracts_search.statuses.pending'), value: 'pending' },
  { title: t('contracts_search.statuses.expired'), value: 'expired' },
  { title: t('contracts_search.statuses.terminated'), value: 'terminated' },
  { title: t('contracts_search.statuses.renewed'), value: 'renewed' },
])

const headers = computed(()=> [
  { title: t('contracts_search.table.number'), key: 'contract_number' },
  { title: t('contracts_search.table.title'), key: 'title' },
  { title: t('contracts_search.table.type'), key: 'type' },
  { title: t('contracts_search.table.category'), key: 'category' },
  { title: t('contracts_search.table.status'), key: 'contract_status' },
  { title: t('contracts_search.table.value'), key: 'contract_value' },
  { title: t('contracts_search.table.start'), key: 'start_date' },
  { title: t('contracts_search.table.end'), key: 'end_date' },
])

let timer: ReturnType<typeof setTimeout> | undefined

function buildParams() {
  const params: Record<string, any> = {}
  if (form.value.q) params.q = form.value.q
  if (form.value.status) params.status = form.value.status
  if (form.value.type) params.type = form.value.type
  if (form.value.category) params.category = form.value.category
  if (form.value.supplier_id) params.supplier_id = form.value.supplier_id
  return params
}

function search() {
  router.get(route('contracts.search'), buildParams(), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

// Debounce the free-text keyword input.
watch(() => form.value.q, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(search, 350)
})

// Filter selects search immediately.
watch(
  () => [form.value.status, form.value.type, form.value.category, form.value.supplier_id],
  () => search(),
)

// Addressed by uuid, not the row id.
function openContract(uuid: string) {
  router.get(route('contracts.show', uuid))
}
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4 mb-4">{{ t('contracts_search.page_title') }}</h4>

    <VCard border class="mb-4">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.q"
              :label="t('contracts_search.filters.keyword')"
              prepend-inner-icon="tabler-search"
              density="compact"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="2">
            <VSelect
              v-model="form.status"
              :items="statusItems"
              :label="t('contracts_search.filters.status')"
              density="compact"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="form.type"
              :items="[{ name: t('contracts_search.filters.any_type'), id: '' }, ...types]"
              item-title="name"
              item-value="id"
              :label="t('contracts_search.filters.type')"
              density="compact"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="form.category"
              :items="[{ name: t('contracts_search.filters.any_category'), id: '' }, ...categories]"
              item-title="name"
              item-value="id"
              :label="t('contracts_search.filters.category')"
              density="compact"
              variant="outlined"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border>
      <VDataTable
        :headers="headers"
        :items="results.data"
        :items-per-page="-1"
        hover
        @click:row="(_e: any, { item }: any) => openContract(item.uuid)"
      >
        <template #item.contract_number="{ item }">
          <span class="font-weight-medium">{{ item.contract_number ?? '—' }}</span>
        </template>
        <template #item.contract_value="{ item }">
          {{ item.contract_value ?? '—' }}
        </template>
        <template #item.type="{ item }">{{ item.type ?? '—' }}</template>
        <template #item.category="{ item }">{{ item.category ?? '—' }}</template>
        <template #item.start_date="{ item }">{{ item.start_date ?? '—' }}</template>
        <template #item.end_date="{ item }">{{ item.end_date ?? '—' }}</template>
        <template #no-data>
          <div class="text-center text-medium-emphasis py-8">{{ t('contracts_search.no_results') }}</div>
        </template>
        <template #bottom>
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
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
