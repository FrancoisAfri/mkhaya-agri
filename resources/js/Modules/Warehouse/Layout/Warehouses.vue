<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Option = { id: number; name: string }
type Staff = { id: string; user_id: number; name: string; shift: string | null; activities: string[]; is_active: boolean }
type PolicyValue = string | boolean | number
type Warehouse = {
  id: string; code: string; name: string; address: string | null; is_active: boolean; is_default: boolean
  warehouse_type: string; timezone: string; work_calendar_id: number | null; manager_user_id: number | null
  manager: string | null; sheq_site_id: number | null; sync_from_site: boolean; site_synced_at: string | null
  capacity_basis: string | null; capacity_value: number | null; opening_time: string | null
  closing_time: string | null; dispatch_cutoff_time: string | null; permitted_categories: string[]
  policies: Record<string, PolicyValue>; is_wms_managed: boolean; staff: Staff[]
}

const props = defineProps<{
  warehouses: Warehouse[]
  types: string[]
  capacity_bases: string[]
  activities: string[]
  policy_defaults: Record<string, PolicyValue>
  timezones: string[]
  people: Option[]
  calendars: Option[]
  shifts: Option[]
  sites: Array<{ id: number; name: string; timezone: string | null }>
  categories: string[]
  can: { manage: boolean }
}>()

// The choices each text policy offers. Boolean policies are switches.
const policyChoices: Record<string, string[]> = {
  rotation: ['FEFO', 'FIFO', 'LIFO', 'NONE'],
  allocation: ['FEFO', 'FIFO', 'LIFO', 'NONE'],
  putaway: ['SUGGESTED', 'DIRECTED', 'FREE'],
  picking: ['DISCRETE', 'BATCH', 'ZONE', 'WAVE', 'CLUSTER'],
  replenishment: ['MIN_MAX', 'REORDER_POINT', 'NONE'],
}
const boolPolicies = computed(() => Object.keys(props.policy_defaults).filter(k => typeof props.policy_defaults[k] === 'boolean'))
const numberPolicies = computed(() => Object.keys(props.policy_defaults).filter(k => typeof props.policy_defaults[k] === 'number'))

const dialog = ref(false)
const editingId = ref<string | null>(null)
const blank = () => ({
  code: '', name: '', address: '', is_default: false, warehouse_type: 'GENERAL', timezone: 'Africa/Johannesburg',
  work_calendar_id: null as number | null, manager_user_id: null as number | null,
  sheq_site_id: null as number | null, sync_from_site: false,
  capacity_basis: null as string | null, capacity_value: null as number | null,
  opening_time: '' as string | null, closing_time: '' as string | null, dispatch_cutoff_time: '' as string | null,
  permitted_categories: [] as string[], policies: { ...props.policy_defaults }, is_wms_managed: false,
})
const form = useForm(blank())

function openCreate() {
  editingId.value = null
  Object.assign(form, blank())
  form.clearErrors()
  dialog.value = true
}

function openEdit(w: Warehouse) {
  editingId.value = w.id
  Object.assign(form, {
    code: w.code, name: w.name, address: w.address ?? '', is_default: w.is_default, warehouse_type: w.warehouse_type,
    timezone: w.timezone, work_calendar_id: w.work_calendar_id, manager_user_id: w.manager_user_id,
    sheq_site_id: w.sheq_site_id, sync_from_site: w.sync_from_site, capacity_basis: w.capacity_basis,
    capacity_value: w.capacity_value, opening_time: w.opening_time ?? '', closing_time: w.closing_time ?? '',
    dispatch_cutoff_time: w.dispatch_cutoff_time ?? '', permitted_categories: [...w.permitted_categories],
    policies: { ...w.policies }, is_wms_managed: w.is_wms_managed,
  })
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  const tidy = (d: any) => ({
    ...d,
    opening_time: d.opening_time || null, closing_time: d.closing_time || null,
    dispatch_cutoff_time: d.dispatch_cutoff_time || null,
  })
  if (editingId.value) form.transform(tidy).patch(route('warehouse.layout.warehouses.update', editingId.value), done)
  else form.transform(tidy).post(route('warehouse.layout.warehouses.store'), done)
}

