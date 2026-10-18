<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Step = {
  id?: string; position: number; name: string
  approver_type: string; approver_ref: string | null
  min_approvals: number; allow_self: boolean
}

type Flow = {
  id: string; name: string; subject_type: string; is_active: boolean
  threshold: number | null; currency: string | null
  expenditure_type: string | null; category_id: number | null
  escalate_after_hours: number | null
  description: string | null; pending: number; steps: Step[]
}

const props = defineProps<{
  flows: Flow[]
  subjectTypes: string[]
  approverTypes: string[]
  roles: string[]
  /** People a step can name — picked, never typed as a primary key. */
  users: Array<{ id: number; label: string }>
  categories: Array<{ id: number; label: string }>
  hasHr: boolean
}>()

const confirmer = ref()

const bySubject = computed(() => {
  const out: Record<string, Flow[]> = {}
  for (const type of props.subjectTypes) out[type] = []
  for (const flow of props.flows) (out[flow.subject_type] ??= []).push(flow)
  return out
})

// ── The designer ─────────────────────────────────────────────────────────────

const dialog = ref(false)
const editing = ref<Flow | null>(null)

const form = useForm<{
  name: string; subject_type: string; is_active: boolean
  threshold: number | string | null; currency: string | null
  expenditure_type: string | null; category_id: number | null
  escalate_after_hours: number | string | null
  description: string; steps: Step[]
}>({
  name: '', subject_type: 'budget', is_active: true,
  threshold: '', currency: null, expenditure_type: null, category_id: null,
  escalate_after_hours: '', description: '', steps: [],
})

function blankStep(position: number): Step {
  return { position, name: '', approver_type: 'role', approver_ref: null, min_approvals: 1, allow_self: false }
}

function openNew(subjectType: string) {
  editing.value = null
  form.reset()
  form.subject_type = subjectType
  form.steps = [blankStep(1)]
  dialog.value = true
}

function openEdit(flow: Flow) {
  editing.value = flow
  form.reset()
  form.name = flow.name
  form.subject_type = flow.subject_type
  form.is_active = flow.is_active
  form.threshold = flow.threshold ?? ''
  form.currency = flow.currency
  form.expenditure_type = flow.expenditure_type
  form.category_id = flow.category_id
  form.escalate_after_hours = flow.escalate_after_hours ?? ''
  form.description = flow.description ?? ''
  // Deep-copied: editing the dialog must not rewrite the card behind it before anything is saved.
  form.steps = flow.steps.map(s => ({ ...s }))
  dialog.value = true
}

function addStep() {
  form.steps.push(blankStep(form.steps.length + 1))
}

function removeStep(index: number) {
  form.steps.splice(index, 1)
  form.steps.forEach((s, i) => { s.position = i + 1 })
}

function moveStep(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= form.steps.length) return
  const [step] = form.steps.splice(index, 1)
  form.steps.splice(target, 0, step)
  form.steps.forEach((s, i) => { s.position = i + 1 })
}

function save() {
  const payload = form.transform(data => ({
    ...data,
    threshold: data.threshold === '' ? null : data.threshold,
    escalate_after_hours: data.escalate_after_hours === '' ? null : data.escalate_after_hours,
  }))

  const options = { preserveScroll: true, onSuccess: () => { dialog.value = false } }

  editing.value
    ? payload.put(route('budgets.settings.approvals.update', editing.value.id), options)
    : payload.post(route('budgets.settings.approvals.store'), options)
}

