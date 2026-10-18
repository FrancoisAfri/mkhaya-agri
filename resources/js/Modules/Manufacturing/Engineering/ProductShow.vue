<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'

const { t } = useI18n()
const route = ziggyRoute

type Version = { id: string; version: string; status: string; effective_from: string | null
  effective_to: string | null; change_reason: string | null; approved_at: string | null }
type Doc = { id: string; kind: string; title: string; document_uuid: string | null
  document_title: string | null; external_reference: string | null }
type Substitute = { id: string; stock_item_id: number; code: string | null; name: string | null
  ratio: number; restriction: string | null; requires_approval: boolean
  approved_at: string | null; is_usable: boolean }
type Quality = { id: string; standard_code: string | null; standard_name: string
  inspection_stage: string; inspection_plan_reference: string | null; is_mandatory: boolean }
type BomRow = { id: string; revision: number; status: string; is_default: boolean
  effective_from: string | null; effective_to: string | null }

const props = defineProps<{
  item: {
    id: string; code: string | null; name: string | null; kind: string; category: string | null
    category_id: number | null
    uom: string | null; is_active: boolean; is_manufactured: boolean; specification: string | null
    material_grade: string | null; colour: string | null; size: string | null; weight_kg: number | null
    shelf_life_days: number | null; storage_conditions: string | null; handling_instructions: string | null
    is_lot_tracked: boolean; is_serial_tracked: boolean; default_uom_id: number | null
    standard_cost: number | null
    dimensions: { length?: number; width?: number; height?: number; uom?: string } | null
    attributes: Record<string, string | null> | null
    versions: Version[]; documents: Doc[]; substitutes: Substitute[]
    quality_requirements: Quality[]; boms: BomRow[]
  }
  kinds: string[]
  units: Array<{ id: number; code: string; name: string }>
  categories: Array<{ id: number; uuid: string; name: string; is_active: boolean }>
  document_kinds: string[]
  inspection_stages: string[]
  substitute_options: Array<{ id: number; code: string; name: string }>
  documents_panel: {
    available: boolean; relation: string | null; record: string | number | null; can_upload: boolean
    count: number; documents: Array<{ uuid: string; title: string; category: string | null
      expiry_date: string | null; is_expired: boolean; uploaded_at: string | null }>
  }
  can: { manage: boolean; approve_substitution: boolean; manage_boms: boolean }
}>()

const tab = ref('details')
const confirmer = ref()

// ── Details ─────────────────────────────────────────────────────────────────
const details = useForm({
  kind: props.item.kind,
  category_id: props.item.category_id,
  default_uom_id: props.item.default_uom_id,
  specification: props.item.specification ?? '',
  material_grade: props.item.material_grade ?? '',
  colour: props.item.colour ?? '',
  size: props.item.size ?? '',
  weight_kg: props.item.weight_kg,
  shelf_life_days: props.item.shelf_life_days,
  storage_conditions: props.item.storage_conditions ?? '',
  handling_instructions: props.item.handling_instructions ?? '',
  is_lot_tracked: props.item.is_lot_tracked,
  is_serial_tracked: props.item.is_serial_tracked,
  is_active: props.item.is_active,
  dimensions: {
    length: props.item.dimensions?.length ?? null,
    width: props.item.dimensions?.width ?? null,
    height: props.item.dimensions?.height ?? null,
    uom: props.item.dimensions?.uom ?? 'mm',
  } as { length: number | null; width: number | null; height: number | null; uom: string },
  attributes: Object.entries(props.item.attributes ?? {}).map(([key, value]) => ({ key, value: value ?? '' })),
})

function addAttribute() {
  details.attributes.push({ key: '', value: '' })
}

function saveDetails() {
  details.patch(route('manufacturing.engineering.products.update', props.item.id), { preserveScroll: true })
}

// ── Versions ────────────────────────────────────────────────────────────────
const versionForm = useForm({ version: '', effective_from: '', change_reason: '' })

function addVersion() {
  versionForm.post(route('manufacturing.engineering.products.versions.store', props.item.id), {
    preserveScroll: true, onSuccess: () => versionForm.reset(),
  })
}

