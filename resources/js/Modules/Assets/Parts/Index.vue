<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Part = {
  id: number
  uuid: string
  part_number: string
  name: string
  category: string | null
  unit_of_measure: string
  unit_cost: number
  quantity_on_hand: number
  reorder_level: number
  reorder_quantity: number
  status: string
  is_low_stock: boolean
  stock_value: number
  location: { id: number; name: string } | null
  supplier: { id: number; name: string } | null
}

const props = defineProps<{
  parts: { data: Part[]; links: any[]; meta: any }
  stats: { total_parts: number; low_stock_count: number; total_stock_value: number }
  categories: string[]
  filters: { category: string | null; low_stock: boolean; search: string }
  reorderView?: boolean
}>()

const filters = reactive({
  category: props.filters.category,
  low_stock: props.filters.low_stock,
  search: props.filters.search,
})

function money(n: number | null): string {
  if (n == null) return '—'
  return `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function qty(n: number): string {
  return Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 2 })
}

function applyFilters() {
  router.get(route('assets.parts.index'), {
    category: filters.category || undefined,
    low_stock: filters.low_stock ? 1 : undefined,
    search: filters.search || undefined,
  }, { preserveState: true, replace: true })
}

function clearFilters() {
  filters.category = null
  filters.low_stock = false
  filters.search = ''
  applyFilters()
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-6">
      <h1 class="text-h4 font-weight-bold">
        {{ props.reorderView ? 'Reorder Report' : 'Spare Parts' }}
      </h1>
      <VSpacer />
      <VBtn
        v-if="!props.reorderView"
        variant="tonal"
        class="mr-2"
        :to="route('assets.parts.low-stock')"
      >
        Reorder Report
      </VBtn>
      <VBtn color="primary" prepend-icon="mdi-plus" :to="route('assets.parts.create')">
        New Part
      </VBtn>
    </div>

    <!-- Stats -->
    <VRow class="mb-2">
      <VCol cols="12" sm="4">
        <VCard>
          <VCardItem>
            <div class="text-caption text-medium-emphasis">Total Parts</div>
            <div class="text-h5 font-weight-bold">{{ props.stats.total_parts }}</div>
          </VCardItem>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard :color="props.stats.low_stock_count > 0 ? 'warning' : undefined" variant="tonal">
          <VCardItem>
            <div class="text-caption text-medium-emphasis">Low Stock</div>
            <div class="text-h5 font-weight-bold">{{ props.stats.low_stock_count }}</div>
          </VCardItem>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard>
          <VCardItem>
            <div class="text-caption text-medium-emphasis">Stock Value</div>
            <div class="text-h5 font-weight-bold">{{ money(props.stats.total_stock_value) }}</div>
          </VCardItem>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard v-if="!props.reorderView" class="mb-4">
      <VCardItem>
        <VRow align="center">
          <VCol cols="12" md="4">
            <VTextField
              v-model="filters.search"
              label="Search part # or name"
              density="compact"
              hide-details
              clearable
              @keyup.enter="applyFilters"
              @click:clear="applyFilters"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="filters.category"
              :items="props.categories"
              label="Category"
              density="compact"
              hide-details
              clearable
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSwitch
              v-model="filters.low_stock"
              label="Low stock only"
              color="warning"
              density="compact"
              hide-details
            />
          </VCol>
          <VCol cols="12" md="2" class="d-flex gap-2">
            <VBtn color="primary" variant="tonal" @click="applyFilters">Apply</VBtn>
            <VBtn variant="text" @click="clearFilters">Clear</VBtn>
          </VCol>
        </VRow>
      </VCardItem>
    </VCard>

    <VCard>
      <VCardItem>
        <VTable>
          <thead>
            <tr>
              <th>Part #</th>
              <th>Name</th>
              <th>Category</th>
              <th class="text-right">On Hand</th>
              <th class="text-right">Reorder At</th>
              <th class="text-right">Unit Cost</th>
              <th class="text-right">Value</th>
              <th>Location</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in props.parts.data" :key="p.id">
              <td class="font-weight-medium">{{ p.part_number }}</td>
              <td>
                {{ p.name }}
                <VChip v-if="p.is_low_stock" color="warning" size="x-small" class="ml-1">Low</VChip>
              </td>
              <td>{{ p.category || '—' }}</td>
              <td class="text-right">
                <span :class="p.is_low_stock ? 'text-warning font-weight-bold' : ''">
                  {{ qty(p.quantity_on_hand) }} {{ p.unit_of_measure }}
                </span>
              </td>
              <td class="text-right">{{ qty(p.reorder_level) }}</td>
              <td class="text-right">{{ money(p.unit_cost) }}</td>
              <td class="text-right">{{ money(p.stock_value) }}</td>
              <td>{{ p.location?.name || '—' }}</td>
              <td class="text-right">
                <VBtn size="small" variant="text" :to="route('assets.parts.show', p.uuid)">View</VBtn>
              </td>
            </tr>
            <tr v-if="props.parts.data.length === 0">
              <td colspan="9" class="text-center text-medium-emphasis py-6">
                {{ props.reorderView ? 'Nothing to reorder — all parts are above their reorder level.' : 'No parts found.' }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
