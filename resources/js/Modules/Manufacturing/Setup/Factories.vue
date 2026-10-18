<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Dept = { id: string; code: string; name: string; manager_id: number | null; is_active: boolean }
type Line = { id: string; code: string; name: string; is_active: boolean }
type Access = { id: string; user_id: number; name: string; department: string | null; whole_factory: boolean }
type Factory = {
  id: string; code: string; name: string; description: string | null; address: string | null
  timezone: string; currency: string; measurement_system: string; default_warehouse_id: number | null
  tax_code: string | null; tax_rate: number | null; tax_registration_number: string | null
  is_active: boolean; work_centres_count: number; departments: Dept[]; production_lines: Line[]
  access: Access[]
}

defineProps<{
  factories: Factory[]
  measurement_systems: string[]
  timezones: string[]
  warehouses: Array<{ id: number; code: string; name: string }>
  people: Array<{ id: number; name: string }>
  can_see_all: boolean
}>()

// ── Factory dialog (create + edit share one form) ───────────────────────────
const dialog = ref(false)
const editingId = ref<string | null>(null)
const form = useForm({
  code: '', name: '', description: '', address: '', timezone: 'Africa/Johannesburg',
  currency: 'ZAR', measurement_system: 'METRIC', default_warehouse_id: null as number | null,
  tax_code: '', tax_rate: null as number | null, tax_registration_number: '',
  is_active: true,
})

function openCreate() {
  editingId.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(f: Factory) {
  editingId.value = f.id
  form.code = f.code
  form.name = f.name
  form.description = f.description ?? ''
  form.address = f.address ?? ''
  form.timezone = f.timezone
  form.currency = f.currency
  form.measurement_system = f.measurement_system
  form.default_warehouse_id = f.default_warehouse_id
  form.tax_code = f.tax_code ?? ''
  form.tax_rate = f.tax_rate
  form.tax_registration_number = f.tax_registration_number ?? ''
  form.is_active = f.is_active
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editingId.value) {
    form.patch(route('manufacturing.setup.factories.update', editingId.value), done)
  } else {
    form.post(route('manufacturing.setup.factories.store'), done)
  }
}

