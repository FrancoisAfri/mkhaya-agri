<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; code: string | null; name: string | null; kind: string; category: string | null
  uom: string | null; is_lot_tracked: boolean; is_active: boolean
  is_manufactured: boolean; approved_boms: number
}

const props = defineProps<{
  items: { data: Row[]; current_page: number; last_page: number; total: number }
  filters: { kind?: string; category?: string; search?: string; without_bom?: boolean | string }
  kinds: string[]
  manufactured_kinds: string[]
  available_stock_items: Array<{ id: number; code: string; name: string; uom: string | null }>
  units: Array<{ id: number; code: string; name: string; dimension: string }>
  categories: Array<{ id: number; uuid: string; name: string; description: string | null; is_active: boolean; products: number }>
  can: { manage: boolean }
}>()

const search = ref(props.filters.search ?? '')
const kind = ref<string | null>(props.filters.kind ?? null)
const category = ref<string | null>(props.filters.category ?? null)
const withoutBom = ref(!!props.filters.without_bom && props.filters.without_bom !== '0')

let timer: ReturnType<typeof setTimeout> | undefined

function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.engineering.products.index'), {
    search: search.value || undefined,
    kind: kind.value || undefined,
    category: category.value || undefined,
    without_bom: withoutBom.value ? 1 : undefined,
    ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

// Debounced: a query per keystroke on a few thousand stock items is a lot of ilike scans.
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch([kind, category, withoutBom], () => apply())

// ── Add a product ───────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  stock_item_id: null as number | null,
  kind: 'FINISHED',
  category_id: null as number | null,
  default_uom_id: null as number | null,
  material_grade: '',
  is_lot_tracked: false,
})

// Only live categories are offered for a new choice; a retired one stays on the products using it.
const activeCategories = computed(() => props.categories.filter(c => c.is_active))

// ── Maintaining the category list ───────────────────────────────────────────
const categoryDialog = ref(false)
const categoryForm = useForm({ name: '', description: '' })

function addCategory() {
  categoryForm.post(route('manufacturing.engineering.categories.store'), {
    preserveScroll: true, preserveState: true, onSuccess: () => categoryForm.reset(),
  })
}

function renameCategory(c: { uuid: string }, name: string) {
  router.patch(route('manufacturing.engineering.categories.update', c.uuid), { name }, { preserveScroll: true, preserveState: true })
}

function toggleCategory(c: { uuid: string; is_active: boolean }) {
  router.patch(route('manufacturing.engineering.categories.update', c.uuid), { is_active: !c.is_active },
    { preserveScroll: true, preserveState: true })
}

function removeCategory(c: { uuid: string }) {
  router.delete(route('manufacturing.engineering.categories.destroy', c.uuid), { preserveScroll: true, preserveState: true })
}

