<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Item = {
  id: number; uuid: string; code: string; name: string; category: string | null
  specification: string | null; lifespan_months: number | null; requires_size: boolean
  is_active: boolean; issued_count: number; stock_rows: number; total_on_hand: number
}

type Stock = {
  uuid: string; ppe_item_id: number; item_name: string; item_code: string
  requires_size: boolean; size: string | null; site_id: number | null; site_name: string | null
  quantity_on_hand: number; reorder_level: number; barcode: string | null; is_low: boolean
}

/*
 * ── Loading the catalogue from a spreadsheet ────────────────────────────────
 *
 * A PPE catalogue arrives as a list — from the supplier, from the safety file, from whatever the
 * last system exported — and typing eighty items into a dialog one at a time is the reason a
 * catalogue stays half-populated and the issue register stays empty behind it.
 */
const page = usePage()

const importDialog = ref(false)
const importForm = useForm({ file: null as File | null })

/** Rows the server could not take, named. A count alone tells nobody which twelve are missing. */
const skipped = computed(() => (page.props.flash as any)?.ppeImportSkipped ?? [])

function runImport() {
  importForm.post(route('sheq.ppe.catalogue.items.import'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      // Held open when rows were skipped: closing it would hide the only explanation of why
      // seventy-nine of eighty items arrived.
      if (!skipped.value.length) importDialog.value = false
      importForm.reset()
    },
  })
}

const props = defineProps<{
  noCompany?: boolean
  canManage?: boolean
  items?: Item[]
  stock?: Stock[]
  sites?: Array<{ id: number; name: string }>
  categories?: string[]
  lowStock?: Array<Record<string, any>>
}>()

const tab = ref<'catalogue' | 'stock'>('catalogue')
const search = ref('')
const itemDialog = ref(false)
const stockDialog = ref(false)
const confirmDelete = ref<Item | null>(null)
const editing = ref<Item | null>(null)

const blankItem = {
  code: '', name: '', category: null as string | null, specification: '',
  lifespan_months: null as number | null, requires_size: false,
}

const itemForm = useForm({ ...blankItem })
const stockForm = useForm({
  ppe_item_id: null as number | null, size: '', site_id: null as number | null,
  quantity_on_hand: 0, reorder_level: 0, barcode: '',
})

/** Only active items can be stocked or issued, so the pickers must not offer retired ones. */
const activeItems = computed(() => (props.items ?? []).filter(i => i.is_active))

const stockItem = computed(() =>
  (props.items ?? []).find(i => i.id === stockForm.ppe_item_id) ?? null)

function matches(haystack: Array<string | null | undefined>) {
  const q = search.value.trim().toLowerCase()
  return !q || haystack.some(h => (h ?? '').toLowerCase().includes(q))
}

const visibleItems = computed(() =>
  (props.items ?? []).filter(i => matches([i.code, i.name, i.category, i.specification])))

const visibleStock = computed(() =>
  (props.stock ?? []).filter(s => matches([s.item_code, s.item_name, s.size, s.site_name, s.barcode])))

const lowCount = computed(() => (props.stock ?? []).filter(s => s.is_low).length)

function openItem(item: Item | null) {
  editing.value = item
  itemForm.clearErrors()
  Object.assign(itemForm, item
    ? {
        code: item.code, name: item.name, category: item.category,
        specification: item.specification ?? '', lifespan_months: item.lifespan_months,
        requires_size: item.requires_size,
      }
    : { ...blankItem })
  itemDialog.value = true
}

function saveItem() {
  const done = { preserveScroll: true, onSuccess: () => { itemDialog.value = false; itemForm.reset() } }
  editing.value
    ? itemForm.post(`/sheq/settings/ppe/items/${editing.value.uuid}`, done)
    : itemForm.post('/sheq/settings/ppe/items', done)
}

/** Pre-fill the item when the row is opened from a specific one — one less thing to re-pick. */
function openStock(row: Stock | null, itemId: number | null = null) {
  stockForm.clearErrors()
  Object.assign(stockForm, row
    ? {
        ppe_item_id: row.ppe_item_id, size: row.size ?? '', site_id: row.site_id,
        quantity_on_hand: row.quantity_on_hand, reorder_level: row.reorder_level,
        barcode: row.barcode ?? '',
      }
    : {
        ppe_item_id: itemId, size: '', site_id: null,
        quantity_on_hand: 0, reorder_level: 0, barcode: '',
      })
  stockDialog.value = true
}