function remove(f: Factory) {
  confirmer.value.open({
    title: t('manufacturing.setup.remove_factory'),
    message: f.work_centres_count > 0
      ? t('manufacturing.setup.remove_factory_deactivates', { name: f.name })
      : t('manufacturing.product.remove_named', { name: f.name }),
    confirmText: f.work_centres_count > 0 ? t('manufacturing.setup.deactivate') : t('manufacturing.common.remove'),
    icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.setup.factories.destroy', f.id), { preserveScroll: true }))
}

// ── Departments and lines ───────────────────────────────────────────────────
const childDialog = ref<null | { factory: Factory; kind: 'department' | 'line' }>(null)
const childForm = useForm({ code: '', name: '', description: '' })

function openChild(factory: Factory, kind: 'department' | 'line') {
  childDialog.value = { factory, kind }
  childForm.reset()
  childForm.clearErrors()
}

// ── Who works here ──────────────────────────────────────────────────────────
const accessFor = ref<Factory | null>(null)
const accessForm = useForm({ user_id: null as number | null, department_uuid: null as string | null })

function openAccess(f: Factory) {
  accessFor.value = f
  accessForm.reset()
  accessForm.clearErrors()
}

function saveAccess() {
  if (!accessFor.value) return
  accessForm.post(route('manufacturing.setup.factories.access.store', accessFor.value.id), {
    preserveScroll: true, onSuccess: () => { accessFor.value = null },
  })
}

function revokeAccess(f: Factory, a: Access) {
  router.delete(route('manufacturing.setup.factories.access.destroy', [f.id, a.id]), { preserveScroll: true })
}

function saveChild() {
  if (!childDialog.value) return
  const { factory, kind } = childDialog.value
  const name = kind === 'department' ? 'manufacturing.setup.departments.store' : 'manufacturing.setup.lines.store'
  childForm.post(route(name, factory.id), {
    preserveScroll: true, onSuccess: () => { childDialog.value = null },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.factories.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.factories.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.factories.add') }}</VBtn>
    </div>

    <VAlert type="info" variant="tonal" class="mb-4">{{ t('manufacturing.factories.concepts') }}</VAlert>

    <VCard v-if="!factories.length" border>
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ t('manufacturing.factories.none') }}
        <div class="text-caption mt-1">{{ t('manufacturing.factories.none_hint') }}</div>
      </VCardText>
    </VCard>

    <VCard v-for="f in factories" :key="f.id" border class="mb-4" :class="{ 'opacity-60': !f.is_active }">
      <VCardItem>
        <template #prepend>
          <VAvatar color="primary" variant="tonal"><VIcon icon="tabler-building-factory-2" /></VAvatar>
        </template>
        <VCardTitle>{{ f.code }} — {{ f.name }}</VCardTitle>
        <VCardSubtitle>
          {{ f.timezone }} · {{ f.currency }}<template v-if="f.tax_code"> · {{ f.tax_code }}<template v-if="f.tax_rate !== null"> {{ f.tax_rate }}%</template></template>
          · {{ t(`manufacturing.measurement.${f.measurement_system}`) }}
          · {{ t('manufacturing.factories.n_work_centres', { n: f.work_centres_count }) }}
        </VCardSubtitle>
        <template #append>
          <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')"
                @click="openEdit(f)" />
          <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                @click="remove(f)" />
        </template>
      </VCardItem>

      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-subtitle-2">{{ t('manufacturing.factories.departments') }}</div>
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="openChild(f, 'department')">
                {{ t('manufacturing.common.add') }}
              </VBtn>
            </div>
            <div v-if="!f.departments.length" class="text-caption text-medium-emphasis">
              {{ t('manufacturing.factories.no_departments') }}
            </div>
            <VChip v-for="d in f.departments" :key="d.id" size="small" label variant="tonal" class="me-1 mb-1">
              {{ d.code }} · {{ d.name }}
            </VChip>
          </VCol>
          <VCol cols="12" md="6">
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-subtitle-2">{{ t('manufacturing.factories.lines') }}</div>
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="openChild(f, 'line')">
                {{ t('manufacturing.common.add') }}
              </VBtn>
            </div>
            <div v-if="!f.production_lines.length" class="text-caption text-medium-emphasis">
              {{ t('manufacturing.factories.no_lines') }}
            </div>
            <VChip v-for="l in f.production_lines" :key="l.id" size="small" label variant="tonal" class="me-1 mb-1">
              {{ l.code }} · {{ l.name }}
            </VChip>
          </VCol>
          <VCol cols="12">
            <VDivider class="mb-3" />
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="text-subtitle-2">{{ t('manufacturing.factories.who_works_here') }}</div>
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-user-plus" @click="openAccess(f)">
                {{ t('manufacturing.factories.assign') }}
              </VBtn>
            </div>
            <!-- An empty list is not "nobody may see this plant": a tenant that has assigned
                 nobody is unrestricted, and saying so avoids it being read the other way. -->
            <div v-if="!f.access.length" class="text-caption text-medium-emphasis">
              {{ t('manufacturing.factories.unrestricted') }}
            </div>
            <VChip v-for="a in f.access" :key="a.id" size="small" label variant="tonal" closable class="me-1 mb-1"
                   @click:close="revokeAccess(f, a)">
              {{ a.name }}<template v-if="!a.whole_factory"> · {{ a.department }}</template>
            </VChip>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="620">
      <VCard :title="editingId ? t('manufacturing.factories.edit') : t('manufacturing.factories.add')">
        <VCardText>
          <VRow dense>
            <VCol cols="4">
              <VTextField v-model="form.code" :error-messages="form.errors.code" :label="t('manufacturing.common.code')" />
            </VCol>
            <VCol cols="8">
              <VTextField v-model="form.name" :error-messages="form.errors.name" :label="t('manufacturing.common.name')" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.address" rows="2" auto-grow :label="t('manufacturing.factories.address')" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="form.timezone" :items="timezones" :error-messages="form.errors.timezone"
                       :label="t('manufacturing.factories.timezone')" />
            </VCol>
            <VCol cols="3">
              <VTextField v-model="form.currency" maxlength="3" :error-messages="form.errors.currency"
                          :label="t('manufacturing.factories.currency')" />
            </VCol>
            <VCol cols="3">
              <VSelect v-model="form.measurement_system"
                       :items="measurement_systems.map(m => ({ title: t(`manufacturing.measurement.${m}`), value: m }))"
                       :label="t('manufacturing.factories.measurement')" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model="form.tax_code" :error-messages="form.errors.tax_code"
                          :label="t('manufacturing.factories.tax_code')" />
            </VCol>
            <VCol cols="3">
              <VTextField v-model.number="form.tax_rate" type="number" min="0" max="100" step="any" suffix="%"
                          :error-messages="form.errors.tax_rate" :label="t('manufacturing.factories.tax_rate')" />
            </VCol>
            <VCol cols="5">
              <VTextField v-model="form.tax_registration_number" :error-messages="form.errors.tax_registration_number"
                          :label="t('manufacturing.factories.tax_registration')" />
            </VCol>
            <VCol cols="12">
              <!-- A factory is not a warehouse. It only names the one its stock lands in. -->
              <VSelect v-model="form.default_warehouse_id" clearable :items="warehouses" item-value="id"
                       :item-title="(w: any) => `${w.code} — ${w.name}`"
                       :hint="t('manufacturing.factories.warehouse_hint')" persistent-hint
                       :label="t('manufacturing.factories.warehouse')" />
            </VCol>
          </VRow>
          <VSwitch v-if="editingId" v-model="form.is_active" color="primary" hide-details class="mt-2"
                   :label="t('manufacturing.product.active')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!childDialog" max-width="440" @update:model-value="childDialog = null">
      <VCard :title="childDialog?.kind === 'department' ? t('manufacturing.factories.add_department') : t('manufacturing.factories.add_line')">
        <VCardText>
          <VTextField v-model="childForm.code" :error-messages="childForm.errors.code" class="mb-2"
                      :label="t('manufacturing.common.code')" />
          <VTextField v-model="childForm.name" :error-messages="childForm.errors.name"
                      :label="t('manufacturing.common.name')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="childDialog = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="childForm.processing" @click="saveChild">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!accessFor" max-width="480" @update:model-value="accessFor = null">
      <VCard :title="t('manufacturing.factories.assign_to', { name: accessFor?.name ?? '' })">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('manufacturing.factories.assign_hint') }}</p>
          <VAutocomplete v-model="accessForm.user_id" :items="people" item-value="id" item-title="name"
                         :error-messages="accessForm.errors.user_id" :label="t('manufacturing.factories.person')"
                         class="mb-3" />
          <VSelect v-model="accessForm.department_uuid" clearable item-value="id" item-title="name"
                   :items="accessFor?.departments ?? []"
                   :error-messages="accessForm.errors.department_uuid"
                   :hint="t('manufacturing.factories.whole_factory_hint')" persistent-hint
                   :label="t('manufacturing.factories.department_optional')" />
          <VAlert v-if="can_see_all" type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('manufacturing.factories.all_factories_note') }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="accessFor = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="accessForm.processing" :disabled="!accessForm.user_id" @click="saveAccess">
            {{ t('manufacturing.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
