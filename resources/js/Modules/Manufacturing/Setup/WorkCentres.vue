<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Workstation = { id: string; code: string; name: string; is_active: boolean }
type Machine = { id: number; name: string; asset_tag: string | null; is_primary: boolean }
type Centre = {
  id: string; code: string; name: string; factory: string | null; factory_id: string | null
  production_line: string | null; production_line_id: number | null
  department: string | null; department_id: number | null
  capacity_per_hour: number | null; capacity_uom_id: number | null; cost_per_hour: number | null
  setup_minutes: number; concurrent_jobs: number; is_rated: boolean; is_active: boolean
  workstations: Workstation[]; machines: Machine[]
}

const props = defineProps<{
  work_centres: Centre[]
  factories: Array<{ id: string; code: string; name: string }>
  production_lines: Array<{ id: number; factory_id: string | null; code: string; name: string }>
  departments: Array<{ id: number; factory_id: string | null; code: string; name: string }>
  units: Array<{ id: number; code: string; name: string; dimension: string }>
  assets: Array<{ id: number; name: string; asset_tag: string | null }>
}>()

const unratedCount = computed(() => props.work_centres.filter(c => c.is_active && !c.is_rated).length)

// ── Create / edit ───────────────────────────────────────────────────────────
const dialog = ref(false)
const editingId = ref<string | null>(null)
const form = useForm({
  factory_uuid: null as string | null, code: '', name: '',
  production_line_id: null as number | null, department_id: null as number | null,
  capacity_per_hour: null as number | null, capacity_uom_id: null as number | null,
  cost_per_hour: null as number | null, setup_minutes: 0, concurrent_jobs: 1, is_active: true,
})

const linesForFactory = computed(() =>
  props.production_lines.filter(l => !form.factory_uuid || l.factory_id === form.factory_uuid))
const departmentsForFactory = computed(() =>
  props.departments.filter(d => !form.factory_uuid || d.factory_id === form.factory_uuid))
// Capacity is a rate, so it is quantity per hour: offer count, mass and volume, not "minutes".
const rateUnits = computed(() => props.units.filter(u => u.dimension !== 'TIME'))

function openCreate() {
  editingId.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(c: Centre) {
  editingId.value = c.id
  form.factory_uuid = c.factory_id
  form.code = c.code
  form.name = c.name
  form.production_line_id = c.production_line_id
  form.department_id = c.department_id
  form.capacity_per_hour = c.capacity_per_hour
  form.capacity_uom_id = c.capacity_uom_id
  form.cost_per_hour = c.cost_per_hour
  form.setup_minutes = c.setup_minutes
  form.concurrent_jobs = c.concurrent_jobs
  form.is_active = c.is_active
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editingId.value) {
    form.patch(route('manufacturing.setup.work-centres.update', editingId.value), done)
  } else {
    form.post(route('manufacturing.setup.work-centres.store'), done)
  }
}

