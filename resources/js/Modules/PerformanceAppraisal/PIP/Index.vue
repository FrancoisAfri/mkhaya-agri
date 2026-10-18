
<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface PipPlan {
  id: number; title: string; employee_id: number; employee_name: string
  manager_name: string; status: string; overall_progress: number
  days_remaining: number; start_date: string; target_end_date: string
}
interface Cycle    { id: number; name: string }
interface Employee { id: number; name: string }

const props = defineProps<{
  plans: PipPlan[]; cycles: Cycle[]; employees: Employee[]
  stats: { active: number; draft: number; closed_improved: number; escalated: number }
}>()

const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()
const delOpen    = ref(false)
const delItem    = ref<PipPlan | null>(null)
const deleting   = ref(false)
const filterCycle  = ref<number | null>(null)
const filterStatus = ref<string | null>(null)

const statusItems = [
  { label: 'Draft',           value: 'draft' },
  { label: 'Active',          value: 'active' },
  { label: 'Extended',        value: 'extended' },
  { label: 'Closed (Improved)', value: 'closed_improved' },
  { label: 'Closed (Not Improved)', value: 'closed_not_improved' },
  { label: 'Escalated',       value: 'escalated' },
]

const form = reactive({
  title: '', employee_id: null as number | null, manager_id: null as number | null,
  cycle_id: null as number | null, reason: '', start_date: '', target_end_date: '',
})

function applyFilter() {
  router.get(route('performance.pip.index'), {
    cycle_id: filterCycle.value  ?? undefined,
    status:   filterStatus.value ?? undefined,
  }, { preserveState: true, replace: true })
}

function openCreate() {
  Object.assign(form, { title: '', employee_id: null, manager_id: null, cycle_id: null, reason: '', start_date: '', target_end_date: '' })
  createOpen.value = true
}

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.pip.store'), form as any, {
    onSuccess: () => { createOpen.value = false },
    onFinish:  () => { saving.value = false },
  })
}

function openDelete(p: PipPlan) { delItem.value = p; delOpen.value = true }
function confirmDelete() {
  if (!delItem.value) return
  deleting.value = true
  router.delete(route('performance.pip.destroy', delItem.value.id), {
    onSuccess: () => { delOpen.value = false },
    onFinish:  () => { deleting.value = false },
  })
}

function statusColor(s: string): string {
  return ({ draft: 'grey', active: 'blue', extended: 'orange', closed_improved: 'green', closed_not_improved: 'error', escalated: 'deep-purple' } as Record<string,string>)[s] ?? 'grey'
}
function statusIcon(s: string): string {
  return ({ draft: 'tabler-file', active: 'tabler-player-play', extended: 'tabler-clock', closed_improved: 'tabler-circle-check', closed_not_improved: 'tabler-circle-x', escalated: 'tabler-alert-circle' } as Record<string,string>)[s] ?? 'tabler-circle-filled'
}
function statusLabel(s: string): string {
  return ({ draft: 'Draft', active: 'Active', extended: 'Extended', closed_improved: 'Improved', closed_not_improved: 'Not Improved', escalated: 'Escalated' } as Record<string,string>)[s] ?? s
}
function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', {
    day: '2-digit', month: 'short', year: 'numeric'
  })
}
</script>
<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.pip.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.pip.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('pa.pip.new') }}</VBtn>
    </div>

    <!-- Filters -->
    <div class="d-flex ga-3 mb-5 flex-wrap">
      <VSelect
        v-model="filterCycle"
        :items="cycles"
        item-title="name"
        item-value="id"
        :label="t('pa.analytics.review_cycle')"
        density="compact"
        variant="outlined"
        clearable
        hide-details
        style="max-width: 240px"
        @update:model-value="applyFilter"
      />
      <VSelect
        v-model="filterStatus"
        :items="statusItems"
        item-title="label"
        item-value="value"
        :label="t('pa.common.status')"
        density="compact"
        variant="outlined"
        clearable
        hide-details
        style="max-width: 200px"
        @update:model-value="applyFilter"
      />
    </div>

    <!-- Stats strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-blue">{{ stats.active }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.active') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-grey">{{ stats.draft }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.draft') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-green">{{ stats.closed_improved }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.closed_improved') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-error">{{ stats.escalated }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.escalated') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- PIP grid -->
    <VRow v-if="plans.length" dense>
      <VCol v-for="p in plans" :key="p.id" cols="12" sm="6" xl="4">
        <VCard border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar :color="statusColor(p.status)" variant="tonal" size="40">
                <VIcon size="18">{{ statusIcon(p.status) }}</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-2 font-weight-medium">{{ p.title }}</VCardTitle>
            <VCardSubtitle>{{ p.employee_name }}</VCardSubtitle>
            <template #append>
              <VChip size="x-small" :color="statusColor(p.status)" variant="tonal">{{ statusLabel(p.status) }}</VChip>
            </template>
          </VCardItem>

          <VCardText class="pt-0 pb-1">
            <div class="d-flex justify-space-between text-caption mb-1">
              <span>{{ t('pa.common.progress') }}</span>
              <span class="font-weight-medium">{{ p.overall_progress }}%</span>
            </div>
            <VProgressLinear
              :model-value="p.overall_progress"
              :color="p.overall_progress >= 75 ? 'green' : p.overall_progress >= 40 ? 'blue' : 'orange'"
              height="6"
              rounded
              class="mb-2"
            />
            <div class="d-flex justify-space-between text-caption text-medium-emphasis">
              <span>{{ t('pa.common.manager') }}: {{ p.manager_name }}</span>
              <span v-if="p.days_remaining > 0">{{ p.days_remaining }}d {{ t('pa.pip.left') }}</span>
              <span v-else class="text-error">{{ t('pa.pip.overdue') }}</span>
            </div>
          </VCardText>

          <VCardActions class="px-4 pb-3 pt-1">
         <span class="text-caption text-medium-emphasis">
  {{ formatDate(p.start_date) }} → {{ formatDate(p.target_end_date) }}
