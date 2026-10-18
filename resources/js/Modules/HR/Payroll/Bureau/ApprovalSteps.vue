<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const a = (key: string, x?: Record<string, unknown>) => t('bureau.approval.' + key, x ?? {})

interface Step {
  uuid: string; sequence: number; name: string
  approver_type: string; role_name: string | null; approver_id: number | null
  approver_label: string; allow_self_approval: boolean; is_active: boolean
  instructions: string | null
}

const props = defineProps<{
  company: { uuid: string; name: string } | null
  steps: Step[]
  effective: { sequence: number; name: string; approver_label: string; inherited: boolean }[]
  roles: string[]
  people: { id: number; label: string }[]
  clients: { uuid: string; name: string }[]
}>()

const scoped = computed(() => Boolean(props.company))
const storeRoute = computed(() => scoped.value
  ? route('payroll.bureau.companies.approval-steps.store', props.company!.uuid)
  : route('payroll.bureau.approval-steps.store'))
const defaultsRoute = computed(() => scoped.value
  ? route('payroll.bureau.companies.approval-steps.defaults', props.company!.uuid)
  : route('payroll.bureau.approval-steps.defaults'))

const form = useForm({
  sequence: null as number | null, name: '',
  approver_type: 'role', role_name: '', approver_id: null as number | null,
  allow_self_approval: false, instructions: '',
})

function add() { form.post(storeRoute.value, { preserveScroll: true, onSuccess: () => form.reset() }) }
function toggle(step: Step) {
  router.put(route('payroll.bureau.approval-steps.update', step.uuid),
    { is_active: !step.is_active }, { preserveScroll: true })
}
const confirmDelete = ref<Step | null>(null)
function remove() {
  if (!confirmDelete.value) return
  router.delete(route('payroll.bureau.approval-steps.destroy', confirmDelete.value.uuid), {
    preserveScroll: true, onFinish: () => { confirmDelete.value = null },
  })
}
function installDefaults() { router.post(defaultsRoute.value, {}, { preserveScroll: true }) }
function openClient(uuid: string) {
  router.get(uuid ? route('payroll.bureau.companies.approval-steps', uuid)
                  : route('payroll.bureau.approval-steps.index'))
}

const clientItems = computed(() => [{ name: a('tenant_default'), uuid: '' }, ...props.clients])
</script>

<template>
  <div class="pa-4" style="max-width:900px;margin:auto">
    <div class="d-flex align-center ga-3 mb-4 flex-wrap">
      <VBtn
        icon="tabler-arrow-left" aria-label="Back" class="row-action" variant="text"
        @click="router.get(route('payroll.bureau.dashboard'))"
      />
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold mb-0">{{ a('page_title') }}</h1>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ company ? a('for_client', { client: company.name }) : a('for_tenant') }}
        </p>
      </div>
      <VSelect
        :model-value="company?.uuid ?? ''" :items="clientItems" item-title="name" item-value="uuid"
        :label="a('applies_to')" density="compact" hide-details style="max-width:260px"
        @update:model-value="openClient"
      />
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ company ? a('client_help') : a('tenant_help') }}
    </VAlert>

    <!-- What this client actually uses right now, own steps or inherited. -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ a('in_force') }}</VCardTitle>
      <VCardText>
        <div v-if="!effective.length" class="text-medium-emphasis text-body-2">{{ a('none_configured') }}</div>
        <div v-else class="d-flex align-center flex-wrap ga-1">
          <template v-for="(s, i) in effective" :key="s.sequence">
            <VChip size="small" variant="tonal" :color="s.inherited ? 'grey' : 'primary'">
              {{ s.sequence }}. {{ s.name }} — {{ s.approver_label }}
            </VChip>
            <VIcon v-if="i < effective.length - 1" size="14" class="text-medium-emphasis">tabler-chevron-right</VIcon>
          </template>
        </div>
        <p v-if="company && effective.some(s => s.inherited)" class="text-caption text-medium-emphasis mt-2 mb-0">
          {{ a('inherited_note') }}
        </p>
      </VCardText>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between text-subtitle-1">
        <span>{{ a('steps_here') }}</span>
        <VBtn size="small" variant="text" @click="installDefaults">{{ a('install_defaults') }}</VBtn>
      </VCardTitle>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ a('order') }}</th>
            <th>{{ a('step') }}</th>
            <th>{{ a('signed_by') }}</th>
            <th>{{ a('self_approval') }}</th>
            <th>{{ a('active') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in steps" :key="s.uuid">
            <td>{{ s.sequence }}</td>
            <td>
              {{ s.name }}
              <div v-if="s.instructions" class="text-caption text-medium-emphasis">{{ s.instructions }}</div>
            </td>
            <td>{{ s.approver_label }}</td>
            <td>
              <VChip v-if="s.allow_self_approval" size="x-small" color="warning" variant="tonal">
                {{ a('allowed') }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td>
              <VSwitch
                :model-value="s.is_active" density="compact" hide-details color="success"
                :aria-label="`Turn the ${s.name} step on or off`" @click="toggle(s)"
              />
            </td>
            <td class="text-end">
              <VBtn
                icon size="small" variant="text" color="error" class="row-action"
                :aria-label="`Remove the ${s.name} step`" @click="confirmDelete = s"
              ><VIcon>tabler-trash</VIcon></VBtn>
            </td>
          </tr>
          <tr v-if="!steps.length">
            <td colspan="6" class="text-center text-medium-emphasis py-6">{{ a('no_steps_here') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ a('add_step') }}</VCardTitle>
      <VCardText>
        <VRow dense>
          <VCol cols="6" sm="2">
            <VTextField v-model.number="form.sequence" type="number" :label="a('order')" :placeholder="a('auto')" density="compact" />
          </VCol>
          <VCol cols="12" sm="4">
            <VTextField v-model="form.name" :label="a('step')" :error-messages="form.errors.name" density="compact" />
          </VCol>
          <VCol cols="6" sm="3">
            <VSelect
              v-model="form.approver_type"
              :items="[{ title: a('by_role'), value: 'role' }, { title: a('by_person'), value: 'user' }]"
              :label="a('signed_by')" density="compact"
            />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect
              v-if="form.approver_type === 'role'"
              v-model="form.role_name" :items="roles" :label="a('role')" density="compact"
              :error-messages="form.errors.role_name"
            />
            <VSelect
              v-else
              v-model="form.approver_id" :items="people" item-title="label" item-value="id"
              :label="a('person')" density="compact" :error-messages="form.errors.approver_id"
            />
          </VCol>
          <VCol cols="12" sm="8">
            <VTextField v-model="form.instructions" :label="a('instructions')" :hint="a('instructions_hint')" persistent-hint density="compact" />
          </VCol>
          <VCol cols="12" sm="4" class="d-flex align-center justify-space-between ga-2">
            <VSwitch
              v-model="form.allow_self_approval" :label="a('self_approval')"
              density="compact" hide-details color="warning"
            />
            <VBtn color="primary" :loading="form.processing" @click="add">{{ t('bureau.ui.add') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VDialog
      :model-value="Boolean(confirmDelete)" max-width="460"
      @update:model-value="v => { if (!v) confirmDelete = null }"
    >
      <VCard v-if="confirmDelete">
        <VCardTitle>{{ a('remove_title') }}</VCardTitle>
        <VCardText>{{ a('remove_body', { step: confirmDelete.name }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDelete = null">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="error" @click="remove">{{ a('remove_confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
