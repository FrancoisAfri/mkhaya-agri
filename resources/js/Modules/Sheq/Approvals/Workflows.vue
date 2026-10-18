<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Step = {
  position: number; name: string; approver_type: string; approver_ref: string | null
  quorum: number; requires_signature: boolean; describes: string; candidate_count: number
}

type Workflow = {
  uuid: string; subject_type: string; subject_subtype: string | null; name: string
  description: string | null; version: number; sla_hours: number | null
  site_name: string | null; steps: Step[]
}

const props = defineProps<{
  workflows: Workflow[]
  subjectTypes: string[]
  approverTypes: Array<{ value: string; label: string }>
  roles: string[]
  users: Array<{ id: number; name: string }>
  competencies: Array<{ code: string; title: string }>
  sites: Array<{ id: number; name: string }>
  canManage?: boolean
  noCompany?: boolean
}>()

const dialog = ref(false)
const editing = ref<Workflow | null>(null)
const retiring = ref<Workflow | null>(null)

const blankStep = () => ({
  name: '', approver_type: 'ROLE', approver_ref: '' as string | number,
  quorum: 1, requires_signature: false,
})

const form = useForm({
  subject_type: 'PERMIT',
  subject_subtype: '',
  site_id: null as number | null,
  name: '',
  description: '',
  sla_hours: null as number | null,
  steps: [blankStep()],
})

/** One card per subject type, so a gap is visible as an empty card rather than an absence. */
const bySubject = computed(() => {
  const map: Record<string, Workflow[]> = {}
  for (const type of props.subjectTypes) map[type] = []
  for (const w of props.workflows) (map[w.subject_type] ??= []).push(w)
  return map
})

const subjectLabel = (type: string) =>
  t(`sheq.approvals.subjects.${type.toLowerCase()}`, type.replace(/_/g, ' ').toLowerCase())

/** Which reference field a step needs — a role name, a competency code, or nothing at all. */
const refKind = (type: string) =>
  ({ ROLE: 'role', SITE_ROLE: 'role', COMPETENCY_HOLDER: 'competency', USER: 'user' }[type] ?? null)

const addStep = () => form.steps.push(blankStep())
const removeStep = (i: number) => form.steps.length > 1 && form.steps.splice(i, 1)

function openNew(type: string) {
  editing.value = null
  form.clearErrors()
  form.defaults({ subject_type: type })
  Object.assign(form, {
    subject_type: type, subject_subtype: '', site_id: null,
    name: '', description: '', sla_hours: null, steps: [blankStep()],
  })
  dialog.value = true
}

/**
 * Editing loads the current version back into the designer and saves a new one.
 *
 * There is no edit-in-place on purpose: the server retires the old version and writes v+1, so a
 * request halfway through its approvals keeps the steps it opened under. Changing a live chain
 * under somebody's feet is how an approval ends up with a step nobody remembers agreeing to.
 */
function openEdit(workflow: Workflow) {
  editing.value = workflow
  form.clearErrors()
  Object.assign(form, {
    subject_type: workflow.subject_type,
    subject_subtype: workflow.subject_subtype ?? '',
    site_id: props.sites.find(s => s.name === workflow.site_name)?.id ?? null,
    name: workflow.name,
    description: workflow.description ?? '',
    sla_hours: workflow.sla_hours,
    steps: workflow.steps.map(s => ({
      name: s.name,
      approver_type: s.approver_type,
      approver_ref: s.approver_ref ?? '',
      quorum: s.quorum ?? 1,
      requires_signature: !!s.requires_signature,
    })),
  })
  dialog.value = true
}

const save = () => form.post('/sheq/approvals/workflows', {
  preserveScroll: true,
  onSuccess: () => { dialog.value = false; editing.value = null },
})

const retire = (workflow: Workflow) =>
  router.delete(`/sheq/approvals/workflows/${workflow.uuid}`, {
    preserveScroll: true,
    onFinish: () => { retiring.value = null },
  })