function approveVersion(v: Version) {
  router.post(route('manufacturing.engineering.products.versions.approve', [props.item.id, v.id]), {},
    { preserveScroll: true })
}

// ── Documents ───────────────────────────────────────────────────────────────
const docForm = useForm({ kind: 'DRAWING', title: '', document_uuid: null as string | null, external_reference: '' })

function addDocument() {
  docForm.post(route('manufacturing.engineering.products.documents.store', props.item.id), {
    preserveScroll: true, onSuccess: () => docForm.reset(),
  })
}

function removeDocument(d: Doc) {
  confirmer.value.open({
    title: t('manufacturing.product.remove_document'),
    message: t('manufacturing.product.remove_named', { name: d.title }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.products.documents.destroy', [props.item.id, d.id]),
    { preserveScroll: true }))
}

// ── Substitutes ─────────────────────────────────────────────────────────────
const subForm = useForm({
  substitute_stock_item_id: null as number | null, ratio: 1, restriction: '', requires_approval: true,
})

function addSubstitute() {
  subForm.post(route('manufacturing.engineering.products.substitutes.store', props.item.id), {
    preserveScroll: true, onSuccess: () => subForm.reset(),
  })
}

function approveSubstitute(s: Substitute) {
  router.post(route('manufacturing.engineering.products.substitutes.approve', [props.item.id, s.id]), {},
    { preserveScroll: true })
}

function removeSubstitute(s: Substitute) {
  confirmer.value.open({
    title: t('manufacturing.product.remove_substitute'),
    message: t('manufacturing.product.remove_named', { name: s.code ?? `#${s.stock_item_id}` }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.products.substitutes.destroy', [props.item.id, s.id]),
    { preserveScroll: true }))
}

// ── Quality ─────────────────────────────────────────────────────────────────
const qualityForm = useForm({
  standard_code: '', standard_name: '', inspection_stage: 'FINAL',
  inspection_plan_reference: '', is_mandatory: true,
})

function addQuality() {
  qualityForm.post(route('manufacturing.engineering.products.quality.store', props.item.id), {
    preserveScroll: true, onSuccess: () => qualityForm.reset(),
  })
}

function removeQuality(q: Quality) {
  confirmer.value.open({
    title: t('manufacturing.product.remove_quality'),
    message: t('manufacturing.product.remove_named', { name: q.standard_name }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.products.quality.destroy', [props.item.id, q.id]),
    { preserveScroll: true }))
}

// ── Recipes ─────────────────────────────────────────────────────────────────
const bomForm = useForm({ item_uuid: props.item.id, yield_quantity: 1, name: '' })

function newBom() {
  bomForm.post(route('manufacturing.engineering.boms.store'), { preserveScroll: true })
}

const statusColor = (s: string) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s] ?? 'default'

// Live categories, plus the product's own even if it has since been retired, so it still shows.
const categoryItems = computed(() =>
  props.categories.filter(c => c.is_active || c.id === props.item.category_id))

const kindItems = computed(() => props.kinds.map(k => ({ title: t(`manufacturing.kind.${k}`), value: k })))
const unitItems = computed(() => props.units.map(u => ({ title: `${u.code} — ${u.name}`, value: u.id })))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
              :href="route('manufacturing.engineering.products.index')">
          {{ t('manufacturing.product.back') }}
        </VBtn>
        <h4 class="text-h4">{{ item.code }} — {{ item.name }}</h4>
        <div class="d-flex gap-2 mt-1">
          <VChip size="small" label variant="tonal">{{ t(`manufacturing.kind.${item.kind}`) }}</VChip>
          <VChip v-if="!item.is_active" size="small" label>{{ t('manufacturing.products.inactive') }}</VChip>
          <VChip v-if="item.standard_cost !== null" size="small" label variant="tonal">
            {{ t('manufacturing.product.standard_cost') }}: {{ item.standard_cost.toFixed(2) }}
          </VChip>
        </div>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="details">{{ t('manufacturing.product.tab_details') }}</VTab>
      <VTab value="recipes">{{ t('manufacturing.product.tab_recipes') }} ({{ item.boms.length }})</VTab>
      <VTab value="versions">{{ t('manufacturing.product.tab_versions') }} ({{ item.versions.length }})</VTab>
      <VTab value="documents">{{ t('manufacturing.product.tab_documents') }} ({{ item.documents.length }})</VTab>
      <VTab value="substitutes">{{ t('manufacturing.product.tab_substitutes') }} ({{ item.substitutes.length }})</VTab>
      <VTab value="quality">{{ t('manufacturing.product.tab_quality') }} ({{ item.quality_requirements.length }})</VTab>
    </VTabs>

    <VWindow v-model="tab" :touch="false">
      <!-- ── Details ──────────────────────────────────────────────────────── -->
      <VWindowItem value="details">
        <VCard border>
          <VCardText>
            <VRow>
              <VCol cols="12" md="4">
                <VSelect v-model="details.kind" :items="kindItems" :disabled="!can.manage"
                         :error-messages="details.errors.kind" :label="t('manufacturing.products.kind')" />
              </VCol>
              <VCol cols="12" md="4">
                <VSelect v-model="details.default_uom_id" :items="unitItems" clearable :disabled="!can.manage"
                         :label="t('manufacturing.products.uom')" />
              </VCol>
              <VCol cols="12" md="4">
                <VSelect v-model="details.category_id" clearable :disabled="!can.manage" :items="categoryItems"
                         item-value="id" item-title="name" :error-messages="details.errors.category_id"
                         :no-data-text="t('manufacturing.categories.none_yet')"
                         :label="t('manufacturing.products.category')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="details.material_grade" :disabled="!can.manage"
                            :label="t('manufacturing.products.material_grade')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="details.colour" :disabled="!can.manage" :label="t('manufacturing.product.colour')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="details.size" :disabled="!can.manage" :label="t('manufacturing.product.size')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model.number="details.weight_kg" type="number" min="0" step="any"
                            :disabled="!can.manage" :label="t('manufacturing.product.weight_kg')" />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField v-model.number="details.dimensions.length" type="number" min="0" step="any"
                            :disabled="!can.manage" :label="t('manufacturing.product.length')" />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField v-model.number="details.dimensions.width" type="number" min="0" step="any"
                            :disabled="!can.manage" :label="t('manufacturing.product.width')" />
              </VCol>
              <VCol cols="6" md="3">
                <VTextField v-model.number="details.dimensions.height" type="number" min="0" step="any"
                            :disabled="!can.manage" :label="t('manufacturing.product.height')" />
              </VCol>
              <VCol cols="6" md="3">
                <VSelect v-model="details.dimensions.uom" :items="['mm', 'cm', 'm']" :disabled="!can.manage"
                         :label="t('manufacturing.product.dimension_unit')" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="details.specification" rows="2" auto-grow :disabled="!can.manage"
                           :label="t('manufacturing.product.specification')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField v-model.number="details.shelf_life_days" type="number" min="1" :disabled="!can.manage"
                            :error-messages="details.errors.shelf_life_days"
                            :label="t('manufacturing.product.shelf_life_days')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextarea v-model="details.storage_conditions" rows="2" auto-grow :disabled="!can.manage"
                           :label="t('manufacturing.product.storage_conditions')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextarea v-model="details.handling_instructions" rows="2" auto-grow :disabled="!can.manage"
                           :label="t('manufacturing.product.handling_instructions')" />
              </VCol>
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-1">
                  <div class="text-subtitle-2">{{ t('manufacturing.product.attributes') }}</div>
                  <VBtn v-if="can.manage" size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="addAttribute">
                    {{ t('manufacturing.common.add') }}
                  </VBtn>
                </div>
                <div v-if="!details.attributes.length" class="text-caption text-medium-emphasis">
                  {{ t('manufacturing.product.no_attributes') }}
                </div>
                <VRow v-for="(attr, i) in details.attributes" :key="i" dense>
                  <VCol cols="5">
                    <VTextField v-model="attr.key" density="compact" :disabled="!can.manage"
                                :error-messages="(details.errors as any)[`attributes.${i}.key`]"
                                :label="t('manufacturing.product.attribute')" />
                  </VCol>
                  <VCol cols="6">
                    <VTextField v-model="attr.value" density="compact" :disabled="!can.manage"
                                :label="t('manufacturing.product.value')" />
                  </VCol>
                  <VCol cols="1" class="d-flex align-center">
                    <VBtn v-if="can.manage" icon="tabler-x" variant="text" size="small"
                          :aria-label="t('manufacturing.common.remove')" @click="details.attributes.splice(i, 1)" />
                  </VCol>
                </VRow>
              </VCol>
              <VCol cols="12" class="d-flex gap-6 flex-wrap">
                <VSwitch v-model="details.is_lot_tracked" color="primary" hide-details :disabled="!can.manage"
                         :label="t('manufacturing.products.lot_tracked')" />
                <VSwitch v-model="details.is_serial_tracked" color="primary" hide-details :disabled="!can.manage"
                         :label="t('manufacturing.product.serial_tracked')" />
                <VSwitch v-model="details.is_active" color="primary" hide-details :disabled="!can.manage"
                         :label="t('manufacturing.product.active')" />
              </VCol>
            </VRow>
            <p v-if="details.errors.kind" class="text-error text-body-2 mt-2">{{ details.errors.kind }}</p>
          </VCardText>
          <VCardActions v-if="can.manage">
            <VSpacer />
            <VBtn color="primary" :loading="details.processing" @click="saveDetails">
              {{ t('manufacturing.common.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>

      <!-- ── Recipes ──────────────────────────────────────────────────────── -->
      <VWindowItem value="recipes">
        <VCard border>
          <VAlert v-if="!item.is_manufactured" type="info" variant="tonal" class="ma-4">
            {{ t('manufacturing.product.bought_no_recipe') }}
          </VAlert>
          <VList v-if="item.boms.length">
            <VListItem v-for="b in item.boms" :key="b.id" :to="route('manufacturing.engineering.boms.show', b.id)">
              <VListItemTitle>
                {{ t('manufacturing.bom.revision_n', { n: b.revision }) }}
                <VChip v-if="b.is_default" size="x-small" color="primary" label class="ms-2">
                  {{ t('manufacturing.bom.default') }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle>
                {{ b.effective_from ?? '—' }} → {{ b.effective_to ?? t('manufacturing.bom.open_ended') }}
              </VListItemSubtitle>
              <template #append>
                <VChip size="small" label variant="tonal" :color="statusColor(b.status)">
                  {{ t(`manufacturing.status.${b.status}`) }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else-if="item.is_manufactured" class="text-medium-emphasis">
            {{ t('manufacturing.product.no_recipes') }}
          </VCardText>

          <VCardText v-if="can.manage_boms && item.is_manufactured">
            <VDivider class="mb-4" />
            <div class="d-flex gap-3 flex-wrap align-center">
              <VTextField v-model="bomForm.name" density="compact" hide-details style="max-width: 260px"
                          :label="t('manufacturing.bom.name')" />
              <VTextField v-model.number="bomForm.yield_quantity" type="number" min="0" step="any"
                          density="compact" hide-details style="max-width: 160px"
                          :error-messages="bomForm.errors.yield_quantity" :label="t('manufacturing.bom.yield')" />
              <VBtn color="primary" prepend-icon="tabler-plus" :loading="bomForm.processing" @click="newBom">
                {{ t('manufacturing.bom.new_draft') }}
              </VBtn>
            </div>
            <p v-if="bomForm.errors.item_uuid" class="text-error text-body-2 mt-2">{{ bomForm.errors.item_uuid }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Engineering versions ────────────────────────────────────────── -->
      <VWindowItem value="versions">
        <VCard border>
          <VList v-if="item.versions.length">
            <VListItem v-for="v in item.versions" :key="v.id">
              <VListItemTitle>{{ t('manufacturing.product.version_n', { v: v.version }) }}</VListItemTitle>
              <VListItemSubtitle>
                {{ v.change_reason }}
                <template v-if="v.effective_from"> · {{ v.effective_from }} → {{ v.effective_to ?? t('manufacturing.bom.open_ended') }}</template>
              </VListItemSubtitle>
              <template #append>
                <VChip size="small" label variant="tonal" :color="statusColor(v.status)" class="me-2">
                  {{ t(`manufacturing.status.${v.status}`) }}
                </VChip>
                <VBtn v-if="can.manage && v.status === 'DRAFT'" size="small" color="primary" variant="tonal"
                      @click="approveVersion(v)">
                  {{ t('manufacturing.common.approve') }}
                </VBtn>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.product.no_versions') }}</VCardText>

          <VCardText v-if="can.manage">
            <VDivider class="mb-4" />
            <VRow dense>
              <VCol cols="12" md="3">
                <VTextField v-model="versionForm.version" :error-messages="versionForm.errors.version"
                            :label="t('manufacturing.product.version')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="versionForm.effective_from" type="date"
                            :label="t('manufacturing.bom.effective_from')" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="versionForm.change_reason" :error-messages="versionForm.errors.change_reason"
                            :label="t('manufacturing.product.change_reason')" />
              </VCol>
            </VRow>
            <VBtn color="primary" :loading="versionForm.processing" @click="addVersion">
              {{ t('manufacturing.product.draft_version') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Documents ───────────────────────────────────────────────────── -->
      <VWindowItem value="documents">
        <!-- The files themselves, from Document Management. Renders nothing where the tenant has
             not bought it; the register below still works with external references alone. -->
        <RecordDocuments v-bind="documents_panel" :can-upload="documents_panel.can_upload"
                         :title="t('manufacturing.product.files')" class="mb-4" />

        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('manufacturing.product.register') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.product.register_hint') }}</VCardSubtitle>
          </VCardItem>
          <VList v-if="item.documents.length">
            <VListItem v-for="d in item.documents" :key="d.id">
              <VListItemTitle>{{ d.title }}</VListItemTitle>
              <VListItemSubtitle>
                {{ t(`manufacturing.doc_kind.${d.kind}`) }}
                <template v-if="d.document_uuid"> ·
                  <a :href="route('document-management.documents.show', d.document_uuid)">{{ d.document_title }}</a>
                </template>
                <template v-if="d.external_reference"> · {{ d.external_reference }}</template>
              </VListItemSubtitle>
              <template #append>
                <VBtn v-if="can.manage" icon="tabler-trash" variant="text" size="small"
                      :aria-label="t('manufacturing.common.remove')" @click="removeDocument(d)" />
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.product.no_documents') }}</VCardText>

          <VCardText v-if="can.manage">
            <VDivider class="mb-4" />
            <VRow dense>
              <VCol cols="12" md="3">
                <VSelect v-model="docForm.kind" :error-messages="docForm.errors.kind"
                         :items="document_kinds.map(k => ({ title: t(`manufacturing.doc_kind.${k}`), value: k }))"
                         :label="t('manufacturing.products.kind')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField v-model="docForm.title" :error-messages="docForm.errors.title"
                            :label="t('manufacturing.product.doc_title')" />
              </VCol>
              <VCol v-if="documents_panel.available" cols="12" md="5">
                <VSelect v-model="docForm.document_uuid" clearable item-value="uuid" item-title="title"
                         :items="documents_panel.documents" :placeholder="t('manufacturing.product.no_file')"
                         :label="t('manufacturing.product.linked_file')" />
              </VCol>
              <VCol cols="12" :md="documents_panel.available ? 12 : 5">
                <VTextField v-model="docForm.external_reference"
                            :label="t('manufacturing.product.external_reference')"
                            :hint="t('manufacturing.product.external_reference_hint')" persistent-hint />
              </VCol>
            </VRow>
            <VBtn color="primary" :loading="docForm.processing" @click="addDocument">
              {{ t('manufacturing.product.add_document') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Substitutes ─────────────────────────────────────────────────── -->
      <VWindowItem value="substitutes">
        <VCard border>
          <VList v-if="item.substitutes.length">
            <VListItem v-for="s in item.substitutes" :key="s.id">
              <VListItemTitle>{{ s.code }} — {{ s.name }}</VListItemTitle>
              <VListItemSubtitle>
                {{ t('manufacturing.product.ratio_n', { n: s.ratio }) }}
                <template v-if="s.restriction"> · {{ s.restriction }}</template>
              </VListItemSubtitle>
              <template #append>
                <!-- Awaiting approval and approved-and-usable are different states and must look it. -->
                <VChip size="small" label variant="tonal" :color="s.is_usable ? 'success' : 'warning'" class="me-2">
                  {{ s.is_usable ? t('manufacturing.product.usable') : t('manufacturing.product.awaiting_approval') }}
                </VChip>
                <VBtn v-if="can.approve_substitution && s.requires_approval && !s.approved_at" size="small"
                      color="primary" variant="tonal" class="me-1" @click="approveSubstitute(s)">
                  {{ t('manufacturing.common.approve') }}
                </VBtn>
                <VBtn v-if="can.manage" icon="tabler-trash" variant="text" size="small"
                      :aria-label="t('manufacturing.common.remove')" @click="removeSubstitute(s)" />
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.product.no_substitutes') }}</VCardText>

          <VCardText v-if="can.manage">
            <VDivider class="mb-4" />
            <VRow dense>
              <VCol cols="12" md="5">
                <VAutocomplete v-model="subForm.substitute_stock_item_id" :items="substitute_options"
                               :item-title="(i: any) => `${i.code} — ${i.name}`" item-value="id"
                               :error-messages="subForm.errors.substitute_stock_item_id"
                               :label="t('manufacturing.product.substitute_with')" />
              </VCol>
              <VCol cols="12" md="2">
                <VTextField v-model.number="subForm.ratio" type="number" min="0" step="any"
                            :error-messages="subForm.errors.ratio" :label="t('manufacturing.product.ratio')" />
              </VCol>
              <VCol cols="12" md="5">
                <VTextField v-model="subForm.restriction" :label="t('manufacturing.product.restriction')" />
              </VCol>
            </VRow>
            <VSwitch v-model="subForm.requires_approval" color="primary" hide-details class="mb-2"
                     :label="t('manufacturing.product.requires_approval')" />
            <VBtn color="primary" :loading="subForm.processing" :disabled="!subForm.substitute_stock_item_id"
                  @click="addSubstitute">
              {{ t('manufacturing.product.add_substitute') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Quality ─────────────────────────────────────────────────────── -->
      <VWindowItem value="quality">
        <VCard border>
          <VList v-if="item.quality_requirements.length">
            <VListItem v-for="q in item.quality_requirements" :key="q.id">
              <VListItemTitle>
                <template v-if="q.standard_code">{{ q.standard_code }} — </template>{{ q.standard_name }}
              </VListItemTitle>
              <VListItemSubtitle>
                {{ t(`manufacturing.stage.${q.inspection_stage}`) }}
                <template v-if="q.inspection_plan_reference"> · {{ q.inspection_plan_reference }}</template>
              </VListItemSubtitle>
              <template #append>
                <VChip size="small" label variant="tonal" :color="q.is_mandatory ? 'primary' : 'default'" class="me-2">
                  {{ q.is_mandatory ? t('manufacturing.product.mandatory') : t('manufacturing.product.advisory') }}
                </VChip>
                <VBtn v-if="can.manage" icon="tabler-trash" variant="text" size="small"
                      :aria-label="t('manufacturing.common.remove')" @click="removeQuality(q)" />
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.product.no_quality') }}</VCardText>

          <VCardText v-if="can.manage">
            <VDivider class="mb-4" />
            <VRow dense>
              <VCol cols="12" md="2">
                <VTextField v-model="qualityForm.standard_code" :label="t('manufacturing.product.standard_code')" />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField v-model="qualityForm.standard_name" :error-messages="qualityForm.errors.standard_name"
                            :label="t('manufacturing.product.standard_name')" />
              </VCol>
              <VCol cols="12" md="3">
                <VSelect v-model="qualityForm.inspection_stage"
                         :items="inspection_stages.map(s => ({ title: t(`manufacturing.stage.${s}`), value: s }))"
                         :label="t('manufacturing.product.stage')" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="qualityForm.inspection_plan_reference"
                            :label="t('manufacturing.product.plan_reference')" />
              </VCol>
            </VRow>
            <VSwitch v-model="qualityForm.is_mandatory" color="primary" hide-details class="mb-2"
                     :label="t('manufacturing.product.mandatory')" />
            <VBtn color="primary" :loading="qualityForm.processing" @click="addQuality">
              {{ t('manufacturing.product.add_quality') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
