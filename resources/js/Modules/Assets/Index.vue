<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const { t } = useI18n()
const route = ziggyRoute

type Asset = {
  id: number; uuid: string; asset_number: string | null; asset_tag: string | null
  name: string; status: { value: string; label: string; color: string }
  condition: { value: string; label: string; color: string } | null
  purchase_cost: string | null; asset_type: { id: number; name: string } | null
  category: { id: number; name: string } | null; location: { id: number; name: string } | null
  current_assignment: { assignee: string | null } | null; created_at: string
}

const props = defineProps<{
  assets: { data: Asset[]; links: any[]; meta: any }
  filters: Record<string, any>
  stats: { total: number; active: number; unallocated: number; maintenance: number }
  statusOptions: { value: string; label: string }[]
  categories: { id: number; name: string }[]
}>()

const searchFilter   = ref<string>(props.filters?.search ?? '')
const statusFilter   = ref<string>(props.filters?.status ?? '')
const categoryFilter = ref<string>(props.filters?.category ?? '')

let searchTimer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('assets.index'), {
    search:   searchFilter.value   || undefined,
    status:   statusFilter.value   || undefined,
    category: categoryFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(searchFilter, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) })
watch([statusFilter, categoryFilter], applyFilters)

function clearFilters() {
  searchFilter.value = ''; statusFilter.value = ''; categoryFilter.value = ''
  router.get(route('assets.index'), {}, { replace: true })
}

const statCards = computed(() => [
  { label: t('assets_index.stats.total_assets'), value: props.stats.total, icon: 'tabler-package', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('assets_index.stats.active_in_use'), value: props.stats.active, icon: 'tabler-circle-check', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('assets_index.stats.unallocated'), value: props.stats.unallocated, icon: 'tabler-inbox', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
  { label: t('assets_index.stats.in_maintenance'), value: props.stats.maintenance, icon: 'tabler-tool', color: 'info', bg: 'rgba(0,207,232,0.12)' },
])

const headers = [
  { title: t('assets_index.table.asset'), key: 'name', sortable: false },
  { title: t('assets_index.table.category'), key: 'category', sortable: false, width: '150px' },
  { title: t('assets_index.table.location'), key: 'location', sortable: false, width: '140px' },
  { title: t('assets_index.table.status'), key: 'status', sortable: false, width: '140px' },
  { title: t('assets_index.table.condition'), key: 'condition', sortable: false, width: '120px' },
  { title: t('assets_index.table.assigned_to'), key: 'current_assignment', sortable: false, width: '160px' },
  { title: t('assets_index.table.cost'), key: 'purchase_cost', sortable: false, width: '120px' },
]

const rows  = computed(() => props.assets?.data  ?? [])
const links = computed(() => props.assets?.links ?? [])
const meta  = computed(() => props.assets?.meta  ?? {})

function formatCurrency(val: string | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val))
}

function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const statusItems = computed(() => [
  { value: '', label: t('assets_index.filters.all_statuses') },
  ...props.statusOptions,
])

const categoryItems = computed(() => [
  { id: '', name: t('assets_index.filters.all_categories') },
  ...props.categories,
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('assets.create'))">
        {{ t('assets_index.register_asset') }}
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }">
              <VIcon :icon="card.icon" :color="card.color" size="28" />
            </div>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p>
              <p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip size="default" variant="tonal" :color="statusFilter === '' ? 'primary' : 'default'" class="cursor-pointer font-weight-medium" @click="statusFilter = ''">
        {{ t('assets_index.quick_filters.all') }}
      </VChip>
      <VChip v-for="opt in statusOptions" :key="opt.value" size="default" variant="tonal"
        :color="statusFilter === opt.value ? 'primary' : 'default'" class="cursor-pointer font-weight-medium" @click="statusFilter = opt.value">
        {{ opt.label }}
      </VChip>
    </div>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="5">
            <AppTextField v-model="searchFilter" :label="t('assets_index.filters.search_placeholder')" prepend-inner-icon="tabler-search" clearable hide-details />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect v-model="categoryFilter" :items="categoryItems" item-title="name" item-value="id" :label="t('assets_index.filters.category')" hide-details density="comfortable" variant="outlined" />
          </VCol>
          <VCol cols="auto" class="ms-auto">
            <VBtn v-if="searchFilter || statusFilter || categoryFilter" variant="text" color="default" prepend-icon="tabler-x" @click="clearFilters">
              {{ t('assets_index.filters.clear') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="25" hover
        @click:row="(_: unknown, row: any) => router.visit(route('assets.show', row.item.uuid))">
        <template #item.name="{ item }">
          <div>
            <p class="text-body-2 font-weight-semibold text-primary mb-0">{{ item.name }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ item.asset_number ?? item.asset_tag ?? '—' }}</p>
          </div>
        </template>
        <template #item.category="{ item }"><span class="text-body-2">{{ item.category?.name ?? '—' }}</span></template>
        <template #item.location="{ item }"><span class="text-body-2">{{ item.location?.name ?? '—' }}</span></template>
        <template #item.status="{ item }"><VChip :color="item.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ item.status.label }}</VChip></template>
        <template #item.condition="{ item }">
          <VChip v-if="item.condition" :color="item.condition.color" size="small" label variant="tonal" class="font-weight-medium">{{ item.condition.label }}</VChip>
          <span v-else class="text-body-2 text-medium-emphasis">—</span>
        </template>
        <template #item.current_assignment="{ item }"><span class="text-body-2">{{ item.current_assignment?.assignee ?? '—' }}</span></template>
        <template #item.purchase_cost="{ item }"><span class="text-body-2">{{ formatCurrency(item.purchase_cost) }}</span></template>
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-package" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_index.empty.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('assets_index.empty.description') }}</p>
            <VBtn color="primary" variant="tonal" @click="router.visit(route('assets.create'))">{{ t('assets_index.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('assets_index.pagination.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}
        </p>
        <div class="d-flex gap-1">
          <VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" />
        </div>
      </div>
    </VCard>
  </div>
</template>