function destroy(flow: Flow) {
  confirmer.value.open({
    title: t('budgets_approval_setup.confirm_delete_title'),
    message: flow.pending
      ? t('budgets_approval_setup.confirm_delete_pending', { name: flow.name, count: flow.pending })
      : t('budgets_approval_setup.confirm_delete_body', { name: flow.name }),
    confirmText: t('budgets_approval_setup.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(route('budgets.settings.approvals.destroy', flow.id), { preserveScroll: true }))
}

/** What a step's `approver_ref` should offer, given the kind of approver it names. */
function refItems(step: Step) {
  if (step.approver_type === 'role') return props.roles.map(r => ({ title: r, value: r }))
  // Stored as a string because `approver_ref` holds role names too; the value is the user's id.
  if (step.approver_type === 'user') return props.users.map(u => ({ title: u.label, value: String(u.id) }))

  return []
}

function describe(step: Step) {
  if (step.approver_type === 'manager') return t('budgets_approval_setup.who_manager')
  if (step.approver_type === 'budget_owner') return t('budgets_approval_setup.who_owner')
  if (step.approver_type === 'user') {
    const person = props.users.find(u => String(u.id) === String(step.approver_ref))

    return person ? person.label : t('budgets_approval_setup.who_user')
  }
  return t('budgets_approval_setup.who_role', { role: step.approver_ref ?? '—' })
}

/** A one-line description of when a flow applies, so a card reads without opening it. */
function scopeOf(flow: Flow) {
  const bits: string[] = []
  if (flow.expenditure_type) bits.push(t(`budgets_approval_setup.type_${flow.expenditure_type}`))
  if (flow.category_id) bits.push(props.categories.find(c => c.id === flow.category_id)?.label ?? '—')
  if (flow.threshold !== null) bits.push(t('budgets_approval_setup.above', { amount: flow.threshold }))

  return bits.join(' · ')
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-2 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_approval_setup.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_approval_setup.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-checkbox" @click="router.get(route('budgets.approvals.index'))">
        {{ t('budgets_approval_setup.open_inbox') }}
      </VBtn>
    </div>

    <!--
      Stated rather than left implicit. Most tenants will never open this page, and "no flow"
      quietly meaning something is how people end up surprised by their own system.
    -->
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('budgets_approval_setup.default_notice') }}
    </VAlert>

    <VAlert v-if="!hasHr" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ t('budgets_approval_setup.no_hr_notice') }}
    </VAlert>

    <VRow>
      <VCol v-for="type in subjectTypes" :key="type" cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">
              {{ t(`budgets_approvals.subject_${type}`) }}
            </VCardTitle>
            <VCardSubtitle>{{ t(`budgets_approval_setup.hint_${type}`) }}</VCardSubtitle>
            <template #append>
              <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="openNew(type)">
                {{ t('budgets_approval_setup.add_flow') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VList v-if="bySubject[type]?.length" density="compact">
            <VListItem v-for="flow in bySubject[type]" :key="flow.id">
              <VListItemTitle class="d-flex align-center gap-2">
                {{ flow.name }}
                <VChip v-if="!flow.is_active" size="x-small" label>
                  {{ t('budgets_approval_setup.inactive') }}
                </VChip>
                <VChip v-if="flow.pending" size="x-small" color="warning" label>
                  {{ t('budgets_approval_setup.pending_count', { count: flow.pending }) }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <span v-for="(step, i) in flow.steps" :key="step.id ?? i">
                  <template v-if="i"> → </template>{{ step.name }}
                </span>
                <template v-if="scopeOf(flow)"> · {{ scopeOf(flow) }}</template>
              </VListItemSubtitle>
              <template #append>
                <VBtn size="small" variant="text" icon="tabler-edit"
                      :title="t('budgets_approval_setup.edit')" @click="openEdit(flow)" />
                <VBtn size="small" variant="text" icon="tabler-trash" color="error"
                      :title="t('budgets_approval_setup.delete')" @click="destroy(flow)" />
              </template>
            </VListItem>
          </VList>

          <VCardText v-else class="text-caption text-medium-emphasis">
            {{ t('budgets_approval_setup.none_for_type') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="880" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editing ? t('budgets_approval_setup.edit_flow') : t('budgets_approval_setup.add_flow') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12" md="7">
              <VTextField v-model="form.name" :label="t('budgets_approval_setup.field_name')"
                          :error-messages="form.errors.name" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="5">
              <VSelect
                v-model="form.subject_type"
                :items="subjectTypes.map(s => ({ title: t(`budgets_approvals.subject_${s}`), value: s }))"
                :label="t('budgets_approval_setup.field_subject')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.threshold" inputmode="decimal"
                :label="t('budgets_approval_setup.field_threshold')"
                :hint="t('budgets_approval_setup.hint_threshold')" persistent-hint
                :error-messages="form.errors.threshold"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.escalate_after_hours" inputmode="numeric"
                :label="t('budgets_approval_setup.field_escalate')"
                :hint="t('budgets_approval_setup.hint_escalate')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect
                v-model="form.expenditure_type"
                :items="[
                  { title: t('budgets_approval_setup.type_capex'), value: 'capex' },
                  { title: t('budgets_approval_setup.type_opex'), value: 'opex' },
                ]"
                :label="t('budgets_approval_setup.field_type')"
                :hint="t('budgets_approval_setup.hint_type')" persistent-hint
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect
                v-model="form.category_id" :items="categories.map(c => ({ title: c.label, value: c.id }))"
                :label="t('budgets_approval_setup.field_category')"
                :hint="t('budgets_approval_setup.hint_category')" persistent-hint
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" rows="2"
                         :label="t('budgets_approval_setup.field_description')"
                         variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="form.is_active" color="primary" density="compact" hide-details
                       :label="t('budgets_approval_setup.field_active')" />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <div class="d-flex align-center justify-space-between mb-2">
            <div>
              <div class="text-body-1 font-weight-medium">{{ t('budgets_approval_setup.steps') }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ t('budgets_approval_setup.steps_hint') }}
              </div>
            </div>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addStep">
              {{ t('budgets_approval_setup.add_step') }}
            </VBtn>
          </div>

          <VAlert v-if="form.errors.steps" type="error" variant="tonal" density="compact" class="mb-3">
            {{ form.errors.steps }}
          </VAlert>

          <VCard v-for="(step, index) in form.steps" :key="index" border class="mb-3">
            <VCardText class="pb-2">
              <div class="d-flex align-center gap-2 mb-2">
                <VAvatar size="26" color="primary" variant="tonal">
                  <span class="text-caption">{{ index + 1 }}</span>
                </VAvatar>
                <span class="text-caption text-medium-emphasis">{{ describe(step) }}</span>
                <VSpacer />
                <VBtn size="x-small" variant="text" icon="tabler-arrow-up" :disabled="index === 0"
                      :aria-label="t('budgets_approval_setup.move_up')" @click="moveStep(index, -1)" />
                <VBtn size="x-small" variant="text" icon="tabler-arrow-down" :disabled="index === form.steps.length - 1"
                      :aria-label="t('budgets_approval_setup.move_down')" @click="moveStep(index, 1)" />
                <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :disabled="form.steps.length === 1"
                      :aria-label="t('budgets_approval_setup.remove_step')" @click="removeStep(index)" />
              </div>

              <VRow dense>
                <VCol cols="12" md="4">
                  <VTextField v-model="step.name" :label="t('budgets_approval_setup.step_name')"
                              variant="outlined" density="compact" hide-details />
                </VCol>
                <VCol cols="12" md="3">
                  <VSelect
                    v-model="step.approver_type"
                    :items="approverTypes.map(a => ({ title: t(`budgets_approval_setup.approver_${a}`), value: a }))"
                    :label="t('budgets_approval_setup.step_who')"
                    variant="outlined" density="compact" hide-details
                  />
                </VCol>
                <VCol cols="12" md="3">
                  <VSelect
                    v-if="step.approver_type === 'role'"
                    v-model="step.approver_ref" :items="refItems(step)"
                    :label="t('budgets_approval_setup.step_ref')"
                    variant="outlined" density="compact" hide-details
                  />
                  <!-- Searchable, because a workspace can have hundreds of people and a plain
                       select is unusable past about twenty. -->
                  <VAutocomplete
                    v-else-if="step.approver_type === 'user'"
                    v-model="step.approver_ref" :items="refItems(step)"
                    :label="t('budgets_approval_setup.step_person')"
                    :no-data-text="t('budgets_approval_setup.no_person_match')"
                    variant="outlined" density="compact" hide-details
                  />
                </VCol>
                <VCol cols="12" md="2">
                  <VTextField
                    v-model.number="step.min_approvals" type="number" min="1" max="10"
                    :label="t('budgets_approval_setup.step_min')"
                    variant="outlined" density="compact" hide-details
                  />
                </VCol>
              </VRow>

              <VCheckbox
                v-model="step.allow_self" density="compact" hide-details class="mt-1"
                :label="t('budgets_approval_setup.allow_self')"
              />
            </VCardText>
          </VCard>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('budgets_approval_setup.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ t('budgets_approval_setup.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
