<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; revision: number; name: string | null; status: string; is_default: boolean
  product_code: string | null; product_name: string | null; yield_quantity: number
  yield_uom: string | null; lines_count: number; effective_from: string | null; effective_to: string | null
}

const props = defineProps<{
  boms: { data: Row[]; current_page: number; last_page: number; total: number }
  filters: { status?: string; search?: string }
  statuses: string[]
  manufacturable: Array<{ id: string; code: string | null; name: string | null; kind: string }>
  units: Array<{ id: number; code: string; name: string }>
  can: { manage: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? null)
let timer: ReturnType<typeof setTimeout> | undefined

function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.engineering.boms.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
    ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch(status, () => apply())

const statusColor = (s: string) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s] ?? 'default'

// ── New draft ───────────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  item_uuid: null as string | null, name: '', yield_quantity: 1,
  yield_uom_id: null as number | null, batch_size: null as number | null,
  process_loss_percent: 0,
})

function save() {
  form.post(route('manufacturing.engineering.boms.store'), {
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

const headers = [
  { title: t('manufacturing.bom.product'), key: 'product_code' },
  { title: t('manufacturing.bom.revision'), key: 'revision' },
  { title: t('manufacturing.bom.status'), key: 'status' },
  { title: t('manufacturing.bom.yield'), key: 'yield_quantity' },
  { title: t('manufacturing.bom.components'), key: 'lines_count' },
  { title: t('manufacturing.bom.effective'), key: 'effective_from', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.boms.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.boms.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
        {{ t('manufacturing.bom.new_draft') }}
      </VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px"
                    prepend-inner-icon="tabler-search" :label="t('manufacturing.products.search')" />
        <VSelect v-model="status" density="compact" hide-details clearable style="max-width: 220px"
                 :items="statuses.map(s => ({ title: t(`manufacturing.status.${s}`), value: s }))"
                 :label="t('manufacturing.bom.status')" />
      </VCardText>

      <VDataTable :headers="headers" :items="boms.data" item-value="id" hover
                  :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.engineering.boms.show', item.id))">
        <template #item.product_code="{ item }">
          <div>{{ item.product_code }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.product_name }}</div>
        </template>
        <template #item.revision="{ item }">
          {{ t('manufacturing.bom.revision_n', { n: item.revision }) }}
          <VChip v-if="item.is_default" size="x-small" color="primary" label class="ms-1">
            {{ t('manufacturing.bom.default') }}
          </VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" label variant="tonal" :color="statusColor(item.status)">
            {{ t(`manufacturing.status.${item.status}`) }}
          </VChip>
        </template>
        <template #item.yield_quantity="{ item }">{{ item.yield_quantity }} {{ item.yield_uom }}</template>
        <template #item.effective_from="{ item }">
          {{ item.effective_from ?? '—' }} → {{ item.effective_to ?? t('manufacturing.bom.open_ended') }}
        </template>
        <template #no-data>
          <div class="py-8 text-medium-emphasis">
            {{ t('manufacturing.boms.none') }}
            <div class="text-caption">{{ t('manufacturing.boms.none_hint') }}</div>
          </div>
        </template>
      </VDataTable>

      <VCardText v-if="boms.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="boms.current_page" :length="boms.last_page" total-visible="7"
                     @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="t('manufacturing.bom.new_draft')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('manufacturing.boms.new_hint') }}</p>

          <!-- Only things that can be made may carry a recipe; a raw material is bought. -->
          <VAutocomplete v-model="form.item_uuid" :items="manufacturable" item-value="id"
                         :item-title="(i: any) => `${i.code} — ${i.name}`"
                         :error-messages="form.errors.item_uuid" :label="t('manufacturing.bom.product')"
                         class="mb-3" />
          <VTextField v-model="form.name" :label="t('manufacturing.bom.name')" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model.number="form.yield_quantity" type="number" min="0" step="any"
                          :error-messages="form.errors.yield_quantity" :label="t('manufacturing.bom.yield')" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="form.yield_uom_id" clearable :items="units"
                       :item-title="(u: any) => `${u.code} — ${u.name}`" item-value="id"
                       :label="t('manufacturing.products.uom')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.batch_size" type="number" min="0" step="any"
                          :error-messages="form.errors.batch_size" :label="t('manufacturing.bom.batch_size')"
                          :hint="t('manufacturing.bom.batch_size_hint')" persistent-hint />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.process_loss_percent" type="number" min="0" max="99.99" step="any"
                          :error-messages="form.errors.process_loss_percent"
                          :label="t('manufacturing.bom.process_loss')" suffix="%" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.item_uuid" @click="save">
            {{ t('manufacturing.bom.create_draft') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