</span>
            <VSpacer />
            <VBtn size="x-small" variant="tonal" color="primary" @click="router.visit(route('performance.pip.show', p.id))">
              {{ t('pa.pip.open') }}
            </VBtn>
            <VBtn size="x-small" icon variant="text" color="error" class="ml-1" @click="openDelete(p)">
              <VIcon>tabler-trash</VIcon>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
    <VAlert v-else type="info" variant="tonal">{{ t('pa.pip.empty') }}</VAlert>

    <!-- Create drawer -->
    <VNavigationDrawer v-model="createOpen" location="right" width="480" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.pip.new_title') }}</VToolbarTitle>
        <template #append><VBtn icon @click="createOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
      </VToolbar>
      <VForm ref="createForm" @submit.prevent="submitCreate" class="pa-4">
        <VTextField v-model="form.title" :label="t('pa.pip.pip_title')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
<VSelect
  v-model="form.employee_id"
  :items="employees"
  item-title="name"
  item-value="id"
  :label="t('pa.common.employee')"
  density="compact"
  variant="outlined"
  :rules="[v => !!v || 'Required']"
  class="mb-3"
  :menu-props="{ zIndex: 2500 }"
/>
<VSelect
  v-model="form.manager_id"
  :items="employees"
  item-title="name"
  item-value="id"
  :label="t('pa.common.manager')"
  density="compact"
  variant="outlined"
  :rules="[v => !!v || 'Required']"
  class="mb-3"
  :menu-props="{ zIndex: 2500 }"
/>
<VSelect
  v-model="form.cycle_id"
  :items="cycles"
  item-title="name"
  item-value="id"
  :label="t('pa.common.cycle_optional')"
  density="compact"
  variant="outlined"
  clearable
  class="mb-3"
  :menu-props="{ zIndex: 2500 }"
/>
        <VSelect v-model="form.cycle_id" :items="cycles" item-title="name" item-value="id" :label="t('pa.common.cycle_optional')" density="compact" variant="outlined" clearable class="mb-3" />
        <VTextarea v-model="form.reason" :label="t('pa.pip.reason')" density="compact" variant="outlined" rows="3" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextField v-model="form.start_date" :label="t('pa.cycles.start_date')" type="date" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextField v-model="form.target_end_date" :label="t('pa.pip.target_end_date')" type="date" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-4" />
        <VBtn type="submit" color="primary" block :loading="saving">{{ t('pa.pip.create') }}</VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Delete dialog -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.pip.delete_title') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deleting" @click="confirmDelete">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>


<style scoped>
:deep(.v-overlay__content .v-list-item__content) {
  color: rgba(var(--v-theme-on-surface), 1);
}
</style>