function remove(c: Centre) {
  confirmer.value.open({
    title: t('manufacturing.workcentres.remove'),
    message: t('manufacturing.product.remove_named', { name: c.name }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.setup.work-centres.destroy', c.id), { preserveScroll: true }))
}

// ── Workstations ────────────────────────────────────────────────────────────
const stationFor = ref<Centre | null>(null)
const stationForm = useForm({ code: '', name: '' })

function saveStation() {
  if (!stationFor.value) return
  stationForm.post(route('manufacturing.setup.workstations.store', stationFor.value.id), {
    preserveScroll: true, onSuccess: () => { stationFor.value = null; stationForm.reset() },
  })
}

// ── Machines (assets) ───────────────────────────────────────────────────────
const machineFor = ref<Centre | null>(null)
const machineForm = useForm({ asset_id: null as number | null, is_primary: false })

function saveMachine() {
  if (!machineFor.value) return
  machineForm.post(route('manufacturing.setup.machines.attach', machineFor.value.id), {
    preserveScroll: true, onSuccess: () => { machineFor.value = null; machineForm.reset() },
  })
}

function detach(c: Centre, m: Machine) {
  router.delete(route('manufacturing.setup.machines.detach', [c.id, m.id]), { preserveScroll: true })
}

const money = (n: number | null) => (n === null ? '—' : n.toFixed(2))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.workcentres.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.workcentres.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.workcentres.add') }}</VBtn>
    </div>

    <!-- A centre with no rate cannot be planned or costed against. Said plainly, because a NULL
         rate is honest and 0.00 would quietly cost production at nothing. -->
    <VAlert v-if="unratedCount" type="warning" variant="tonal" class="mb-4">
      {{ t('manufacturing.workcentres.unrated', { n: unratedCount }) }}
    </VAlert>

    <VCard v-if="!work_centres.length" border>
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ t('manufacturing.workcentres.none') }}
        <div class="text-caption mt-1">{{ t('manufacturing.workcentres.none_hint') }}</div>
      </VCardText>
    </VCard>

    <VCard v-for="c in work_centres" :key="c.id" border class="mb-4" :class="{ 'opacity-60': !c.is_active }">
      <VCardItem>
        <VCardTitle>{{ c.code }} — {{ c.name }}</VCardTitle>
        <VCardSubtitle>
          {{ c.factory }}
          <template v-if="c.production_line"> · {{ c.production_line }}</template>
          <template v-if="c.department"> · {{ c.department }}</template>
        </VCardSubtitle>
        <template #append>
          <VChip size="small" label variant="tonal" :color="c.is_rated ? 'success' : 'warning'" class="me-2">
            {{ c.is_rated ? t('manufacturing.workcentres.rated') : t('manufacturing.workcentres.not_rated') }}
          </VChip>
          <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')"
                @click="openEdit(c)" />
          <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                @click="remove(c)" />
        </template>
      </VCardItem>

      <VCardText>
        <VRow dense class="mb-2">
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.workcentres.capacity') }}</div>
            <div>{{ c.capacity_per_hour ?? '—' }}</div>
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.workcentres.cost_per_hour') }}</div>
            <div>{{ money(c.cost_per_hour) }}</div>
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.workcentres.setup') }}</div>
            <div>{{ c.setup_minutes }} {{ t('manufacturing.workcentres.min') }}</div>
          </VCol>
          <VCol cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.workcentres.concurrent') }}</div>
            <div>{{ c.concurrent_jobs }}</div>
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="6">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-subtitle-2">{{ t('manufacturing.workcentres.workstations') }}</div>
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="stationFor = c">
                {{ t('manufacturing.common.add') }}
              </VBtn>
            </div>
            <div v-if="!c.workstations.length" class="text-caption text-medium-emphasis">
              {{ t('manufacturing.workcentres.no_workstations') }}
            </div>
            <VChip v-for="w in c.workstations" :key="w.id" size="small" label variant="tonal" class="me-1 mb-1">
              {{ w.code }} · {{ w.name }}
            </VChip>
          </VCol>
          <VCol cols="12" md="6">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-subtitle-2">{{ t('manufacturing.workcentres.machines') }}</div>
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="machineFor = c">
                {{ t('manufacturing.common.add') }}
              </VBtn>
            </div>
            <div v-if="!c.machines.length" class="text-caption text-medium-emphasis">
              {{ t('manufacturing.workcentres.no_machines') }}
            </div>
            <VChip v-for="m in c.machines" :key="m.id" size="small" label variant="tonal" closable
                   class="me-1 mb-1" :color="m.is_primary ? 'primary' : undefined"
                   @click:close="detach(c, m)">
              {{ m.name }}<template v-if="m.asset_tag"> ({{ m.asset_tag }})</template>
            </VChip>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="680">
      <VCard :title="editingId ? t('manufacturing.workcentres.edit') : t('manufacturing.workcentres.add')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="form.factory_uuid" :disabled="!!editingId" :items="factories" item-value="id"
                       :item-title="(f: any) => `${f.code} — ${f.name}`"
                       :error-messages="form.errors.factory_uuid" :label="t('manufacturing.workcentres.factory')" />
            </VCol>
            <VCol cols="4" md="2">
              <VTextField v-model="form.code" :error-messages="form.errors.code" :label="t('manufacturing.common.code')" />
            </VCol>
            <VCol cols="8" md="4">
              <VTextField v-model="form.name" :error-messages="form.errors.name" :label="t('manufacturing.common.name')" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.production_line_id" clearable :items="linesForFactory" item-value="id"
                       :item-title="(l: any) => `${l.code} — ${l.name}`" :label="t('manufacturing.workcentres.line')" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.department_id" clearable :items="departmentsForFactory" item-value="id"
                       :item-title="(d: any) => `${d.code} — ${d.name}`" :label="t('manufacturing.workcentres.department')" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model.number="form.capacity_per_hour" type="number" min="0" step="any"
                          :error-messages="form.errors.capacity_per_hour"
                          :label="t('manufacturing.workcentres.capacity')" />
            </VCol>
            <VCol cols="6" md="4">
              <VSelect v-model="form.capacity_uom_id" clearable :items="rateUnits" item-value="id"
                       :item-title="(u: any) => u.code" :label="t('manufacturing.workcentres.capacity_unit')" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.cost_per_hour" type="number" min="0" step="any"
                          :error-messages="form.errors.cost_per_hour"
                          :label="t('manufacturing.workcentres.cost_per_hour')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.setup_minutes" type="number" min="0"
                          :label="t('manufacturing.workcentres.setup')" :suffix="t('manufacturing.workcentres.min')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.concurrent_jobs" type="number" min="1"
                          :label="t('manufacturing.workcentres.concurrent')" />
            </VCol>
          </VRow>
          <VSwitch v-if="editingId" v-model="form.is_active" color="primary" hide-details
                   :label="t('manufacturing.product.active')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.factory_uuid" @click="save">
            {{ t('manufacturing.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!stationFor" max-width="440" @update:model-value="stationFor = null">
      <VCard :title="t('manufacturing.workcentres.add_workstation')">
        <VCardText>
          <VTextField v-model="stationForm.code" :error-messages="stationForm.errors.code" class="mb-2"
                      :label="t('manufacturing.common.code')" />
          <VTextField v-model="stationForm.name" :error-messages="stationForm.errors.name"
                      :label="t('manufacturing.common.name')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="stationFor = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="stationForm.processing" @click="saveStation">
            {{ t('manufacturing.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!machineFor" max-width="480" @update:model-value="machineFor = null">
      <VCard :title="t('manufacturing.workcentres.add_machine')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('manufacturing.workcentres.machine_hint') }}</p>
          <VAutocomplete v-model="machineForm.asset_id" :items="assets" item-value="id"
                         :item-title="(a: any) => a.asset_tag ? `${a.name} (${a.asset_tag})` : a.name"
                         :error-messages="machineForm.errors.asset_id" :label="t('manufacturing.workcentres.asset')"
                         class="mb-2" />
          <VSwitch v-model="machineForm.is_primary" color="primary" hide-details
                   :label="t('manufacturing.workcentres.primary_machine')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="machineFor = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="machineForm.processing" :disabled="!machineForm.asset_id"
                @click="saveMachine">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
