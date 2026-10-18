<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The contract register: every project contract, and the way to raise one.
 *
 * Drafting offers a template or nothing at all. "From scratch" is not a fallback for an empty
 * library — a practice that writes its own terms each time is working normally, and the wording is
 * added on the contract page afterwards.
 */
const props = defineProps<{
  contracts: Array<Record<string, any>>
  filters: { status: string | null; project: string | null }
  statuses: string[]
  projects: Array<{ id: string; label: string }>
  // Contractors on each project, keyed by project uuid.
  members: Record<string, Array<{ id: string; label: string }>>
  templates: Array<{ id: string; name: string }>
  costs: boolean
  can: { draft: boolean; manageTemplates: boolean }
}>()

const STATUS_COLOUR: Record<string, string> = {
  draft: 'grey',
  in_review: 'info',
  approved: 'primary',
  out_for_signature: 'warning',
  signed: 'success',
  rejected: 'error',
  declined: 'error',
  cancelled: 'grey',
}

function open(id: string) {
  router.visit(route('project-management.contracts.show', id))
}

function money(amount: string | null | undefined, currency = 'ZAR') {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

// ── Filters ──────────────────────────────────────────────────────────────────
const status = ref(props.filters.status ?? '')
const project = ref(props.filters.project ?? '')

function applyFilters() {
  router.get(route('project-management.contracts.index'), {
    status: status.value || undefined,
    project: project.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

// ── Draft a contract ─────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  project: '', member: '', template: '', title: '', summary: '',
  contract_value: '', start_date: '', end_date: '',
})

const projectChosen = computed(() => !!form.project)

/** The chosen project's contractors. Empty until a project is picked. */
const membersForProject = computed(() => props.members[form.project] ?? [])

// Changing the project invalidates whoever was chosen under the previous one.
watch(() => form.project, () => { form.member = '' })

function draft() {
  form
    .transform(data => ({
      ...data,
      member: data.member || null,
      template: data.template || null,
    }))
    .post(route('project-management.contracts.store'), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ t('project_management.contracts.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.contracts.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="props.can.manageTemplates"
          variant="tonal" prepend-icon="tabler-file-description"
          @click="router.visit(route('project-management.contracts.templates.index'))"
        >
          {{ t('project_management.contracts.templates') }}
        </VBtn>
        <VBtn v-if="props.can.draft" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
          {{ t('project_management.contracts.draft') }}
        </VBtn>
      </div>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VSelect
          v-model="status" :items="[{ title: t('project_management.contracts.any_status'), value: '' },
            ...props.statuses.map(s => ({ title: t('project_management.contracts.status_' + s), value: s }))]"
          :label="t('project_management.contractors.filter_status')"
          variant="outlined" density="compact" style="max-inline-size: 240px"
          @update:model-value="applyFilters"
        />
        <VAutocomplete
          v-model="project" :items="[{ title: t('project_management.contracts.any_project'), value: '' },
            ...props.projects.map(p => ({ title: p.label, value: p.id }))]"
          :label="t('project_management.contractors.filter_project')"
          variant="outlined" density="compact" style="max-inline-size: 340px"
          @update:model-value="applyFilters"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable v-if="props.contracts.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.contracts.col_number') }}</th>
            <th>{{ t('project_management.contracts.col_title') }}</th>
            <th>{{ t('project_management.contractors.col_contractor') }}</th>
            <th>{{ t('project_management.contractors.col_project') }}</th>
            <th v-if="props.costs" class="text-end">{{ t('project_management.contractors.contract_value') }}</th>
            <th>{{ t('project_management.contractors.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <!--
            The row is a click target for the mouse, and the contract number is a real link so the
            row is reachable by keyboard and announced as a destination. A row that is only
            `@click` cannot be tabbed to at all.
          -->
          <tr
            v-for="c in props.contracts" :key="c.id" class="row-link"
            @click="open(c.id)"
          >
            <td class="text-no-wrap">
              <a
                :href="route('project-management.contracts.show', c.id)"
                class="text-primary font-weight-medium"
                @click.prevent.stop="open(c.id)"
              >{{ c.number }}</a>
              <VChip v-if="c.version > 1" size="x-small" variant="tonal" class="ms-1">
                {{ t('project_management.contracts.version_short', { no: c.version }) }}
              </VChip>
            </td>
            <td>{{ c.title }}</td>
            <td>{{ c.contractor ?? '—' }}</td>
            <td class="text-medium-emphasis">{{ c.project_code }}</td>
            <td v-if="props.costs" class="text-end figure">{{ money(c.value, c.currency) }}</td>
            <td>
              <VChip :color="STATUS_COLOUR[c.status] ?? 'grey'" size="small" variant="tonal">
                {{ t('project_management.contracts.status_' + c.status) }}
              </VChip>
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-10">
        {{ t('project_management.contracts.none') }}
      </VCardText>
    </VCard>

    <!-- ── Draft ──────────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="680">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contracts.draft') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contracts.draft_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.project"
                :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
                :label="t('project_management.contractors.field_project')"
                :error-messages="form.errors.project"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <!--
                The other party. Without one the contract can be drafted but never sent, because
                there is nobody to send it to — so the dialog says so rather than letting somebody
                find out two screens later.
              -->
              <VAutocomplete
                v-model="form.member"
                :items="membersForProject.map(m => ({ title: m.label, value: m.id }))"
                :label="t('project_management.contractors.col_contractor')"
                :hint="projectChosen && !membersForProject.length
                  ? t('project_management.contracts.no_contractors_on_project')
                  : t('project_management.contracts.member_hint')"
                persistent-hint
                :disabled="!projectChosen"
                :no-data-text="t('project_management.contracts.no_contractors_on_project')"
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.template"
                :items="props.templates.map(x => ({ title: x.name, value: x.id }))"
                :label="t('project_management.contracts.template')"
                :hint="t('project_management.contracts.template_hint')" persistent-hint
                :no-data-text="t('project_management.contracts.no_templates')"
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.title"
                :label="t('project_management.contractors.contract_title')"
                :hint="t('project_management.contracts.title_hint')" persistent-hint
                :error-messages="form.errors.title"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol v-if="props.costs" cols="12" md="4">
              <VTextField
                v-model="form.contract_value" inputmode="decimal"
                :label="t('project_management.contractors.contract_value')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.start_date" type="date"
                :label="t('project_management.contractors.start_date')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.end_date" type="date"
                :label="t('project_management.contractors.end_date')"
                :error-messages="form.errors.end_date"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="form.processing" :disabled="!projectChosen"
            @click="draft"
          >
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.figure { font-variant-numeric: tabular-nums; }

/* Hover feedback on colour only — no transform, which would shift the row under the pointer. */
.row-link { cursor: pointer; transition: background-color 200ms ease; }
.row-link:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.row-link:focus-within { background-color: rgba(var(--v-theme-primary), 0.08); }

@media (prefers-reduced-motion: reduce) {
  .row-link { transition: none; }
}
</style>
