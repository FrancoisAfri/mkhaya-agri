<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Alternate = { id: string; stock_item_id: number; ratio: number; priority: number; is_usable: boolean }
type Line = {
  id: string; line_no: number; component_stock_item_id: number; component_code: string | null
  component_name: string | null; quantity_per: number; gross_quantity_per: number | null
  uom: string | null; uom_id: number | null; scrap_percent: number; is_phantom: boolean
  line_kind: string; alternate_group: string | null; is_optional: boolean; notes: string | null
  alternates: Alternate[]
}
type History = { id: string; action: string; actor_id: number | null; acted_at: string | null; comment: string | null }

const props = defineProps<{
  bom: {
    id: string; revision: number; name: string | null; status: string; is_default: boolean
    product_code: string | null; product_name: string | null; product_id: string | null
    yield_quantity: number; yield_uom: string | null; batch_size: number | null
    process_loss_percent: number; effective_from: string | null; effective_to: string | null
    is_editable: boolean; notes: string | null; approved_at: string | null
    superseded_by: { id: string; revision: number } | null
    lines: Line[]; history: History[]
  }
  siblings: Array<{ id: string; revision: number; status: string }>
  components: Array<{ id: number; code: string; name: string; uom: string | null }>
  units: Array<{ id: number; code: string; name: string }>
  line_kinds: string[]
  can: { edit: boolean; approve: boolean; revise: boolean; obsolete: boolean; delete: boolean }
  documents_panel: { available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number; documents: Array<Record<string, any>> }
}>()

const confirmer = ref()
const statusColor = (s: string) =>
  ({ APPROVED: 'success', DRAFT: 'warning', SUPERSEDED: 'grey', OBSOLETE: 'error' } as Record<string, string>)[s] ?? 'default'

const unitItems = computed(() => props.units.map(u => ({ title: `${u.code} — ${u.name}`, value: u.id })))
const compareWith = ref<string | null>(null)

// ── Header (draft only) ─────────────────────────────────────────────────────
const header = useForm({
  name: props.bom.name ?? '',
  yield_quantity: props.bom.yield_quantity,
  batch_size: props.bom.batch_size,
  process_loss_percent: props.bom.process_loss_percent,
  effective_from: props.bom.effective_from ?? '',
  notes: props.bom.notes ?? '',
})

function saveHeader() {
  header.patch(route('manufacturing.engineering.boms.update', props.bom.id), { preserveScroll: true })
}

// ── Lines ───────────────────────────────────────────────────────────────────
const lineDialog = ref(false)
const lineForm = useForm({
  component_stock_item_id: null as number | null, quantity_per: 1, uom_id: null as number | null,
  scrap_percent: 0, is_phantom: false, line_kind: 'COMPONENT', alternate_group: '', is_optional: false,
})

function addLine() {
  lineForm.post(route('manufacturing.engineering.boms.lines.store', props.bom.id), {
    preserveScroll: true, onSuccess: () => { lineDialog.value = false; lineForm.reset() },
  })
}

const editing = ref<Line | null>(null)
const editForm = useForm({ quantity_per: 0, scrap_percent: 0, is_phantom: false, is_optional: false })

function openEdit(l: Line) {
  editing.value = l
  editForm.quantity_per = l.quantity_per
  editForm.scrap_percent = l.scrap_percent
  editForm.is_phantom = l.is_phantom
  editForm.is_optional = l.is_optional
}

function saveEdit() {
  if (!editing.value) return
  editForm.patch(route('manufacturing.engineering.boms.lines.update', [props.bom.id, editing.value.id]), {
    preserveScroll: true, onSuccess: () => { editing.value = null },
  })
}