function save() {
  form.post(route('manufacturing.engineering.products.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

const headers = [
  { title: t('manufacturing.products.code'), key: 'code' },
  { title: t('manufacturing.products.name'), key: 'name' },
  { title: t('manufacturing.products.kind'), key: 'kind' },
  { title: t('manufacturing.products.uom'), key: 'uom' },
  { title: t('manufacturing.products.recipe'), key: 'approved_boms', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.products.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.products.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-category" @click="categoryDialog = true">
          {{ t('manufacturing.categories.manage') }}
        </VBtn>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
          {{ t('manufacturing.products.add') }}
        </VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px"
                    prepend-inner-icon="tabler-search" :label="t('manufacturing.products.search')" />
        <VSelect v-model="kind" density="compact" hide-details clearable style="max-width: 220px"
                 :items="kinds.map(k => ({ title: t(`manufacturing.kind.${k}`), value: k }))"
                 :label="t('manufacturing.products.kind')" />
        <VSelect v-model="category" density="compact" hide-details clearable style="max-width: 220px"
                 :items="categories" item-value="uuid" item-title="name"
                 :label="t('manufacturing.products.category')" />
        <VSwitch v-model="withoutBom" density="compact" hide-details color="warning"
                 :label="t('manufacturing.products.only_without_recipe')" />
      </VCardText>

      <VDataTable :headers="headers" :items="items.data" item-value="id" hover
                  :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.engineering.products.show', item.id))">
        <template #item.kind="{ item }">
          <VChip size="small" label variant="tonal">{{ t(`manufacturing.kind.${item.kind}`) }}</VChip>
          <VChip v-if="!item.is_active" size="x-small" label class="ms-1">{{ t('manufacturing.products.inactive') }}</VChip>
        </template>
        <template #item.approved_boms="{ item }">
          <template v-if="!item.is_manufactured">
            <span class="text-medium-emphasis">{{ t('manufacturing.products.bought') }}</span>
          </template>
          <VChip v-else-if="item.approved_boms > 0" size="small" label color="success" variant="tonal">
            {{ t('manufacturing.products.has_recipe') }}
          </VChip>
          <VChip v-else size="small" label color="warning" variant="tonal">
            {{ t('manufacturing.products.no_recipe') }}
          </VChip>
        </template>
        <template #no-data>
          <div class="py-8 text-medium-emphasis">
            {{ t('manufacturing.products.none') }}
            <div class="text-caption">{{ t('manufacturing.products.none_hint') }}</div>
          </div>
        </template>
      </VDataTable>

      <VCardText v-if="items.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="items.current_page" :length="items.last_page" total-visible="7"
                     @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="t('manufacturing.products.add')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('manufacturing.products.add_hint') }}</p>

          <!-- A product extends a finance stock item. It never asks for a code and a name,
               because those exist already and a second set would drift from the first. -->
          <VAutocomplete v-model="form.stock_item_id" :error-messages="form.errors.stock_item_id"
                         :items="available_stock_items"
                         :item-title="(i: any) => `${i.code} — ${i.name}`" item-value="id"
                         :label="t('manufacturing.products.stock_item')" class="mb-3" />
          <VSelect v-model="form.kind" :error-messages="form.errors.kind"
                   :items="kinds.map(k => ({ title: t(`manufacturing.kind.${k}`), value: k }))"
                   :label="t('manufacturing.products.kind')" class="mb-3" />
          <VSelect v-model="form.default_uom_id" clearable :items="units"
                   :item-title="(u: any) => `${u.code} — ${u.name}`" item-value="id"
                   :label="t('manufacturing.products.uom')" class="mb-3" />
          <VSelect v-model="form.category_id" clearable :items="activeCategories" item-value="id" item-title="name"
                   :error-messages="form.errors.category_id" :label="t('manufacturing.products.category')"
                   :no-data-text="t('manufacturing.categories.none_yet')" class="mb-3">
            <template #append-item>
              <VDivider />
              <VListItem prepend-icon="tabler-plus" :title="t('manufacturing.categories.add_new')"
                         @click="categoryDialog = true" />
            </template>
          </VSelect>
          <VTextField v-model="form.material_grade" :label="t('manufacturing.products.material_grade')" class="mb-3" />
          <VSwitch v-model="form.is_lot_tracked" color="primary" hide-details
                   :label="t('manufacturing.products.lot_tracked')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.stock_item_id" @click="save">
            {{ t('manufacturing.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="categoryDialog" max-width="560">
      <VCard :title="t('manufacturing.categories.title')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('manufacturing.categories.hint') }}</p>

          <div class="d-flex gap-2 align-start mb-2">
            <VTextField v-model="categoryForm.name" density="compact" :error-messages="categoryForm.errors.name"
                        :label="t('manufacturing.categories.name')" @keyup.enter="addCategory" />
            <VBtn color="primary" :loading="categoryForm.processing" :disabled="!categoryForm.name.trim()"
                  @click="addCategory">{{ t('manufacturing.common.add') }}</VBtn>
          </div>

          <VList v-if="categories.length" density="compact">
            <VListItem v-for="c in categories" :key="c.uuid" :class="{ 'opacity-60': !c.is_active }">
              <VTextField :model-value="c.name" density="compact" variant="plain" hide-details
                          @change="(e: Event) => renameCategory(c, (e.target as HTMLInputElement).value)" />
              <template #append>
                <span class="text-caption text-medium-emphasis me-2">
                  {{ t('manufacturing.categories.n_products', { n: c.products }) }}
                </span>
                <VChip v-if="!c.is_active" size="x-small" label class="me-1">{{ t('manufacturing.categories.retired') }}</VChip>
                <VBtn size="small" variant="text" :icon="c.is_active ? 'tabler-eye-off' : 'tabler-eye'"
                      :aria-label="c.is_active ? t('manufacturing.categories.retire') : t('manufacturing.categories.restore')"
                      @click="toggleCategory(c)" />
                <VBtn size="small" variant="text" icon="tabler-trash" :aria-label="t('manufacturing.common.remove')"
                      @click="removeCategory(c)" />
              </template>
            </VListItem>
          </VList>
          <div v-else class="text-medium-emphasis text-body-2">{{ t('manufacturing.categories.none_yet') }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="categoryDialog = false">{{ t('manufacturing.categories.done') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
