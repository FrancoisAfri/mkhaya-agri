<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const route = ziggyRoute

type RatingScale = { id: number; name: string }
type Template    = { id: number; name: string }
type Cycle = {
  id: number
  name: string
  description: string | null
  cycle_type: string
  start_date: string
  end_date: string
  self_review_deadline: string | null
  manager_review_deadline: string | null
  status: 'draft' | 'active' | 'closed' | 'archived'
  rating_scale_id: number | null
  template_id: number | null
  allow_self_review: boolean
  allow_peer_review: boolean
  require_manager_approval: boolean
  rating_scale: RatingScale | null
  template: Template | null
}

const props = defineProps<{
  cycles: Cycle[]
  ratingScales: RatingScale[]
  templates: Template[]
}>()

const dialog    = ref(false)
const delOpen   = ref(false)
const delItem   = ref<Cycle | null>(null)
const saving    = ref(false)
const editing   = ref<Partial<Cycle> | null>(null)

const blank = (): Partial<Cycle> => ({
  name: '', description: null, cycle_type: 'annual',
  start_date: '', end_date: '',
  self_review_deadline: null, manager_review_deadline: null,
  status: 'draft',
  rating_scale_id: null, template_id: null,
  allow_self_review: true, allow_peer_review: false, require_manager_approval: true,
})

function openDialog(cycle?: Cycle) {
  editing.value = cycle ? { ...cycle } : blank()
  dialog.value  = true
}

function save() {
  if (!editing.value) return
  saving.value = true
  const p = editing.value
  const opts = { onSuccess: () => { dialog.value = false; saving.value = false }, onError: () => { saving.value = false } }
  if (p.id) {
    router.patch(route('performance.cycles.update', p.id), p, opts)
  } else {
    router.post(route('performance.cycles.store'), p, opts)
  }
}

function confirmDelete(cycle: Cycle) { delItem.value = cycle; delOpen.value = true }
function doDelete() {
  if (!delItem.value) return
  router.delete(route('performance.cycles.destroy', delItem.value.id), {
    onSuccess: () => { delItem.value = null; delOpen.value = false },
  })
}

function activate(cycle: Cycle) {
  router.patch(route('performance.cycles.activate', cycle.id), {}, { preserveScroll: true })
}
function closeCycle(cycle: Cycle) {
  router.patch(route('performance.cycles.close', cycle.id), {}, { preserveScroll: true })
}

const statusColor = (s: string) => ({ draft: 'default', active: 'success', closed: 'warning', archived: 'secondary' }[s] ?? 'default')
const statusLabel = (s: string) => ({ draft: 'Draft', active: 'Active', closed: 'Closed', archived: 'Archived' }[s] ?? s)

const cycleTypes = [
  { value: 'annual',      title: 'Annual' },
  { value: 'semi_annual', title: 'Semi-Annual' },
  { value: 'quarterly',   title: 'Quarterly' },
  { value: 'monthly',     title: 'Monthly' },
  { value: 'probation',   title: 'Probation' },
  { value: 'custom',      title: 'Custom' },
]

