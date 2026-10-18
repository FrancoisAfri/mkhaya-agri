<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Rate = { service: string; zone: string | null; max_weight_kg: number; price: number; transit_days: number | null }
type Carrier = { id: string; code: string; name: string; driver: string; account_ref: string | null; tracking_url: string | null; is_active: boolean; capabilities: Record<string, boolean>; rates: Rate[] }
const props = defineProps<{ carriers: Carrier[]; drivers: string[]; can: { manage: boolean } }>()

const editing = ref<string | null | 'new'>(null)
const form = useForm({ code: '', name: '', driver: 'MANUAL', account_ref: '', tracking_url: '', is_active: true, rates: [] as Rate[] })
function edit(c: Carrier | null) {
  editing.value = c ? c.id : 'new'
  form.defaults({ code: c?.code ?? '', name: c?.name ?? '', driver: c?.driver ?? 'MANUAL', account_ref: c?.account_ref ?? '', tracking_url: c?.tracking_url ?? '',
    is_active: c?.is_active ?? true, rates: (c?.rates ?? []).map(r => ({ ...r })) })
  form.reset()
  form.clearErrors()
}
const addRate = () => form.rates.push({ service: '', zone: null, max_weight_kg: 1, price: 0, transit_days: null })
function save() {
  const url = editing.value === 'new' ? route('warehouse.integrations.carriers.store') : route('warehouse.integrations.carriers.update', editing.value as string)
  form.post(url, { preserveScroll: true, onSuccess: () => { if (!Object.keys(form.errors).length) editing.value = null } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.carriers.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.carriers.subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn v-if="can.manage" color="primary" @click="edit(null)">{{ t('warehouse_management.carriers.add') }}</VBtn>
    </div>

    <VCard v-if="!carriers.length" border><VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.carriers.none') }}</VCardText></VCard>
    <VCard v-for="c in carriers" :key="c.id" border class="mb-3">
      <VCardItem>
        <VCardTitle>{{ c.name }} <span class="text-caption">{{ c.code }}</span>
          <VChip size="x-small" class="ms-1">{{ c.driver }}</VChip>
          <VChip v-if="!c.is_active" size="x-small" color="error" class="ms-1">{{ t('warehouse_management.carriers.off') }}</VChip>
        </VCardTitle>
        <VCardSubtitle>
          <span v-for="(on, k) in c.capabilities" :key="k" class="me-3">{{ on ? '✓' : '—' }} {{ t(`warehouse_management.carriers.cap.${k}`) }}</span>
        </VCardSubtitle>
        <template #append><VBtn v-if="can.manage" size="small" variant="tonal" @click="edit(c)">{{ t('warehouse_management.common.edit') }}</VBtn></template>
      </VCardItem>
      <div style="overflow-x: auto">
        <VTable v-if="c.rates.length" density="compact">
          <thead><tr><th>{{ t('warehouse_management.outbound.service') }}</th><th>{{ t('warehouse_management.carriers.zone') }}</th><th class="text-end">{{ t('warehouse_management.carriers.up_to_kg') }}</th><th class="text-end">{{ t('warehouse_management.carriers.price') }}</th><th class="text-end">{{ t('warehouse_management.carriers.transit') }}</th></tr></thead>
          <tbody><tr v-for="(r, i) in c.rates" :key="i"><td>{{ r.service }}</td><td>{{ r.zone ?? t('warehouse_management.carriers.anywhere') }}</td><td class="text-end">{{ r.max_weight_kg }}</td><td class="text-end">{{ r.price.toFixed(2) }}</td><td class="text-end">{{ r.transit_days ?? '—' }}</td></tr></tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog :model-value="editing !== null" max-width="760" @update:model-value="v => { if (!v) editing = null }">
      <VCard>
        <VCardTitle>{{ editing === 'new' ? t('warehouse_management.carriers.add') : form.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal" class="mb-3">{{ (form.errors as any).refused }}</VAlert>
          <VRow dense>
            <VCol cols="4"><VTextField v-model="form.code" :label="t('warehouse_management.carriers.code')" :error-messages="form.errors.code" /></VCol>
            <VCol cols="8"><VTextField v-model="form.name" :label="t('warehouse_management.carriers.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="4"><VSelect v-model="form.driver" :items="drivers" :label="t('warehouse_management.carriers.driver')" /></VCol>
            <VCol cols="8"><VTextField v-model="form.account_ref" :label="t('warehouse_management.carriers.account')" /></VCol>
            <VCol cols="12"><VTextField v-model="form.tracking_url" :label="t('warehouse_management.carriers.tracking_url')" :hint="t('warehouse_management.carriers.tracking_url_hint')" persistent-hint :error-messages="form.errors.tracking_url" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.is_active" :label="t('warehouse_management.carriers.active')" hide-details color="primary" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-3 mb-1">{{ t('warehouse_management.carriers.rate_card') }}</div>
          <VRow v-for="(r, i) in form.rates" :key="i" dense>
            <VCol cols="3"><VTextField v-model="r.service" density="compact" :label="t('warehouse_management.outbound.service')" /></VCol>
            <VCol cols="3"><VTextField v-model="r.zone" density="compact" :label="t('warehouse_management.carriers.zone')" /></VCol>
            <VCol cols="2"><VTextField v-model.number="r.max_weight_kg" type="number" density="compact" :label="t('warehouse_management.carriers.up_to_kg')" /></VCol>
            <VCol cols="2"><VTextField v-model.number="r.price" type="number" density="compact" :label="t('warehouse_management.carriers.price')" /></VCol>
            <VCol cols="1"><VTextField v-model.number="r.transit_days" type="number" density="compact" :label="t('warehouse_management.carriers.transit')" /></VCol>
            <VCol cols="1"><VBtn icon="mdi-close" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.rates.splice(i, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="tonal" @click="addRate">{{ t('warehouse_management.carriers.add_rate') }}</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