function retire(w: Warehouse) {
  confirmer.value.open({
    title: t('warehouse_management.warehouses.retire'),
    message: t('warehouse_management.warehouses.retire_confirm', { name: w.name }),
    confirmText: t('warehouse_management.warehouses.retire'),
    icon: 'tabler-archive',
  }, () => router.delete(route('warehouse.layout.warehouses.destroy', w.id), { preserveScroll: true }))
}

function sync(w: Warehouse) {
  router.post(route('warehouse.layout.warehouses.sync', w.id), {}, { preserveScroll: true })
}

const siteName = (id: number | null) => props.sites.find(s => s.id === id)?.name

// ── People ──────────────────────────────────────────────────────────────────
const staffFor = ref<Warehouse | null>(null)
const staffForm = useForm({ user_id: null as number | null, shift_id: null as number | null, activities: ['PICK'] as string[] })

function openStaff(w: Warehouse) {
  staffFor.value = w
  staffForm.reset()
  staffForm.clearErrors()
}

function saveStaff() {
  if (!staffFor.value) return
  staffForm.post(route('warehouse.layout.warehouses.staff.store', staffFor.value.id), {
    preserveScroll: true, onSuccess: () => { staffFor.value = null },
  })
}

function removeStaff(w: Warehouse, s: Staff) {
  router.delete(route('warehouse.layout.warehouses.staff.destroy', [w.id, s.id]), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.warehouses.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.warehouses.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('warehouse_management.warehouses.add') }}</VBtn>
    </div>

    <RefusalAlert />

    <VCard v-if="!warehouses.length" border>
      <VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.warehouses.none') }}</VCardText>
    </VCard>

    <VCard v-for="w in warehouses" :key="w.id" border class="mb-4" :class="{ 'opacity-60': !w.is_active }">
      <VCardItem>
        <template #prepend>
          <VAvatar color="primary" variant="tonal"><VIcon icon="tabler-building-warehouse" /></VAvatar>
        </template>
        <VCardTitle>
          {{ w.code }} — {{ w.name }}
          <VChip v-if="w.is_default" size="x-small" variant="tonal" class="ms-1">{{ t('warehouse_management.common.default') }}</VChip>
          <VChip v-if="w.is_wms_managed" size="x-small" color="primary" variant="tonal" class="ms-1">{{ t('warehouse_management.common.managed') }}</VChip>
          <VChip v-if="!w.is_active" size="x-small" color="secondary" variant="tonal" class="ms-1">{{ t('warehouse_management.common.retired') }}</VChip>
        </VCardTitle>
        <VCardSubtitle>
          {{ t(`warehouse_management.type.${w.warehouse_type}`) }} · {{ w.timezone }}
          <template v-if="w.opening_time"> · {{ w.opening_time }}–{{ w.closing_time }}</template>
          <template v-if="w.dispatch_cutoff_time"> · {{ t('warehouse_management.warehouses.cutoff') }} {{ w.dispatch_cutoff_time }}</template>
          <template v-if="w.manager"> · {{ t('warehouse_management.warehouses.manager') }}: {{ w.manager }}</template>
        </VCardSubtitle>
        <template v-if="can.manage" #append>
          <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('warehouse_management.common.edit')" @click="openEdit(w)" />
          <VBtn v-if="w.is_active" icon="tabler-archive" variant="text" size="small" :aria-label="t('warehouse_management.warehouses.retire')" @click="retire(w)" />
        </template>
      </VCardItem>
      <VCardText>
        <div v-if="w.address" class="text-body-2 mb-2">{{ w.address }}</div>
        <div v-if="w.sheq_site_id" class="d-flex align-center gap-2 mb-2 text-body-2">
          <VIcon icon="tabler-link" size="16" />
          {{ t('warehouse_management.warehouses.linked_site', { site: siteName(w.sheq_site_id) ?? '#' + w.sheq_site_id }) }}
          <span v-if="w.site_synced_at" class="text-medium-emphasis">· {{ t('warehouse_management.warehouses.synced_at', { at: new Date(w.site_synced_at).toLocaleString() }) }}</span>
          <VBtn v-if="can.manage" size="x-small" variant="tonal" prepend-icon="tabler-refresh" @click="sync(w)">{{ t('warehouse_management.warehouses.sync') }}</VBtn>
        </div>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <VChip v-for="(v, k) in w.policies" :key="k" size="small" label variant="outlined">
            {{ t(`warehouse_management.policy.${k}`) }}: {{ v === null ? '—' : typeof v === 'boolean' ? (v ? t('warehouse_management.common.yes') : t('warehouse_management.common.no')) : Array.isArray(v) ? v.join(', ') : v }}
          </VChip>
          <VBtn size="x-small" variant="text" :href="route('warehouse.layout.warehouses.policies', w.id)">{{ t('warehouse_management.policies.open') }}</VBtn>
        </div>
        <VDivider class="mb-3" />
        <div class="d-flex align-center justify-space-between mb-1">
          <div class="text-subtitle-2">{{ t('warehouse_management.warehouses.people') }}</div>
          <VBtn v-if="can.manage" size="x-small" variant="tonal" prepend-icon="tabler-user-plus" @click="openStaff(w)">{{ t('warehouse_management.common.add') }}</VBtn>
        </div>
        <div v-if="!w.staff.length" class="text-caption text-medium-emphasis">{{ t('warehouse_management.warehouses.no_people') }}</div>
        <VChip v-for="s in w.staff" :key="s.id" size="small" label variant="tonal" class="me-1 mb-1"
               :closable="can.manage" @click:close="removeStaff(w, s)">
          {{ s.name }}<template v-if="s.shift"> · {{ s.shift }}</template> · {{ s.activities.map(a => t(`warehouse_management.activity.${a}`)).join(', ') }}
        </VChip>
      </VCardText>
    </VCard>

    <!-- Warehouse dialog -->
    <VDialog v-model="dialog" max-width="860" scrollable>
      <VCard>
        <VCardTitle>{{ editingId ? t('warehouse_management.warehouses.edit') : t('warehouse_management.warehouses.add') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="3"><VTextField v-model="form.code" :label="t('warehouse_management.common.code')" :error-messages="form.errors.code" /></VCol>
            <VCol cols="12" md="5"><VTextField v-model="form.name" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.warehouse_type" :items="types.map(v => ({ value: v, title: t(`warehouse_management.type.${v}`) }))"
                       :label="t('warehouse_management.common.type')" :error-messages="form.errors.warehouse_type" />
            </VCol>
            <VCol cols="12"><VTextField v-model="form.address" :label="t('warehouse_management.warehouses.address')" :error-messages="form.errors.address" /></VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.warehouses.site_section') }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('warehouse_management.warehouses.site_hint') }}</div></VCol>
            <VCol cols="12" md="8">
              <VAutocomplete v-model="form.sheq_site_id" :items="sites" item-title="name" item-value="id" clearable
                             :label="t('warehouse_management.warehouses.site')" :error-messages="form.errors.sheq_site_id" />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch v-model="form.sync_from_site" :disabled="!form.sheq_site_id" color="primary" :label="t('warehouse_management.warehouses.sync_from_site')" />
            </VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.warehouses.hours_section') }}</div></VCol>
            <VCol cols="12" md="4">
              <VAutocomplete v-model="form.timezone" :items="timezones" :disabled="form.sync_from_site && !!form.sheq_site_id"
                             :label="t('warehouse_management.warehouses.timezone')" :error-messages="form.errors.timezone" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.work_calendar_id" :items="calendars" item-title="name" item-value="id" clearable
                       :label="t('warehouse_management.warehouses.calendar')" :error-messages="form.errors.work_calendar_id" />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete v-model="form.manager_user_id" :items="people" item-title="name" item-value="id" clearable
                             :label="t('warehouse_management.warehouses.manager')" :error-messages="form.errors.manager_user_id" />
            </VCol>
            <VCol cols="4"><VTextField v-model="form.opening_time" type="time" :label="t('warehouse_management.warehouses.opens')" :error-messages="form.errors.opening_time" /></VCol>
            <VCol cols="4"><VTextField v-model="form.closing_time" type="time" :label="t('warehouse_management.warehouses.closes')" :error-messages="form.errors.closing_time" /></VCol>
            <VCol cols="4"><VTextField v-model="form.dispatch_cutoff_time" type="time" :label="t('warehouse_management.warehouses.cutoff')" :error-messages="form.errors.dispatch_cutoff_time" /></VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.warehouses.capacity_section') }}</div></VCol>
            <VCol cols="6" md="4">
              <VSelect v-model="form.capacity_basis" :items="capacity_bases.map(v => ({ value: v, title: t(`warehouse_management.capacity.${v}`) }))" clearable
                       :label="t('warehouse_management.warehouses.capacity_basis')" />
            </VCol>
            <VCol cols="6" md="4"><VTextField v-model.number="form.capacity_value" type="number" min="0" :label="t('warehouse_management.warehouses.capacity_value')" :error-messages="form.errors.capacity_value" /></VCol>
            <VCol cols="12" md="4">
              <VCombobox v-model="form.permitted_categories" :items="categories" multiple chips closable-chips
                         :label="t('warehouse_management.warehouses.categories')" :hint="t('warehouse_management.warehouses.categories_hint')" persistent-hint />
            </VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.warehouses.policies_section') }}</div></VCol>
            <VCol v-for="(choices, key) in policyChoices" :key="key" cols="6" md="4">
              <VSelect v-model="(form.policies as any)[key]" :items="choices" :label="t(`warehouse_management.policy.${key}`)" />
            </VCol>
            <VCol v-for="key in numberPolicies" :key="key" cols="6" md="4">
              <VTextField v-model.number="(form.policies as any)[key]" type="number" min="0" :label="t(`warehouse_management.policy.${key}`)" />
            </VCol>
            <VCol v-for="key in boolPolicies" :key="key" cols="12" md="6">
              <VSwitch v-model="(form.policies as any)[key]" color="primary" density="compact" hide-details :label="t(`warehouse_management.policy.${key}`)" />
            </VCol>

            <VCol cols="12"><VDivider class="my-2" /></VCol>
            <VCol cols="12" md="6"><VSwitch v-model="form.is_default" color="primary" :label="t('warehouse_management.warehouses.is_default')" /></VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="form.is_wms_managed" color="primary" :label="t('warehouse_management.warehouses.is_wms_managed')"
                       :hint="t('warehouse_management.warehouses.is_wms_managed_hint')" persistent-hint />
            </VCol>
          </VRow>
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal" class="mt-2">{{ (form.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- People dialog -->
    <VDialog :model-value="!!staffFor" max-width="560" @update:model-value="v => { if (!v) staffFor = null }">
      <VCard v-if="staffFor">
        <VCardTitle>{{ t('warehouse_management.warehouses.assign_title', { name: staffFor.name }) }}</VCardTitle>
        <VCardText>
          <VAutocomplete v-model="staffForm.user_id" :items="people" item-title="name" item-value="id"
                         :label="t('warehouse_management.warehouses.person')" :error-messages="staffForm.errors.user_id" />
          <VSelect v-model="staffForm.shift_id" :items="shifts" item-title="name" item-value="id" clearable
                   :label="t('warehouse_management.warehouses.shift')" :error-messages="staffForm.errors.shift_id" />
          <VSelect v-model="staffForm.activities" :items="activities.map(a => ({ value: a, title: t(`warehouse_management.activity.${a}`) }))"
                   multiple chips :label="t('warehouse_management.warehouses.activities')" :error-messages="staffForm.errors.activities" />
          <div class="text-caption text-medium-emphasis">{{ t('warehouse_management.warehouses.activities_hint') }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="staffFor = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="staffForm.processing" @click="saveStaff">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
