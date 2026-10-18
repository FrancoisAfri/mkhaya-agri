<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute
type Tpl = { kind: string; uuid: string | null; name: string; width_mm: number; height_mm: number; symbology: string; fields: string[]; fields_available: string[] }
defineProps<{ templates: Tpl[]; printers: Array<{ uuid: string; name: string; host: string; port: number; dpi: number; is_active: boolean; warehouse: string | null }>;
  warehouses: Array<{ id: number; title: string }>; can: { manage: boolean } }>()
const editing = ref<Tpl | null>(null)
const form = useForm({ uuid: null as string | null, kind: '', name: '', width_mm: 100, height_mm: 50, symbology: 'CODE128', fields: [] as string[] })
function edit(tpl: Tpl) { editing.value = tpl; Object.assign(form, { uuid: tpl.uuid, kind: tpl.kind, name: tpl.name, width_mm: tpl.width_mm, height_mm: tpl.height_mm, symbology: tpl.symbology, fields: [...tpl.fields] }) }
const printer = useForm({ name: '', host: '', port: 9100, dpi: 203, warehouse_id: null as number | null })
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">{{ t('warehouse_management.labels_setup.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.labels_setup.subtitle') }}</p>
    <RefusalAlert />
    <VRow>
      <VCol cols="12" md="7">
        <VCard border :title="t('warehouse_management.labels_setup.templates')">
          <VList lines="two">
            <VListItem v-for="tpl in templates" :key="tpl.kind" :title="`${t(`warehouse_management.label_kind.${tpl.kind}`)} · ${tpl.name}`"
                       :subtitle="`${tpl.width_mm} × ${tpl.height_mm} mm · ${tpl.symbology} · ${tpl.fields.map(f => t(`warehouse_management.label_field.${f}`)).join(', ')}`">
              <template v-if="can.manage" #append><VBtn size="small" variant="text" @click="edit(tpl)">{{ t('warehouse_management.common.edit') }}</VBtn></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border :title="t('warehouse_management.labels_setup.printers')">
          <VList density="compact"><VListItem v-for="p in printers" :key="p.uuid" :title="p.name" :subtitle="`${p.host}:${p.port} · ${p.dpi} dpi${p.warehouse ? ' · ' + p.warehouse : ''}`" /></VList>
          <VCardText v-if="can.manage" class="d-flex flex-column gap-2">
            <VTextField v-model="printer.name" density="compact" :label="t('warehouse_management.common.name')" />
            <div class="d-flex gap-2"><VTextField v-model="printer.host" density="compact" :label="t('warehouse_management.labels_setup.address')" :error-messages="printer.errors.host" />
              <VTextField v-model.number="printer.port" type="number" density="compact" style="max-width: 100px" :label="t('warehouse_management.labels_setup.port')" /></div>
            <div class="d-flex gap-2"><VSelect v-model="printer.dpi" :items="[203, 300, 600]" density="compact" label="dpi" />
              <VSelect v-model="printer.warehouse_id" :items="warehouses" item-value="id" clearable density="compact" :label="t('warehouse_management.common.warehouse')" /></div>
            <p class="text-caption mb-0">{{ t('warehouse_management.labels_setup.private_only') }}</p>
            <VBtn variant="tonal" @click="printer.post(route('warehouse.labels.printers.store'), { preserveScroll: true, onSuccess: () => printer.reset() })">{{ t('warehouse_management.common.add') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
    <VDialog :model-value="editing !== null" max-width="520" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="t(`warehouse_management.label_kind.${editing.kind}`)">
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.name" :label="t('warehouse_management.common.name')" />
          <div class="d-flex gap-2"><VTextField v-model.number="form.width_mm" type="number" :label="t('warehouse_management.labels_setup.width')" suffix="mm" />
            <VTextField v-model.number="form.height_mm" type="number" :label="t('warehouse_management.labels_setup.height')" suffix="mm" /></div>
          <VSelect v-model="form.symbology" :items="['CODE128', 'GS1_128', 'QR']" :label="t('warehouse_management.labels_setup.barcode')" />
          <VSelect v-model="form.fields" :items="editing.fields_available.map(f => ({ value: f, title: t(`warehouse_management.label_field.${f}`) }))" multiple chips :label="t('warehouse_management.labels_setup.fields')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="form.post(route('warehouse.labels.templates.store'), { preserveScroll: true, onSuccess: () => editing = null })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