/** A step no one can fill deadlocks every request that reaches it — worth saying on the card. */
const unfillable = (workflow: Workflow) =>
  workflow.steps.filter(s => s.candidate_count === 0).length
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.approvals.designer') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('sheq.approvals.designer_subtitle') }}
        </p>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        type="info" variant="tonal" density="compact" class="mb-4"
        icon="mdi-history"
      >
        {{ t('sheq.approvals.versioned') }}
      </VAlert>

      <VAlert
        v-if="!canManage" type="info" variant="tonal" density="compact" class="mb-4"
        icon="mdi-eye-outline"
      >
        {{ t('sheq.approvals_setup.read_only') }}
      </VAlert>

      <VRow>
        <VCol v-for="type in subjectTypes" :key="type" cols="12" md="6">
          <VCard border elevation="0" rounded="lg" class="h-100">
            <VCardItem>
              <VCardTitle class="text-body-1">{{ subjectLabel(type) }}</VCardTitle>
              <VCardSubtitle class="text-caption">
                {{ t(`sheq.approvals_setup.hints.${type.toLowerCase()}`, '') }}
              </VCardSubtitle>
              <template #append>
                <VBtn
                  v-if="canManage" size="small" variant="tonal" prepend-icon="mdi-plus"
                  style="cursor: pointer" @click="openNew(type)"
                >
                  {{ t('sheq.approvals_setup.add_flow') }}
                </VBtn>
              </template>
            </VCardItem>

            <VDivider />

            <VList v-if="bySubject[type]?.length" density="compact">
              <VListItem v-for="workflow in bySubject[type]" :key="workflow.uuid">
                <VListItemTitle class="d-flex align-center flex-wrap ga-2">
                  {{ workflow.name }}
                  <VChip size="x-small" variant="tonal" label>
                    {{ t('sheq.approvals_setup.version', { n: workflow.version }) }}
                  </VChip>
                  <VChip v-if="workflow.site_name" size="x-small" variant="tonal" label>
                    {{ workflow.site_name }}
                  </VChip>
                  <VChip
                    v-if="unfillable(workflow)" size="x-small" color="error" variant="tonal" label
                  >
                    {{ t('sheq.approvals.no_approver') }}
                  </VChip>
                </VListItemTitle>

                <VListItemSubtitle class="text-caption">
                  <!-- The chain of authority, read left to right. -->
                  <span v-for="(step, i) in workflow.steps" :key="step.position">
                    <template v-if="i"> → </template>{{ step.name }}
                  </span>
                  <template v-if="workflow.sla_hours">
                    · {{ t('sheq.approvals_setup.sla', { n: workflow.sla_hours }) }}
                  </template>
                </VListItemSubtitle>

                <template v-if="canManage" #append>
                  <VBtn
                    size="small" variant="text" icon="mdi-pencil-outline" style="cursor: pointer"
                    :aria-label="t('sheq.approvals_setup.edit_flow', { name: workflow.name })"
                    @click="openEdit(workflow)"
                  />
                  <VBtn
                    size="small" variant="text" icon="mdi-archive-arrow-down-outline" color="error"
                    style="cursor: pointer"
                    :aria-label="t('sheq.approvals_setup.retire_flow', { name: workflow.name })"
                    @click="retiring = workflow"
                  />
                </template>
              </VListItem>
            </VList>

            <VCardText v-else class="text-caption text-medium-emphasis">
              {{ t('sheq.approvals_setup.none_for_type') }}
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ── Designer ───────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="1100" scrollable>
      <VCard rounded="lg">
        <VCardItem>
          <VCardTitle>
            {{ editing
              ? t('sheq.approvals_setup.edit_title', { name: editing.name })
              : t('sheq.approvals_setup.new_title', { subject: subjectLabel(form.subject_type) }) }}
          </VCardTitle>
          <VCardSubtitle v-if="editing" class="text-caption">
            {{ t('sheq.approvals_setup.edit_hint', { n: editing.version + 1 }) }}
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <!--
                What this chain approves, shown and not chosen.

                It was a dropdown, and changing it was the one thing you could do here that could
                not work. The dialog is opened from a subject's own section — "add a workflow for
                Permits" — so the type is already decided; switching it afterwards left the subtype
                and the steps describing the type you started from, and the save failed on the
                mismatch. Worse when editing: the server retires the existing chain for whatever
                (type, subtype, site) it is saved against, so a changed type would retire a
                different subject's workflow and write this one in its place.

                Read-only rather than removed: which subject you are configuring is exactly the
                thing to be sure of before adding four approval steps to it.
              -->
              <VTextField
                :model-value="subjectLabel(form.subject_type)"
                readonly variant="outlined" density="compact"
                :label="t('sheq.approvals_setup.approves')"
                :error-messages="form.errors.subject_type"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.name" variant="outlined" density="compact"
                :label="t('sheq.approvals_setup.flow_name')"
                :error-messages="form.errors.name"
              />
            </VCol>
            <VCol cols="12" md="2">
              <VSelect
                v-model="form.site_id" :items="sites" item-title="name" item-value="id" clearable
                variant="outlined" density="compact"
                :label="t('sheq.approvals_setup.site_optional')"
              />
            </VCol>
            <VCol cols="12" md="2">
              <VTextField
                v-model.number="form.sla_hours" type="number" min="1"
                variant="outlined" density="compact"
                :label="t('sheq.approvals_setup.sla_hours')"
                :error-messages="form.errors.sla_hours"
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <!-- Steps run top to bottom; the order is the chain of authority. -->
          <div
            v-for="(step, index) in form.steps" :key="index"
            class="mb-3 pa-3 border rounded"
          >
            <div class="d-flex align-center ga-2 mb-2">
              <VAvatar size="24" color="primary" variant="tonal" class="text-caption">
                {{ index + 1 }}
              </VAvatar>
              <VTextField
                v-model="step.name" variant="outlined" density="compact" hide-details
                :label="t('sheq.approvals_setup.step_name')" class="flex-grow-1"
              />
              <VBtn
                icon="mdi-close" size="small" variant="text" style="cursor: pointer"
                :disabled="form.steps.length === 1"
                :aria-label="t('sheq.approvals_setup.remove_step', { n: index + 1 })"
                @click="removeStep(index)"
              />
            </div>

            <VRow dense>
              <VCol cols="12" md="4">
                <VSelect
                  v-model="step.approver_type" :items="approverTypes"
                  item-title="label" item-value="value"
                  variant="outlined" density="compact" hide-details
                  :label="t('sheq.approvals_setup.approved_by')"
                />
              </VCol>

              <VCol v-if="refKind(step.approver_type) === 'role'" cols="12" md="4">
                <VSelect
                  v-model="step.approver_ref" :items="roles"
                  variant="outlined" density="compact" hide-details
                  :label="t('sheq.approvals_setup.role')"
                />
              </VCol>
              <VCol v-else-if="refKind(step.approver_type) === 'competency'" cols="12" md="4">
                <VSelect
                  v-model="step.approver_ref" :items="competencies"
                  item-title="title" item-value="code"
                  variant="outlined" density="compact" hide-details
                  :label="t('sheq.approvals_setup.competency')"
                />
              </VCol>
              <VCol v-else-if="refKind(step.approver_type) === 'user'" cols="12" md="4">
                <!--
                  A list, not a typed id. Naming the wrong person routes approvals silently;
                  naming nobody deadlocks every request that reaches this step.
                -->
                <VSelect
                  v-model="step.approver_ref" :items="users"
                  item-title="name" item-value="id"
                  variant="outlined" density="compact" hide-details
                  :label="t('sheq.approvals_setup.person')"
                  :no-data-text="t('sheq.approvals.no_users')"
                />
              </VCol>

              <VCol cols="6" md="2">
                <VTextField
                  v-model.number="step.quorum" type="number" min="1"
                  variant="outlined" density="compact" hide-details
                  :label="t('sheq.approvals_setup.approvals_needed')"
                />
              </VCol>
              <VCol cols="6" md="2" class="d-flex align-center">
                <VCheckbox
                  v-model="step.requires_signature" hide-details density="compact"
                  :label="t('sheq.approvals_setup.signature')"
                />
              </VCol>
            </VRow>
          </div>

          <VBtn
            variant="outlined" size="small" prepend-icon="mdi-plus" style="cursor: pointer"
            @click="addStep"
          >
            {{ t('sheq.approvals.add_step') }}
          </VBtn>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" style="cursor: pointer" @click="dialog = false">
            {{ t('sheq.approvals_setup.cancel') }}
          </VBtn>
          <VBtn
            color="primary" variant="flat" style="cursor: pointer"
            :loading="form.processing" :disabled="form.processing" @click="save"
          >
            {{ t('sheq.approvals.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Retire confirmation ────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="!!retiring" max-width="620" @update:model-value="retiring = null">
      <VCard rounded="lg">
        <VCardTitle class="text-h6 py-4">{{ t('sheq.approvals_setup.retire_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="text-body-2">
          {{ t('sheq.approvals_setup.retire_confirm', { name: retiring?.name ?? '' }) }}
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" style="cursor: pointer" @click="retiring = null">
            {{ t('sheq.approvals_setup.cancel') }}
          </VBtn>
          <VBtn
            color="error" variant="flat" style="cursor: pointer"
            @click="retiring && retire(retiring)"
          >
            {{ t('sheq.approvals_setup.retire') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
