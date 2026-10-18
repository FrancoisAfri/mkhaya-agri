<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { VueFlow, Position, MarkerType, type Node, type Edge } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

const { t } = useI18n()
const route = ziggyRoute

interface GraphNode {
  key: string; kind: 'entity' | 'person'; id: number; label: string; sub: string | null
  is_root: boolean; is_supplier: boolean; supplier_ref_id?: number | null
}
interface GraphEdge {
  id: number; holder_key: string; holder: string; holder_kind: string
  subject_key: string; subject: string; percentage: number | null
  ownership_type: string; as_at: string | null; source: string
}
interface Ubo {
  person_id: number; name: string; role: string | null; nationality: string | null
  has_identifier: boolean; effective_percentage: number; paths: number; direct: boolean
}

const props = defineProps<{
  /** The supplier's uuid — suppliers are addressed by it, never by the row id. */
  supplierUuid: string
  ownership: {
    root: GraphNode
    nodes: GraphNode[]
    edges: GraphEdge[]
    ubos: Ubo[]
    unallocated: { entity_id: number; entity: string; declared: number; missing: number; stakes_without_a_percentage: number; over_allocated: boolean }[]
    cycles: string[]
    dead_ends: string[]
    declared_total: number | null
  }
  relatedSuppliers: { supplier_id: number; supplier: string; via: { kind: string; name: string; role: string }[] }[]
  people: { id: number; name: string; role: string }[]
}>()

// ── The drawing ──────────────────────────────────────────────────────────────
// Laid out in tiers: the supplier at the bottom, its holders above it, theirs above those. Ownership
// reads upward, which is how the structure is described in every filing it will be checked against.
const tiers = computed(() => {
  const depth = new Map<string, number>()
  depth.set(props.ownership.root.key, 0)

  // Breadth-first up the holder edges; repeated until stable so a diamond settles at its deepest tier.
  for (let pass = 0; pass < 12; pass++) {
    let changed = false
    for (const e of props.ownership.edges) {
      const subjectDepth = depth.get(e.subject_key)
      if (subjectDepth === undefined) continue
      const next = subjectDepth + 1
      if ((depth.get(e.holder_key) ?? -1) < next) {
        depth.set(e.holder_key, next)
        changed = true
      }
    }
    if (!changed) break
  }
  return depth
})

const flowNodes = computed<Node[]>(() => {
  const perTier = new Map<number, number>()

  return props.ownership.nodes.map((n) => {
    const tier = tiers.value.get(n.key) ?? 1
    const index = perTier.get(tier) ?? 0
    perTier.set(tier, index + 1)

    return {
      id: n.key,
      position: { x: index * 230, y: -tier * 130 },
      sourcePosition: Position.Bottom,
      targetPosition: Position.Top,
      data: { node: n },
      type: 'default',
      label: n.label,
      class: n.is_root ? 'dd-node dd-node--root' : (n.kind === 'person' ? 'dd-node dd-node--person' : 'dd-node'),
      style: { width: '200px' },
    }
  })
})

const flowEdges = computed<Edge[]>(() => props.ownership.edges.map(e => ({
  id: `e${e.id}`,
  source: e.holder_key,
  target: e.subject_key,
  label: e.percentage === null ? t('supplier_management.ownership.unstated') : `${e.percentage}%`,
  markerEnd: MarkerType.ArrowClosed,
  animated: false,
  style: { strokeWidth: e.percentage !== null && e.percentage >= 50 ? 2.5 : 1.5 },
})))

const showGraph = ref(true)
const hasFindings = computed(() =>
  props.ownership.unallocated.length > 0 || props.ownership.cycles.length > 0 || props.ownership.dead_ends.length > 0)

// ── Capture a stake ──────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm<any>({
  holder_kind: 'person', holder_id: null as number | null, holder_name: '',
  holder_registration_number: '', subject_entity_id: null as number | null,
  percentage: null as number | null, ownership_type: 'shares', as_at: '', notes: '',
})

const entityOptions = computed(() => props.ownership.nodes.filter(n => n.kind === 'entity'))