function saveStock() {
  stockForm.post('/sheq/settings/ppe/stock', {
    preserveScroll: true,
    onSuccess: () => { stockDialog.value = false; stockForm.reset() },
  })
}

const toggle = (item: Item) =>
  router.post(`/sheq/settings/ppe/items/${item.uuid}/toggle`, {}, { preserveScroll: true })

const removeItem = (item: Item) =>
  router.delete(`/sheq/settings/ppe/items/${item.uuid}`, {
    preserveScroll: true, onFinish: () => { confirmDelete.value = null },
  })

const removeStock = (row: Stock) =>
  router.delete(`/sheq/settings/ppe/stock/${row.uuid}`, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.ppe_config.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.ppe_config.subtitle') }}</p>
      </div>

      <div class="d-flex ga-2 flex-wrap">
        <VBtn
          :to="undefined" href="/sheq/ppe" variant="outlined"
          prepend-icon="mdi-clipboard-account-outline" class="cursor-pointer"
        >
          {{ t('sheq.ppe_config.go_to_register') }}
        </VBtn>
        <VBtn
          v-if="canManage" variant="outlined" prepend-icon="mdi-upload"
          class="cursor-pointer" @click="importDialog = true"
        >
          {{ t('sheq.ppe_config.import.button') }}
        </VBtn>
        <VBtn
          v-if="canManage" color="primary" variant="flat" prepend-icon="mdi-plus"
          class="cursor-pointer" @click="openItem(null)"
        >
          {{ t('sheq.ppe_config.add_item') }}
        </VBtn>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="!canManage" type="info" variant="tonal" density="compact" class="mb-4"
        icon="mdi-eye-outline"
      >
        {{ t('sheq.ppe_config.read_only') }}
      </VAlert>

      <!--
        The dead end this page exists to remove: an empty catalogue means the issue register has
        nothing in its dropdown, and nothing on that screen says why.
      -->
      <VAlert
        v-if="!items?.length" type="info" variant="tonal" class="mb-4"
        icon="mdi-information-outline"
      >
        {{ t('sheq.ppe_config.empty_help') }}
      </VAlert>

      <VAlert
        v-else-if="lowCount" type="warning" variant="tonal" class="mb-4"
        icon="mdi-alert-outline" role="status" aria-live="polite"
      >
        {{ t('sheq.ppe_config.low_warning', { n: lowCount }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VTabs v-model="tab" density="comfortable" color="primary">
          <VTab value="catalogue" class="cursor-pointer">
            {{ t('sheq.ppe_config.tab_catalogue') }}
            <VChip size="x-small" class="ms-2" variant="tonal">{{ items?.length ?? 0 }}</VChip>
          </VTab>
          <VTab value="stock" class="cursor-pointer">
            {{ t('sheq.ppe_config.tab_stock') }}
            <VChip size="x-small" class="ms-2" variant="tonal">{{ stock?.length ?? 0 }}</VChip>
          </VTab>
        </VTabs>

        <VDivider />

        <div class="pa-4 pb-0">
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify" :label="t('sheq.ppe_config.search')"
            style="max-inline-size: 420px"
          />
        </div>

        <!-- ── Catalogue ──────────────────────────────────────────────────────────────────── -->
        <div v-show="tab === 'catalogue'" class="pa-4">
          <div v-if="!visibleItems.length" class="text-center py-8 text-medium-emphasis text-body-2">
            {{ items?.length ? t('sheq.ppe_config.no_match') : t('sheq.ppe_config.no_items') }}
          </div>

          <div v-else class="sheq-scroll">
            <VTable density="comfortable" class="text-body-2">
              <thead>
                <tr>
                  <th scope="col">{{ t('sheq.ppe_config.code') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.item') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.category') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.standard') }}</th>
                  <th scope="col" class="text-end">{{ t('sheq.ppe_config.life') }}</th>
                  <th scope="col" class="text-end">{{ t('sheq.ppe_config.on_hand') }}</th>
                  <th scope="col" class="text-end">{{ t('sheq.ppe_config.issued') }}</th>
                  <th scope="col" v-if="canManage" class="text-end">{{ t('sheq.ppe_config.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in visibleItems" :key="item.uuid" :class="{ 'text-disabled': !item.is_active }">
                  <td class="font-weight-medium">{{ item.code }}</td>
                  <td>
                    {{ item.name }}
                    <VChip v-if="item.requires_size" size="x-small" variant="tonal" class="ms-2">
                      {{ t('sheq.ppe_config.sized') }}
                    </VChip>
                    <VChip v-if="!item.is_active" size="x-small" color="warning" variant="tonal" class="ms-2">
                      {{ t('sheq.ppe_config.retired') }}
                    </VChip>
                  </td>
                  <td>{{ item.category ?? '—' }}</td>
                  <td class="text-medium-emphasis">{{ item.specification ?? '—' }}</td>
                  <td class="text-end">
                    {{ item.lifespan_months ? t('sheq.ppe_config.months', { n: item.lifespan_months }) : '—' }}
                  </td>
                  <td class="text-end font-weight-medium">{{ item.total_on_hand }}</td>
                  <td class="text-end text-medium-emphasis">{{ item.issued_count }}</td>
                  <td v-if="canManage" class="text-end text-no-wrap">
                    <VBtn
                      icon="mdi-package-variant-plus" size="small" variant="text" class="cursor-pointer"
                      :aria-label="t('sheq.ppe_config.add_stock_for', { name: item.name })"
                      @click="openStock(null, item.id)"
                    />
                    <VBtn
                      icon="mdi-pencil-outline" size="small" variant="text" class="cursor-pointer"
                      :aria-label="t('sheq.ppe_config.edit_item_for', { name: item.name })"
                      @click="openItem(item)"
                    />
                    <VBtn
                      :icon="item.is_active ? 'mdi-archive-arrow-down-outline' : 'mdi-archive-arrow-up-outline'"
                      size="small" variant="text" class="cursor-pointer"
                      :aria-label="item.is_active
                        ? t('sheq.ppe_config.retire_item_for', { name: item.name })
                        : t('sheq.ppe_config.restore_item_for', { name: item.name })"
                      @click="toggle(item)"
                    />
                    <VBtn
                      icon="mdi-delete-outline" size="small" variant="text" color="error"
                      class="cursor-pointer" :disabled="item.issued_count > 0"
                      :aria-label="t('sheq.ppe_config.delete_item_for', { name: item.name })"
                      @click="confirmDelete = item"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </div>

        <!-- ── Stock ──────────────────────────────────────────────────────────────────────── -->
        <div v-show="tab === 'stock'" class="pa-4">
          <div class="d-flex justify-space-between align-center flex-wrap ga-2 mb-3">
            <p class="text-caption text-medium-emphasis mb-0">
              {{ t('sheq.ppe_config.stock_hint') }}
            </p>
            <VBtn
              v-if="canManage" size="small" color="primary" variant="flat" prepend-icon="mdi-plus"
              class="cursor-pointer" :disabled="!activeItems.length" @click="openStock(null)"
            >
              {{ t('sheq.ppe_config.set_stock') }}
            </VBtn>
          </div>

          <div v-if="!visibleStock.length" class="text-center py-8 text-medium-emphasis text-body-2">
            {{ stock?.length ? t('sheq.ppe_config.no_match') : t('sheq.ppe_config.no_stock') }}
          </div>

          <div v-else class="sheq-scroll">
            <VTable density="comfortable" class="text-body-2">
              <thead>
                <tr>
                  <th scope="col">{{ t('sheq.ppe_config.item') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.size') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.site') }}</th>
                  <th scope="col" class="text-end">{{ t('sheq.ppe_config.on_hand') }}</th>
                  <th scope="col" class="text-end">{{ t('sheq.ppe_config.reorder') }}</th>
                  <th scope="col">{{ t('sheq.ppe_config.barcode') }}</th>
                  <th scope="col" v-if="canManage" class="text-end">{{ t('sheq.ppe_config.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in visibleStock" :key="row.uuid">
                  <td>
                    <span class="font-weight-medium">{{ row.item_code }}</span>
                    <span class="text-medium-emphasis ms-2">{{ row.item_name }}</span>
                  </td>
                  <td>{{ row.size ?? '—' }}</td>
                  <td>{{ row.site_name ?? t('sheq.ppe_config.all_sites') }}</td>
                  <td class="text-end">
                    <!-- Colour is not the only signal: the low rows also say so in words. -->
                    <span :class="row.is_low ? 'text-warning font-weight-bold' : 'font-weight-medium'">
                      {{ row.quantity_on_hand }}
                    </span>
                    <VChip v-if="row.is_low" size="x-small" color="warning" variant="tonal" class="ms-2">
                      {{ t('sheq.ppe_config.low') }}
                    </VChip>
                  </td>
                  <td class="text-end text-medium-emphasis">{{ row.reorder_level }}</td>
                  <td class="text-medium-emphasis">{{ row.barcode ?? '—' }}</td>
                  <td v-if="canManage" class="text-end text-no-wrap">
                    <VBtn
                      icon="mdi-pencil-outline" size="small" variant="text" class="cursor-pointer"
                      :aria-label="t('sheq.ppe_config.edit_stock_for', { name: row.item_name })"
                      @click="openStock(row)"
                    />
                    <VBtn
                      icon="mdi-delete-outline" size="small" variant="text" color="error"
                      class="cursor-pointer"
                      :aria-label="t('sheq.ppe_config.delete_stock_for', { name: row.item_name })"
                      @click="removeStock(row)"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </div>
      </VCard>
    </template>

    <!-- ── Item dialog ────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="itemDialog" max-width="860" scrollable>
      <VCard rounded="lg">
        <VCardTitle class="text-h6 py-4">
          {{ editing ? t('sheq.ppe_config.edit_item') : t('sheq.ppe_config.add_item') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="4">
              <VTextField
                v-model="itemForm.code" variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.code')" :error-messages="itemForm.errors.code"
                :hint="t('sheq.ppe_config.code_hint')" persistent-hint
              />
            </VCol>
            <VCol cols="12" sm="8">
              <VTextField
                v-model="itemForm.name" variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.item')" :error-messages="itemForm.errors.name"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="itemForm.category" :items="categories ?? []" clearable
                variant="outlined" density="comfortable" :label="t('sheq.ppe_config.category')"
                :error-messages="itemForm.errors.category"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="itemForm.specification" variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.standard')" placeholder="SANS 20345"
                :hint="t('sheq.ppe_config.standard_hint')" persistent-hint
                :error-messages="itemForm.errors.specification"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="itemForm.lifespan_months" type="number" min="1"
                variant="outlined" density="comfortable" :label="t('sheq.ppe_config.life')"
                :hint="t('sheq.ppe_config.life_hint')" persistent-hint
                :error-messages="itemForm.errors.lifespan_months"
              />
            </VCol>
            <VCol cols="12" sm="6" class="d-flex align-center">
              <VCheckbox
                v-model="itemForm.requires_size" density="comfortable" hide-details
                :label="t('sheq.ppe_config.sized')"
              />
            </VCol>
            <VCol cols="12">
              <p class="text-caption text-medium-emphasis mb-0">
                {{ t('sheq.ppe_config.sized_hint') }}
              </p>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" class="cursor-pointer" @click="itemDialog = false">
            {{ t('sheq.ppe_config.cancel') }}
          </VBtn>
          <VBtn
            color="primary" variant="flat" class="cursor-pointer"
            :loading="itemForm.processing" :disabled="itemForm.processing" @click="saveItem"
          >
            {{ t('sheq.ppe_config.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Stock dialog ───────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="stockDialog" max-width="860" scrollable>
      <VCard rounded="lg">
        <VCardTitle class="text-h6 py-4">{{ t('sheq.ppe_config.set_stock') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="stockForm.ppe_item_id" :items="activeItems" item-title="name" item-value="id"
                variant="outlined" density="comfortable" :label="t('sheq.ppe_config.item')"
                :error-messages="stockForm.errors.ppe_item_id"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="stockForm.size" variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.size')"
                :disabled="!!stockItem && !stockItem.requires_size"
                :hint="stockItem && !stockItem.requires_size
                  ? t('sheq.ppe_config.size_na')
                  : t('sheq.ppe_config.size_hint')"
                persistent-hint :error-messages="stockForm.errors.size"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="stockForm.site_id" :items="sites ?? []" item-title="name" item-value="id"
                clearable variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.site')" :hint="t('sheq.ppe_config.site_hint')"
                persistent-hint :error-messages="stockForm.errors.site_id"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="stockForm.quantity_on_hand" type="number" min="0"
                variant="outlined" density="comfortable" :label="t('sheq.ppe_config.on_hand')"
                :hint="t('sheq.ppe_config.on_hand_hint')" persistent-hint
                :error-messages="stockForm.errors.quantity_on_hand"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="stockForm.reorder_level" type="number" min="0"
                variant="outlined" density="comfortable" :label="t('sheq.ppe_config.reorder')"
                :hint="t('sheq.ppe_config.reorder_hint')" persistent-hint
                :error-messages="stockForm.errors.reorder_level"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="stockForm.barcode" variant="outlined" density="comfortable"
                :label="t('sheq.ppe_config.barcode')"
                :error-messages="stockForm.errors.barcode"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" class="cursor-pointer" @click="stockDialog = false">
            {{ t('sheq.ppe_config.cancel') }}
          </VBtn>
          <VBtn
            color="primary" variant="flat" class="cursor-pointer"
            :loading="stockForm.processing" :disabled="stockForm.processing" @click="saveStock"
          >
            {{ t('sheq.ppe_config.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Delete confirmation ────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="!!confirmDelete" max-width="620" @update:model-value="confirmDelete = null">
      <VCard rounded="lg">
        <VCardTitle class="text-h6 py-4">{{ t('sheq.ppe_config.delete_item') }}</VCardTitle>
        <VDivider />
        <VCardText class="text-body-2">
          {{ t('sheq.ppe_config.delete_confirm', { name: confirmDelete?.name ?? '' }) }}
          <VAlert
            v-if="confirmDelete && confirmDelete.stock_rows > 0" type="warning" variant="tonal"
            density="compact" class="mt-3"
          >
            {{ t('sheq.ppe_config.delete_stock_warning', { n: confirmDelete.stock_rows }) }}
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" class="cursor-pointer" @click="confirmDelete = null">
            {{ t('sheq.ppe_config.cancel') }}
          </VBtn>
          <VBtn
            color="error" variant="flat" class="cursor-pointer"
            @click="confirmDelete && removeItem(confirmDelete)"
          >
            {{ t('sheq.ppe_config.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  
    <!-- ── Import ─────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="importDialog" max-width="720">
      <VCard>
        <VCardTitle class="text-body-1">{{ t('sheq.ppe_config.import.title') }}</VCardTitle>
        <VCardSubtitle class="text-wrap pb-2">{{ t('sheq.ppe_config.import.subtitle') }}</VCardSubtitle>
        <VDivider />

        <VCardText>
          <!--
            The template is the file the importer accepts, with two real rows in it. A worked
            example is the difference between a load that works first time and three that do not.
          -->
          <VBtn
            variant="text" size="small" prepend-icon="mdi-download" class="mb-3 px-0"
            href="/sheq/settings/ppe/items/template"
          >
            {{ t('sheq.ppe_config.import.template') }}
          </VBtn>

          <VFileInput
            v-model="importForm.file" accept=".csv,text/csv" show-size
            variant="outlined" density="compact" prepend-icon=""
            prepend-inner-icon="mdi-paperclip"
            :label="t('sheq.ppe_config.import.file')"
            :error-messages="importForm.errors.file"
          />

          <p class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('sheq.ppe_config.import.matched_on_code') }}
          </p>

          <!-- What could not be taken, and why, line by line as the file numbers them. -->
          <VAlert v-if="skipped.length" type="warning" variant="tonal" density="compact" class="mt-3">
            <div class="text-body-2 font-weight-medium mb-1">
              {{ t('sheq.ppe_config.import.skipped', { n: skipped.length }) }}
            </div>
            <ul class="text-caption mb-0 ps-4">
              <li v-for="row in skipped" :key="row.line">
                {{ t('sheq.ppe_config.import.line', { line: row.line }) }} — {{ row.reason }}
              </li>
            </ul>
          </VAlert>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="importDialog = false">{{ t('sheq.ppe_config.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="importForm.processing" :disabled="!importForm.file"
            @click="runImport"
          >{{ t('sheq.ppe_config.import.button') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</div>
</template>

<style scoped>
/* Wide tables scroll inside their own card rather than pushing the page sideways. */
.sheq-scroll { overflow-x: auto; }
.cursor-pointer { cursor: pointer; }
</style>