const statusOptions = [
  { value: 'draft',    title: 'Draft' },
  { value: 'active',   title: 'Active' },
  { value: 'closed',   title: 'Closed' },
  { value: 'archived', title: 'Archived' },
]
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.cycles.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.cycles.subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openDialog()">{{ t('pa.cycles.new') }}</VBtn>
    </div>

    <!-- Cycles Table -->
    <VCard>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('pa.cycles.col_name') }}</th>
            <th>{{ t('pa.cycles.col_type') }}</th>
            <th>{{ t('pa.cycles.col_period') }}</th>
            <th>{{ t('pa.cycles.col_template') }}</th>
            <th>{{ t('pa.cycles.col_scale') }}</th>
            <th>{{ t('pa.cycles.col_status') }}</th>
            <th style="width:56px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.cycles.length === 0">
            <td colspan="7" class="text-center py-8 text-medium-emphasis">No cycles yet — click "New Cycle" to get started.</td>
          </tr>
          <tr v-for="cycle in props.cycles" :key="cycle.id">
            <td class="font-weight-medium">{{ cycle.name }}</td>
            <td>
              <VChip size="x-small" variant="tonal">{{ t('pa.cycles.types.' + cycle.cycle_type) }}</VChip>
            </td>
            <td class="text-caption">{{ cycle.start_date }} – {{ cycle.end_date }}</td>
            <td class="text-caption">{{ cycle.template?.name ?? '—' }}</td>
            <td class="text-caption">{{ cycle.rating_scale?.name ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColor(cycle.status)" label>{{ statusLabel(cycle.status) }}</VChip>
            </td>
            <td class="text-right">
              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn v-bind="menuProps" icon="tabler-dots-vertical" size="small" variant="text" />
                </template>
                <VList density="compact" min-width="180">
                  <VListItem
                    prepend-icon="tabler-pencil"
                    :title="t('pa.common.edit')"
                    @click="openDialog(cycle)"
                  />
                  <VListItem
                    v-if="cycle.status === 'draft'"
                    prepend-icon="tabler-player-play"
                    :title="t('pa.cycles.activate')"
                    class="text-success"
                    @click="activate(cycle)"
                  />
                  <VListItem
                    v-if="cycle.status === 'active'"
                    prepend-icon="tabler-player-stop"
                    :title="t('pa.cycles.deactivate_close')"
                    class="text-warning"
                    @click="closeCycle(cycle)"
                  />
                  <VDivider />
                  <VListItem
                    prepend-icon="tabler-trash"
                    :title="t('pa.common.delete')"
                    class="text-error"
                    @click="confirmDelete(cycle)"
                  />
                </VList>
              </VMenu>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Create / Edit Dialog -->
    <VDialog v-model="dialog" max-width="640" persistent scrollable>
      <VCard v-if="editing">
        <VCardTitle class="d-flex align-center pa-4 border-b">
          <VIcon :icon="editing.id ? 'tabler-pencil' : 'tabler-circle-plus'" class="me-2" size="20" />
          {{ editing.id ? t('pa.cycles.edit') : t('pa.cycles.new_title') }}
          <VSpacer />
          <VBtn icon="tabler-x" variant="text" size="small" @click="dialog = false" />
        </VCardTitle>

        <VCardText class="pa-4">
          <!-- Basic info -->
          <VTextField
            v-model="editing.name"
            :label="t('pa.cycles.name')"
            :placeholder="t('pa.cycles.name_placeholder')"
            class="mb-3"
          />
          <VTextField
            v-model="editing.description"
            :label="t('pa.cycles.description')"
            class="mb-3"
          />

          <VRow>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="editing.cycle_type"
                :items="cycleTypes"
                item-title="title"
                item-value="value"
                :label="t('pa.cycles.cycle_type')"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="editing.status"
                :items="statusOptions"
                item-title="title"
                item-value="value"
                :label="t('pa.common.status')"
              />
            </VCol>
          </VRow>

          <VDivider class="my-3" />

          <!-- Date range -->
          <p class="text-caption text-medium-emphasis mb-2">{{ t('pa.cycles.review_period') }}</p>
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField v-model="editing.start_date" :label="t('pa.cycles.start_date')" type="date" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="editing.end_date" :label="t('pa.cycles.end_date')" type="date" />
            </VCol>
          </VRow>

          <!-- Deadlines -->
          <p class="text-caption text-medium-emphasis mb-2 mt-1">{{ t('pa.cycles.deadlines') }}</p>
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField v-model="editing.self_review_deadline" :label="t('pa.cycles.self_review_deadline')" type="date" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="editing.manager_review_deadline" :label="t('pa.cycles.manager_review_deadline')" type="date" />
            </VCol>
          </VRow>

          <VDivider class="my-3" />

          <!-- Template & Scale -->
          <VRow>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="editing.template_id"
                :items="props.templates"
                item-title="name"
                item-value="id"
                :label="t('pa.cycles.appraisal_template')"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="editing.rating_scale_id"
                :items="props.ratingScales"
                item-title="name"
                item-value="id"
                :label="t('pa.cycles.rating_scale')"
                clearable
              />
            </VCol>
          </VRow>

          <VDivider class="my-3" />

          <!-- Switches -->
          <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.cycles.review_options') }}</p>
          <VSwitch v-model="editing.allow_self_review"       :label="t('pa.cycles.allow_self_review')"       inset color="primary"  density="compact" class="mb-1" />
          <VSwitch v-model="editing.allow_peer_review"       :label="t('pa.cycles.allow_peer_review')"       inset color="primary"  density="compact" class="mb-1" />
          <VSwitch v-model="editing.require_manager_approval" :label="t('pa.cycles.require_manager_approval')" inset color="warning"  density="compact" />
        </VCardText>

        <VCardActions class="pa-4 border-t">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" @click="save">
            {{ editing.id ? t('pa.cycles.save_changes') : t('pa.cycles.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete confirmation -->
    <VDialog v-model="delOpen" max-width="420">
      <VCard v-if="delItem">
        <VCardTitle class="pa-4">{{ t('pa.cycles.delete') }}</VCardTitle>
        <VCardText>
          {{ t('pa.cycles.delete_confirm') }} <strong>{{ delItem.name }}</strong>?
          <div class="mt-2 text-caption text-error">{{ t('pa.cycles.cannot_undo') }}</div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