function openAdd() {
  form.reset(); form.clearErrors()
  form.subject_entity_id = props.ownership.root.id
  dialog.value = true
}
function save() {
  form.post(route('suppliers.ownership.store', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

// ── Compare against a registry ───────────────────────────────────────────────
const compareDialog = ref(false)
const registryOwners = ref<{ name: string; percentage: number | null }[]>([{ name: '', percentage: null }])
const compareForm = useForm<any>({ owners: [], source: 'CIPC beneficial ownership', reference: '' })

function submitCompare() {
  compareForm.owners = registryOwners.value.filter(o => o.name.trim() !== '')
  compareForm.post(route('suppliers.ownership.compare', props.supplierUuid), {
    preserveScroll: true,
    onSuccess: () => { compareDialog.value = false; registryOwners.value = [{ name: '', percentage: null }] },
  })
}
</script>

<template>
  <VCard border>
    <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
      <span class="text-subtitle-1">{{ t('supplier_management.ownership.title') }}</span>
      <div class="d-flex gap-2">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="openAdd">
          {{ t('supplier_management.ownership.add') }}
        </VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-git-compare" @click="compareDialog = true">
          {{ t('supplier_management.ownership.compare') }}
        </VBtn>
      </div>
    </VCardTitle>

    <VCardText>
      <!-- Findings before the picture: the gaps are the reason to look -->
      <VAlert
        v-for="(gap, i) in ownership.unallocated" :key="`gap${i}`"
        :type="gap.over_allocated ? 'error' : 'warning'" variant="tonal" density="compact" class="mb-2"
        prepend-icon="tabler-chart-pie-off"
      >
        <span v-if="gap.over_allocated">
          {{ t('supplier_management.ownership.over_allocated', { entity: gap.entity, declared: gap.declared }) }}
        </span>
        <span v-else-if="gap.stakes_without_a_percentage">
          {{ t('supplier_management.ownership.unstated_stakes', { entity: gap.entity, n: gap.stakes_without_a_percentage }) }}
        </span>
        <span v-else>
          {{ t('supplier_management.ownership.unallocated', { entity: gap.entity, missing: gap.missing }) }}
        </span>
      </VAlert>

      <VAlert v-for="(cycle, i) in ownership.cycles" :key="`cyc${i}`" type="error" variant="tonal" density="compact" class="mb-2" prepend-icon="tabler-rotate-clockwise">
        {{ cycle }}
      </VAlert>

      <VAlert v-for="(dead, i) in ownership.dead_ends" :key="`dead${i}`" type="warning" variant="tonal" density="compact" class="mb-2" prepend-icon="tabler-help-circle">
        {{ dead }}
      </VAlert>

      <!-- Ultimate beneficial owners: the answer the whole exercise is for -->
      <div class="text-subtitle-2 mb-2 mt-4">{{ t('supplier_management.ownership.ubos') }}</div>
      <div v-if="!ownership.ubos.length" class="text-caption text-medium-emphasis mb-3">
        {{ t('supplier_management.ownership.no_ubos') }}
      </div>
      <div v-else style="overflow-x: auto" class="mb-3">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('supplier_management.ownership.person') }}</th>
              <th class="text-right">{{ t('supplier_management.ownership.effective') }}</th>
              <th>{{ t('supplier_management.ownership.held') }}</th>
              <th>{{ t('supplier_management.ownership.screenable') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in ownership.ubos" :key="u.person_id">
              <td class="font-weight-medium">{{ u.name }}</td>
              <td class="text-right" style="font-variant-numeric: tabular-nums">{{ u.effective_percentage }}%</td>
              <td class="text-caption">
                <span v-if="u.direct && u.paths === 1">{{ t('supplier_management.ownership.directly') }}</span>
                <span v-else>{{ t('supplier_management.ownership.through_paths', { n: u.paths }) }}</span>
              </td>
              <td>
                <VChip v-if="u.has_identifier" size="x-small" variant="tonal" color="success" prepend-icon="tabler-id">
                  {{ t('supplier_management.ownership.identified') }}
                </VChip>
                <VChip v-else size="x-small" variant="tonal" color="warning" prepend-icon="tabler-id-off">
                  {{ t('supplier_management.ownership.name_only') }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <!-- The structure. The table below is the accessible primary; the drawing is the fast read,
           and it can be switched off entirely. -->
      <div class="d-flex align-center justify-space-between mt-4 mb-2">
        <div class="text-subtitle-2">{{ t('supplier_management.ownership.structure') }}</div>
        <VBtn size="x-small" variant="text" :prepend-icon="showGraph ? 'tabler-eye-off' : 'tabler-eye'" @click="showGraph = !showGraph">
          {{ showGraph ? t('supplier_management.ownership.hide_diagram') : t('supplier_management.ownership.show_diagram') }}
        </VBtn>
      </div>

      <div v-if="showGraph && ownership.edges.length" class="dd-flow mb-3" role="img" :aria-label="t('supplier_management.ownership.diagram_alt')">
        <VueFlow
          :nodes="flowNodes" :edges="flowEdges" :fit-view-on-init="true"
          :nodes-draggable="false" :nodes-connectable="false" :elements-selectable="false"
          :zoom-on-scroll="false" :pan-on-scroll="true" style="height: 320px"
        />
      </div>

      <!-- Adjacency table: keyboard-navigable, screen-reader-legible, and the only version that
           works when the diagram cannot be seen at all. -->
      <div style="overflow-x: auto">
        <VTable density="compact">
          <caption class="text-caption text-medium-emphasis text-start pb-2">
            {{ t('supplier_management.ownership.table_caption') }}
          </caption>
          <thead>
            <tr>
              <th>{{ t('supplier_management.ownership.holder') }}</th>
              <th>{{ t('supplier_management.ownership.owns') }}</th>
              <th class="text-right">{{ t('supplier_management.ownership.stake') }}</th>
              <th>{{ t('supplier_management.ownership.type') }}</th>
              <th>{{ t('supplier_management.ownership.as_at') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!ownership.edges.length">
              <td colspan="5" class="text-caption text-medium-emphasis">
                {{ t('supplier_management.ownership.empty') }}
              </td>
            </tr>
            <tr v-for="e in ownership.edges" :key="e.id">
              <td>
                <VIcon :icon="e.holder_kind === 'person' ? 'tabler-user' : 'tabler-building'" size="14" class="me-1" />
                {{ e.holder }}
              </td>
              <td>{{ e.subject }}</td>
              <td class="text-right" style="font-variant-numeric: tabular-nums">
                <span v-if="e.percentage !== null">{{ e.percentage }}%</span>
                <span v-else class="text-warning">{{ t('supplier_management.ownership.unstated') }}</span>
              </td>
              <td class="text-caption text-capitalize">{{ e.ownership_type }}</td>
              <td class="text-caption text-medium-emphasis">{{ e.as_at || '—' }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <!-- Suppliers connected through a shared person or parent -->
      <template v-if="relatedSuppliers.length">
        <VDivider class="my-4" />
        <div class="text-subtitle-2 mb-1">{{ t('supplier_management.ownership.related') }}</div>
        <div class="text-caption text-medium-emphasis mb-2" style="max-width: 68ch">
          {{ t('supplier_management.ownership.related_help') }}
        </div>
        <VList density="compact" class="py-0">
          <VListItem v-for="r in relatedSuppliers" :key="r.supplier_id" :href="`/suppliers/${r.supplier_id}`">
            <template #prepend><VIcon icon="tabler-affiliate" size="18" /></template>
            <VListItemTitle>{{ r.supplier }}</VListItemTitle>
            <VListItemSubtitle>
              {{ r.via.map(v => v.name).join(', ') }}
            </VListItemSubtitle>
          </VListItem>
        </VList>
      </template>
    </VCardText>

    <!-- Add a stake -->
    <VDialog v-model="dialog" max-width="560" scrollable>
      <VCard>
        <VCardTitle>{{ t('supplier_management.ownership.add_title') }}</VCardTitle>
        <VCardText>
          <VBtnToggle v-model="form.holder_kind" mandatory density="comfortable" variant="outlined" divided class="mb-4">
            <VBtn value="person">{{ t('supplier_management.ownership.a_person') }}</VBtn>
            <VBtn value="entity">{{ t('supplier_management.ownership.a_company') }}</VBtn>
          </VBtnToggle>

          <VSelect
            v-if="form.holder_kind === 'person' && people.length" v-model="form.holder_id" clearable
            :items="people.map(p => ({ title: `${p.name} (${p.role})`, value: p.id }))"
            :label="t('supplier_management.ownership.existing_person')"
            density="comfortable" variant="outlined" class="mb-3"
          />
          <VSelect
            v-if="form.holder_kind === 'entity' && entityOptions.length" v-model="form.holder_id" clearable
            :items="entityOptions.map(e => ({ title: e.label, value: e.id }))"
            :label="t('supplier_management.ownership.existing_company')"
            density="comfortable" variant="outlined" class="mb-3"
          />

          <VTextField
            v-if="!form.holder_id" v-model="form.holder_name" density="comfortable" variant="outlined" class="mb-3"
            :label="t('supplier_management.ownership.new_holder')" :error-messages="form.errors.holder_name"
          />
          <VTextField
            v-if="!form.holder_id && form.holder_kind === 'entity'" v-model="form.holder_registration_number"
            density="comfortable" variant="outlined" class="mb-3"
            :label="t('supplier_management.ownership.registration_number')"
          />

          <VSelect
            v-model="form.subject_entity_id" :items="entityOptions.map(e => ({ title: e.label, value: e.id }))"
            :label="t('supplier_management.ownership.owns_what')" :hint="t('supplier_management.ownership.owns_what_help')"
            persistent-hint density="comfortable" variant="outlined" class="mb-3"
          />

          <div class="d-flex gap-3">
            <VTextField
              v-model.number="form.percentage" type="number" min="0" max="100" step="0.01"
              density="comfortable" variant="outlined" :label="t('supplier_management.ownership.stake')"
              :error-messages="form.errors.percentage"
            />
            <VSelect
              v-model="form.ownership_type" :items="['shares', 'member', 'beneficial', 'control', 'trustee']"
              density="comfortable" variant="outlined" :label="t('supplier_management.ownership.type')"
            />
          </div>
          <VTextField
            v-model="form.as_at" type="date" density="comfortable" variant="outlined"
            :label="t('supplier_management.ownership.as_at')" :hint="t('supplier_management.ownership.as_at_help')" persistent-hint
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('common.save', 'Save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Compare against a registry filing -->
    <VDialog v-model="compareDialog" max-width="600" scrollable>
      <VCard>
        <VCardTitle>{{ t('supplier_management.ownership.compare_title') }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('supplier_management.ownership.compare_help') }}
          </VAlert>
          <div v-for="(owner, i) in registryOwners" :key="i" class="d-flex gap-2 mb-2">
            <VTextField v-model="owner.name" :label="t('supplier_management.ownership.owner_name')" density="compact" variant="outlined" hide-details />
            <VTextField v-model.number="owner.percentage" type="number" min="0" max="100" :label="t('supplier_management.ownership.stake')" density="compact" variant="outlined" hide-details style="max-width: 130px" />
            <VBtn icon="tabler-plus" size="small" variant="text"
                    :aria-label="t('supplier_management.ownership.add_owner_row')"
                    @click="registryOwners.push({ name: '', percentage: null })" />
          </div>
          <VTextField v-model="compareForm.source" class="mt-3" density="comfortable" variant="outlined" :label="t('supplier_management.ownership.source')" />
          <VTextField v-model="compareForm.reference" density="comfortable" variant="outlined" :label="t('supplier_management.ownership.reference')" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="compareDialog = false">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="compareForm.processing" @click="submitCompare">
            {{ t('supplier_management.ownership.run_comparison') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>

<style scoped>
.dd-flow {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  overflow: hidden;
}
/* Node styling goes through Vuetify's theme variables so the diagram follows light and dark with
   the rest of the page rather than staying on Vue Flow's own light default. */
:deep(.dd-node) {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  font-size: 12px;
  padding: 8px 10px;
}
:deep(.dd-node--root) {
  border-color: rgb(var(--v-theme-primary));
  border-width: 2px;
  font-weight: 600;
}
:deep(.dd-node--person) {
  border-style: dashed;
}
:deep(.vue-flow__edge-text) {
  font-size: 11px;
  fill: rgb(var(--v-theme-on-surface));
}
</style>
