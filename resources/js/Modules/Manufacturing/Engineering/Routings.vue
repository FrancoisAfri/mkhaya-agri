<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; revision: number; name: string | null; status: string; is_default: boolean
  product_code: string | null; product_name: string | null; operations_count: number
  min_batch_size: number | null; max_batch_size: number | null; standard_yield_percent: number
  effective_from: string | null; effective_to: string | null
}

const props = defineProps<{
  routings: { data: Row[]; current_page: number; last_page: number }
  filters: { status?: string; search?: string }
  statuses: string[]
  manufacturable: Array<{ id: string; code: string | null; name: string | null }>
  can: { manage: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? null)
let timer: ReturnType<typeof setTimeout> | undefined

function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.engineering.routings.index'), {
    search: search.value || undefined, status: status.value || undefined, ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch(status, () => apply())

const statusColor = (s: string) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s] ?? 'default'

const dialog = ref(false)
const form = useForm({
  item_uuid: null as string | null, name: '', min_batch_size: null as number | null,
  max_batch_size: null as number | null, standard_yield_percent: 100, yield_tolerance_percent: null as number | null,
})

function save() {
  form.post(route('manufacturing.engineering.routings.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}

const headers = [
  { title: t('manufacturing.bom.product'), key: 'product_code' },
  { title: t('manufacturing.bom.revision'), key: 'revision' },
  { title: t('manufacturing.bom.status'), key: 'status' },
  { title: t('manufacturing.routing.operations'), key: 'operations_count' },
  { title: t('manufacturing.routing.batch_range'), key: 'max_batch_size', sortable: false },
  { title: t('manufacturing.routing.yield'), key: 'standard_yield_percent' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.routings.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.routings.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
        {{ t('manufacturing.routing.new_draft') }}
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

      <VDataTable :headers="headers" :items="routings.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.engineering.routings.show', item.id))">
        <template #item.product_code="{ item }">
          <div>{{ item.product_code }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.product_name }}</div>
        </template>
        <template #item.revision="{ item }">
          {{ t('manufacturing.bom.revision_n', { n: item.revision }) }}
          <VChip v-if="item.is_default" size="x-small" color="primary" label class="ms-1">{{ t('manufacturing.bom.default') }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" label variant="tonal" :color="statusColor(item.status)">{{ t(`manufacturing.status.${item.status}`) }}</VChip>
        </template>
        <template #item.max_batch_size="{ item }">
          {{ item.min_batch_size ?? '—' }} – {{ item.max_batch_size ?? '∞' }}
        </template>
        <template #item.standard_yield_percent="{ item }">{{ item.standard_yield_percent }}%</template>
        <template #no-data>
          <div class="py-8 text-medium-emphasis">
            {{ t('manufacturing.routings.none') }}
            <div class="text-caption">{{ t('manufacturing.routings.none_hint') }}</div>
          </div>
        </template>
      </VDataTable>

      <VCardText v-if="routings.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="routings.current_page" :length="routings.last_page" total-visible="7"
                     @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="t('manufacturing.routing.new_draft')">
        <VCardText>
          <VAutocomplete v-model="form.item_uuid" :items="manufacturable" item-value="id"
                         :item-title="(i: any) => `${i.code} — ${i.name}`" :error-messages="form.errors.item_uuid"
                         :label="t('manufacturing.bom.product')" class="mb-3" />
          <VTextField v-model="form.name" :label="t('manufacturing.bom.name')" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model.number="form.min_batch_size" type="number" min="0" step="any"
                          :error-messages="form.errors.min_batch_size" :label="t('manufacturing.routing.min_batch')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.max_batch_size" type="number" min="0" step="any"
                          :error-messages="form.errors.max_batch_size" :label="t('manufacturing.routing.max_batch')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.standard_yield_percent" type="number" min="0" max="100" step="any"
                          suffix="%" :error-messages="form.errors.standard_yield_percent" :label="t('manufacturing.routing.yield')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.yield_tolerance_percent" type="number" min="0" max="100" step="any"
                          suffix="%" :label="t('manufacturing.routing.yield_tolerance')" />
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