function removeLine(l: Line) {
  confirmer.value.open({
    title: t('manufacturing.bom.remove_line'),
    message: t('manufacturing.product.remove_named', { name: l.component_code ?? `#${l.component_stock_item_id}` }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.boms.lines.destroy', [props.bom.id, l.id]),
    { preserveScroll: true }))
}

// Alternatives
const altFor = ref<Line | null>(null)
const altForm = useForm({ stock_item_id: null as number | null, ratio: 1, priority: 1, requires_approval: true })

function saveAlt() {
  if (!altFor.value) return
  altForm.post(route('manufacturing.engineering.boms.lines.alternates.store', [props.bom.id, altFor.value.id]), {
    preserveScroll: true, onSuccess: () => { altFor.value = null; altForm.reset() },
  })
}

const componentLabel = (id: number) => {
  const c = props.components.find(x => x.id === id)
  return c ? `${c.code}` : `#${id}`
}

// ── Lifecycle ───────────────────────────────────────────────────────────────
const reasonDialog = ref<null | 'reject' | 'revise' | 'obsolete'>(null)
const reasonForm = useForm({ reason: '' })

function lifecycle(action: 'submit' | 'approve') {
  router.post(route(`manufacturing.engineering.boms.${action}`, props.bom.id), {}, { preserveScroll: true })
}

function sendReason() {
  if (!reasonDialog.value) return
  reasonForm.post(route(`manufacturing.engineering.boms.${reasonDialog.value}`, props.bom.id), {
    preserveScroll: true,
    onSuccess: () => { reasonDialog.value = null; reasonForm.reset() },
  })
}

function discard() {
  confirmer.value.open({
    title: t('manufacturing.bom.discard'),
    message: t('manufacturing.bom.discard_body'),
    confirmText: t('manufacturing.bom.discard'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.engineering.boms.destroy', props.bom.id)))
}

function compare() {
  if (compareWith.value) {
    router.visit(route('manufacturing.engineering.boms.compare', [props.bom.id, compareWith.value]))
  }
}

const reasonTitle = computed(() => reasonDialog.value ? t(`manufacturing.bom.reason_${reasonDialog.value}`) : '')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
              :href="route('manufacturing.engineering.boms.index')">
          {{ t('manufacturing.bom.back') }}
        </VBtn>
        <h4 class="text-h4">
          {{ bom.product_code }} — {{ t('manufacturing.bom.revision_n', { n: bom.revision }) }}
        </h4>
        <div class="text-body-2 text-medium-emphasis">{{ bom.product_name }}<template v-if="bom.name"> · {{ bom.name }}</template></div>
        <div class="d-flex gap-2 mt-2">
          <VChip size="small" label variant="tonal" :color="statusColor(bom.status)">
            {{ t(`manufacturing.status.${bom.status}`) }}
          </VChip>
          <VChip v-if="bom.is_default" size="small" label color="primary">{{ t('manufacturing.bom.default') }}</VChip>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.edit && bom.status === 'DRAFT'" variant="tonal" prepend-icon="tabler-send"
              @click="lifecycle('submit')">{{ t('manufacturing.bom.submit') }}</VBtn>
        <VBtn v-if="can.approve" color="success" prepend-icon="tabler-check"
              @click="lifecycle('approve')">{{ t('manufacturing.common.approve') }}</VBtn>
        <VBtn v-if="can.approve" variant="tonal" color="error" prepend-icon="tabler-x"
              @click="reasonDialog = 'reject'">{{ t('manufacturing.bom.reject') }}</VBtn>
        <VBtn v-if="can.revise" color="primary" prepend-icon="tabler-copy"
              @click="reasonDialog = 'revise'">{{ t('manufacturing.bom.revise') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-calculator"
              :href="route('manufacturing.engineering.boms.explode', bom.id)">
          {{ t('manufacturing.bom.explode') }}
        </VBtn>
        <VBtn v-if="can.obsolete" variant="text" color="error" @click="reasonDialog = 'obsolete'">
          {{ t('manufacturing.bom.obsolete') }}
        </VBtn>
        <VBtn v-if="can.delete" variant="text" color="error" @click="discard">{{ t('manufacturing.bom.discard') }}</VBtn>
      </div>
    </div>

    <!-- The immutability rule, made visible. An approved revision offers Revise rather than Edit,
         and says why, instead of letting somebody discover it as a failed save. -->
    <VAlert v-if="bom.status === 'APPROVED'" type="info" variant="tonal" class="mb-4">
      {{ t('manufacturing.bom.locked_approved') }}
    </VAlert>
    <VAlert v-else-if="bom.status === 'SUPERSEDED'" type="warning" variant="tonal" class="mb-4">
      {{ t('manufacturing.bom.locked_superseded') }}
      <a v-if="bom.superseded_by" :href="route('manufacturing.engineering.boms.show', bom.superseded_by.id)">
        {{ t('manufacturing.bom.revision_n', { n: bom.superseded_by.revision }) }}
      </a>
    </VAlert>
    <VAlert v-else-if="bom.status === 'OBSOLETE'" type="error" variant="tonal" class="mb-4">
      {{ t('manufacturing.bom.locked_obsolete') }}
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.bom.components') }}</VCardTitle>
            <template #append>
              <VBtn v-if="can.edit" size="small" color="primary" prepend-icon="tabler-plus"
                    @click="lineDialog = true">
                {{ t('manufacturing.bom.add_component') }}
              </VBtn>
            </template>
          </VCardItem>

          <VTable v-if="bom.lines.length" density="comfortable">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('manufacturing.bom.component') }}</th>
                <th class="text-end">{{ t('manufacturing.bom.net_qty') }}</th>
                <th class="text-end">{{ t('manufacturing.bom.scrap') }}</th>
                <th class="text-end">{{ t('manufacturing.bom.gross_qty') }}</th>
                <th>{{ t('manufacturing.bom.kind') }}</th>
                <th v-if="can.edit"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="l in bom.lines" :key="l.id">
                <tr>
                  <td>{{ l.line_no }}</td>
                  <td>
                    <div>{{ l.component_code }}</div>
                    <div class="text-caption text-medium-emphasis">{{ l.component_name }}</div>
                    <div class="d-flex gap-1 mt-1">
                      <VChip v-if="l.is_phantom" size="x-small" label color="deep-purple" variant="tonal">
                        {{ t('manufacturing.bom.phantom') }}
                      </VChip>
                      <VChip v-if="l.is_optional" size="x-small" label variant="tonal">
                        {{ t('manufacturing.bom.optional') }}
                      </VChip>
                      <VChip v-if="l.alternate_group" size="x-small" label variant="tonal">
                        {{ t('manufacturing.bom.group_n', { n: l.alternate_group }) }}
                      </VChip>
                    </div>
                  </td>
                  <td class="text-end">{{ l.quantity_per }} {{ l.uom }}</td>
                  <td class="text-end">{{ l.scrap_percent }}%</td>
                  <!-- The gross figure is what gets issued; the difference from net is the point. -->
                  <td class="text-end font-weight-medium">
                    <template v-if="l.gross_quantity_per !== null">{{ l.gross_quantity_per }} {{ l.uom }}</template>
                    <VChip v-else size="x-small" color="error" label>{{ t('manufacturing.bom.invalid_scrap') }}</VChip>
                  </td>
                  <td>{{ t(`manufacturing.line_kind.${l.line_kind}`) }}</td>
                  <td v-if="can.edit" class="text-end text-no-wrap">
                    <VBtn icon="tabler-switch-horizontal" variant="text" size="small"
                          :aria-label="t('manufacturing.bom.add_alternate')" @click="altFor = l" />
                    <VBtn icon="tabler-pencil" variant="text" size="small"
                          :aria-label="t('manufacturing.common.edit')" @click="openEdit(l)" />
                    <VBtn icon="tabler-trash" variant="text" size="small"
                          :aria-label="t('manufacturing.common.remove')" @click="removeLine(l)" />
                  </td>
                </tr>
                <tr v-if="l.alternates.length">
                  <td></td>
                  <td :colspan="can.edit ? 6 : 5" class="text-caption text-medium-emphasis pt-0">
                    {{ t('manufacturing.bom.alternates') }}:
                    <span v-for="a in l.alternates" :key="a.id" class="me-3">
                      {{ componentLabel(a.stock_item_id) }} ×{{ a.ratio }}
                      <VChip v-if="!a.is_usable" size="x-small" color="warning" label class="ms-1">
                        {{ t('manufacturing.product.awaiting_approval') }}
                      </VChip>
                    </span>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.bom.no_components') }}</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.bom.recipe') }}</VCardTitle></VCardItem>
          <VCardText>
            <template v-if="can.edit">
              <VTextField v-model="header.name" density="compact" class="mb-2" :label="t('manufacturing.bom.name')" />
              <VTextField v-model.number="header.yield_quantity" type="number" min="0" step="any"
                          density="compact" class="mb-2" :error-messages="header.errors.yield_quantity"
                          :label="`${t('manufacturing.bom.yield')} (${bom.yield_uom ?? ''})`" />
              <VTextField v-model.number="header.batch_size" type="number" min="0" step="any"
                          density="compact" class="mb-2" :label="t('manufacturing.bom.batch_size')" />
              <VTextField v-model.number="header.process_loss_percent" type="number" min="0" max="99.99" step="any"
                          density="compact" class="mb-2" suffix="%" :label="t('manufacturing.bom.process_loss')" />
              <VTextField v-model="header.effective_from" type="date" density="compact" class="mb-2"
                          :label="t('manufacturing.bom.effective_from')" />
              <VBtn block color="primary" :loading="header.processing" @click="saveHeader">
                {{ t('manufacturing.common.save') }}
              </VBtn>
            </template>
            <VList v-else density="compact" class="pa-0">
              <VListItem><template #title>{{ t('manufacturing.bom.yield') }}</template>
                <template #append>{{ bom.yield_quantity }} {{ bom.yield_uom }}</template></VListItem>
              <VListItem><template #title>{{ t('manufacturing.bom.batch_size') }}</template>
                <template #append>{{ bom.batch_size ?? '—' }}</template></VListItem>
              <VListItem><template #title>{{ t('manufacturing.bom.process_loss') }}</template>
                <template #append>{{ bom.process_loss_percent }}%</template></VListItem>
              <VListItem><template #title>{{ t('manufacturing.bom.effective') }}</template>
                <template #append>{{ bom.effective_from ?? '—' }} → {{ bom.effective_to ?? t('manufacturing.bom.open_ended') }}</template></VListItem>
            </VList>
          </VCardText>
        </VCard>

        <VCard v-if="siblings.length" border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.bom.compare') }}</VCardTitle></VCardItem>
          <VCardText class="d-flex gap-2">
            <VSelect v-model="compareWith" density="compact" hide-details
                     :items="siblings.map(s => ({ title: `${t('manufacturing.bom.revision_n', { n: s.revision })} · ${t(`manufacturing.status.${s.status}`)}`, value: s.id }))"
                     :label="t('manufacturing.bom.compare_with')" />
            <VBtn :disabled="!compareWith" @click="compare">{{ t('manufacturing.bom.compare') }}</VBtn>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.bom.history') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="h in bom.history" :key="h.id">
              <VListItemTitle>{{ t(`manufacturing.bom_action.${h.action}`) }}</VListItemTitle>
              <VListItemSubtitle>
                {{ h.acted_at ? new Date(h.acted_at).toLocaleString() : '' }}
                <template v-if="h.comment"> · {{ h.comment }}</template>
              </VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!bom.history.length">
              <VListItemTitle class="text-medium-emphasis">{{ t('manufacturing.bom.no_history') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Add component -->
    <VDialog v-model="lineDialog" max-width="560">
      <VCard :title="t('manufacturing.bom.add_component')">
        <VCardText>
          <VAutocomplete v-model="lineForm.component_stock_item_id" :items="components" item-value="id"
                         :item-title="(i: any) => `${i.code} — ${i.name}`"
                         :error-messages="lineForm.errors.component_stock_item_id"
                         :label="t('manufacturing.bom.component')" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model.number="lineForm.quantity_per" type="number" min="0" step="any"
                          :error-messages="lineForm.errors.quantity_per" :label="t('manufacturing.bom.net_qty')" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="lineForm.uom_id" clearable :items="unitItems" :label="t('manufacturing.products.uom')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="lineForm.scrap_percent" type="number" min="0" max="99.99" step="any"
                          suffix="%" :error-messages="lineForm.errors.scrap_percent"
                          :label="t('manufacturing.bom.scrap')" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="lineForm.line_kind"
                       :items="line_kinds.map(k => ({ title: t(`manufacturing.line_kind.${k}`), value: k }))"
                       :label="t('manufacturing.bom.kind')" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="lineForm.alternate_group" :label="t('manufacturing.bom.alternate_group')"
                          :hint="t('manufacturing.bom.alternate_group_hint')" persistent-hint />
            </VCol>
          </VRow>
          <div class="d-flex gap-6 mt-3">
            <VSwitch v-model="lineForm.is_phantom" color="primary" hide-details :label="t('manufacturing.bom.phantom')" />
            <VSwitch v-model="lineForm.is_optional" color="primary" hide-details :label="t('manufacturing.bom.optional')" />
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="lineDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="lineForm.processing" :disabled="!lineForm.component_stock_item_id"
                @click="addLine">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit component -->
    <VDialog :model-value="!!editing" max-width="480" @update:model-value="editing = null">
      <VCard :title="editing?.component_code ?? ''">
        <VCardText>
          <VTextField v-model.number="editForm.quantity_per" type="number" min="0" step="any" class="mb-3"
                      :error-messages="editForm.errors.quantity_per" :label="t('manufacturing.bom.net_qty')" />
          <VTextField v-model.number="editForm.scrap_percent" type="number" min="0" max="99.99" step="any"
                      suffix="%" class="mb-3" :error-messages="editForm.errors.scrap_percent"
                      :label="t('manufacturing.bom.scrap')" />
          <div class="d-flex gap-6">
            <VSwitch v-model="editForm.is_phantom" color="primary" hide-details :label="t('manufacturing.bom.phantom')" />
            <VSwitch v-model="editForm.is_optional" color="primary" hide-details :label="t('manufacturing.bom.optional')" />
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editForm.processing" @click="saveEdit">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add alternative -->
    <VDialog :model-value="!!altFor" max-width="480" @update:model-value="altFor = null">
      <VCard :title="t('manufacturing.bom.add_alternate')">
        <VCardText>
          <VAutocomplete v-model="altForm.stock_item_id" :items="components" item-value="id"
                         :item-title="(i: any) => `${i.code} — ${i.name}`"
                         :error-messages="altForm.errors.stock_item_id"
                         :label="t('manufacturing.bom.component')" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model.number="altForm.ratio" type="number" min="0" step="any"
                          :error-messages="altForm.errors.ratio" :label="t('manufacturing.product.ratio')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="altForm.priority" type="number" min="1" :label="t('manufacturing.bom.priority')" />
            </VCol>
          </VRow>
          <VSwitch v-model="altForm.requires_approval" color="primary" hide-details
                   :label="t('manufacturing.product.requires_approval')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="altFor = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="altForm.processing" :disabled="!altForm.stock_item_id"
                @click="saveAlt">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject / revise / obsolete: each wants a reason on the record. -->
    <VDialog :model-value="!!reasonDialog" max-width="480" @update:model-value="reasonDialog = null">
      <VCard :title="reasonTitle">
        <VCardText>
          <VTextarea v-model="reasonForm.reason" rows="3" auto-grow :error-messages="reasonForm.errors.reason"
                     :label="t('manufacturing.bom.reason')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="reasonDialog = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="reasonForm.processing"
                :disabled="reasonDialog === 'reject' && !reasonForm.reason" @click="sendReason">
            {{ t('manufacturing.common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
    <RecordDocuments v-if="documents_panel.available" v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.documents.title')" class="mt-4" />
  </div>
</template>
