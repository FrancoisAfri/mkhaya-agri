<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Unit = {
  id: number; uuid: string; code: string; name: string; dimension: string; is_base: boolean
  factor_to_base: number; decimal_places: number; is_active: boolean
}
type Conversion = { id: number; stock_item_id: number; from: string | null; to: string | null; factor: number }

const props = defineProps<{
  units: Unit[]
  dimensions: string[]
  conversions: Conversion[]
  stock_items: Array<{ id: number; code: string; name: string }>
}>()

const grouped = computed(() => props.dimensions
  .map(d => ({ dimension: d, units: props.units.filter(u => u.dimension === d) }))
  .filter(g => g.units.length))

const base = (dimension: string) => props.units.find(u => u.dimension === dimension && u.is_base)

function install() {
  router.post(route('manufacturing.setup.units.install'), {}, { preserveScroll: true })
}

// ── Add / edit a unit ───────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Unit | null>(null)
const form = useForm({
  code: '', name: '', dimension: 'MASS', factor_to_base: 1, decimal_places: 3, is_active: true,
})

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(u: Unit) {
  editing.value = u
  form.code = u.code
  form.name = u.name
  form.dimension = u.dimension
  form.factor_to_base = u.factor_to_base
  form.decimal_places = u.decimal_places
  form.is_active = u.is_active
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) {
    form.patch(route('manufacturing.setup.units.update', editing.value.uuid), done)
  } else {
    form.post(route('manufacturing.setup.units.store'), done)
  }
}

// ── Per-item conversions ────────────────────────────────────────────────────
const convForm = useForm({
  stock_item_id: null as number | null, from_uom_id: null as number | null,
  to_uom_id: null as number | null, factor: 1,
})

const unitItems = computed(() => props.units.filter(u => u.is_active)
  .map(u => ({ title: `${u.code} — ${u.name}`, value: u.id })))

const itemLabel = (id: number) => {
  const i = props.stock_items.find(x => x.id === id)
  return i ? `${i.code} — ${i.name}` : `#${id}`
}

function addConversion() {
  convForm.post(route('manufacturing.setup.units.conversions.store'), {
    preserveScroll: true, onSuccess: () => convForm.reset(),
  })
}

function removeConversion(c: Conversion) {
  router.delete(route('manufacturing.setup.units.conversions.destroy', c.id), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.units.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.units.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-download" @click="install">
          {{ t('manufacturing.units.install') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.units.add') }}</VBtn>
      </div>
    </div>

    <VAlert type="info" variant="tonal" class="mb-4">{{ t('manufacturing.units.rule') }}</VAlert>

    <VCard v-if="!units.length" border class="mb-4">
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ t('manufacturing.units.none') }}
        <div class="text-caption mt-1">{{ t('manufacturing.units.none_hint') }}</div>
      </VCardText>
    </VCard>

    <VCard v-for="g in grouped" :key="g.dimension" border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t(`manufacturing.dimension.${g.dimension}`) }}</VCardTitle>
        <VCardSubtitle>
          <template v-if="g.dimension === 'CONTAINER'">{{ t('manufacturing.units.container_hint') }}</template>
          <template v-else>{{ t('manufacturing.units.base_is', { code: base(g.dimension)?.code ?? '—' }) }}</template>
        </VCardSubtitle>
      </VCardItem>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('manufacturing.common.code') }}</th>
            <th>{{ t('manufacturing.common.name') }}</th>
            <th class="text-end">{{ t('manufacturing.units.factor') }}</th>
            <th class="text-end">{{ t('manufacturing.units.decimals') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in g.units" :key="u.id" :class="{ 'opacity-60': !u.is_active }">
            <td>
              {{ u.code }}
              <VChip v-if="u.is_base" size="x-small" label color="primary" class="ms-1">{{ t('manufacturing.units.base') }}</VChip>
            </td>
            <td>{{ u.name }}</td>
            <td class="text-end">{{ u.factor_to_base }}</td>
            <td class="text-end">{{ u.decimal_places }}</td>
            <td class="text-end">
              <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')"
                    @click="openEdit(u)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard border>
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.units.conversions') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.units.conversions_hint') }}</VCardSubtitle>
      </VCardItem>
      <VList v-if="conversions.length" density="compact">
        <VListItem v-for="c in conversions" :key="c.id">
          <VListItemTitle>{{ itemLabel(c.stock_item_id) }}</VListItemTitle>
          <VListItemSubtitle>1 {{ c.from }} = {{ c.factor }} {{ c.to }}</VListItemSubtitle>
          <template #append>
            <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                  @click="removeConversion(c)" />
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.units.no_conversions') }}</VCardText>

      <VCardText>
        <VDivider class="mb-4" />
        <VRow dense>
          <VCol cols="12" md="4">
            <VAutocomplete v-model="convForm.stock_item_id" :items="stock_items" item-value="id"
                           :item-title="(i: any) => `${i.code} — ${i.name}`"
                           :error-messages="convForm.errors.stock_item_id" :label="t('manufacturing.units.item')" />
          </VCol>
          <VCol cols="4" md="2">
            <VSelect v-model="convForm.from_uom_id" :items="unitItems" :label="t('manufacturing.units.from')"
                     :error-messages="convForm.errors.from_uom_id" />
          </VCol>
          <VCol cols="4" md="3">
            <VTextField v-model.number="convForm.factor" type="number" min="0" step="any"
                        :error-messages="convForm.errors.factor" :label="t('manufacturing.units.equals')" />
          </VCol>
          <VCol cols="4" md="3">
            <VSelect v-model="convForm.to_uom_id" :items="unitItems" :label="t('manufacturing.units.to')"
                     :error-messages="convForm.errors.to_uom_id" />
          </VCol>
        </VRow>
        <VBtn color="primary" :loading="convForm.processing"
              :disabled="!convForm.stock_item_id || !convForm.from_uom_id || !convForm.to_uom_id"
              @click="addConversion">{{ t('manufacturing.units.add_conversion') }}</VBtn>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="480">
      <VCard :title="editing ? t('manufacturing.units.edit') : t('manufacturing.units.add')">
        <VCardText>
          <VRow dense>
            <VCol cols="4">
              <VTextField v-model="form.code" :disabled="!!editing" :error-messages="form.errors.code"
                          :label="t('manufacturing.common.code')" />
            </VCol>
            <VCol cols="8">
              <VTextField v-model="form.name" :error-messages="form.errors.name" :label="t('manufacturing.common.name')" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="form.dimension" :disabled="!!editing"
                       :items="dimensions.map(d => ({ title: t(`manufacturing.dimension.${d}`), value: d }))"
                       :error-messages="form.errors.dimension" :label="t('manufacturing.units.dimension')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.factor_to_base" type="number" min="0" step="any"
                          :disabled="!!editing?.is_base" :error-messages="form.errors.factor_to_base"
                          :label="t('manufacturing.units.factor')"
                          :hint="t('manufacturing.units.factor_hint')" persistent-hint />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.decimal_places" type="number" min="0" max="6"
                          :label="t('manufacturing.units.decimals')" />
            </VCol>
          </VRow>
          <VSwitch v-if="editing" v-model="form.is_active" color="primary" hide-details class="mt-2"
                   :label="t('manufacturing.product.active')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
