<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Proj = { id: string; code: string; name: string; status: string; stage: string; budget: number | null }
type Prog = { id: string; code: string | null; name: string; outcome: string | null; budget: number | null; projects: Proj[]; total: number | null }
type Pf = { id: string; code: string | null; name: string; description: string | null; budget: number | null; programmes: Prog[]; projects: Proj[]; total: number | null }

const props = defineProps<{
  portfolios: Pf[]
  unassigned: Proj[]
  programmes: Array<{ id: string; name: string }>
  can: { manage: boolean; costs: boolean }
}>()

const empty = computed(() => props.portfolios.length === 0)

function money(v: number | null) {
  return v === null ? '—' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(v)
}

/**
 * How much of a portfolio's allowance the projects under it already account for.
 *
 * Shown as a bar because the number on its own does not answer the portfolio question — "are we
 * spending on the right things" is a comparison, not a total.
 */
function usage(pf: Pf) {
  if (!pf.budget || !pf.total) return null
  return Math.min(100, Math.round((pf.total / pf.budget) * 100))
}

function openProject(id: string) {
  router.get(route('project-management.projects.show', id))
}

const pfDialog = ref(false)
const pfForm = useForm({ code: '', name: '', description: '', budget_amount: null as number | null })

function savePortfolio() {
  pfForm.post(route('project-management.portfolios.store'), {
    preserveScroll: true, onSuccess: () => { pfDialog.value = false; pfForm.reset() },
  })
}

const progDialog = ref(false)
const progForm = useForm({
  portfolio_id: null as string | null, code: '', name: '', outcome: '',
  description: '', starts_on: '', ends_on: '', budget_amount: null as number | null,
})

function openProgramme(portfolioId: string | null) {
  progForm.reset()
  progForm.clearErrors()
  progForm.portfolio_id = portfolioId
  progDialog.value = true
}

function saveProgramme() {
  progForm.post(route('project-management.programmes.store'), {
    preserveScroll: true, onSuccess: () => { progDialog.value = false; progForm.reset() },
  })
}

// ── Moving a project into the hierarchy ─────────────────────────────────────
const assignDialog = ref(false)
const assigning = ref<Proj | null>(null)
const assignForm = useForm({ portfolio_id: null as string | null, programme_id: null as string | null })

function openAssign(p: Proj) {
  assigning.value = p
  assignForm.reset()
  assignForm.clearErrors()
  assignDialog.value = true
}

function saveAssign() {
  if (!assigning.value) return
  assignForm.post(route('project-management.projects.assign', assigning.value.id), {
    preserveScroll: true, onSuccess: () => { assignDialog.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.portfolio.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.portfolio.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-target" @click="openProgramme(null)">
          {{ t('project_management.portfolio.add_programme') }}
        </VBtn>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="pfDialog = true">
          {{ t('project_management.portfolio.add_portfolio') }}
        </VBtn>
      </div>
    </div>

    <!-- An empty page that explains itself, rather than one that looks broken. -->
    <VCard v-if="empty" border class="mb-4">
      <VCardText class="text-center py-10">
        <VIcon icon="tabler-stack-2" size="48" class="text-medium-emphasis mb-3" />
        <div class="text-body-1 mb-1">{{ t('project_management.portfolio.empty_title') }}</div>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width:640px;margin:0 auto">
          {{ t('project_management.portfolio.empty_body') }}
        </p>
      </VCardText>
    </VCard>

    <VCard v-for="pf in portfolios" :key="pf.id" border class="mb-4">
      <VCardItem>
        <template #prepend>
          <VAvatar color="primary" variant="tonal"><VIcon icon="tabler-stack-2" /></VAvatar>
        </template>
        <VCardTitle>
          <span v-if="pf.code" class="text-medium-emphasis me-2">{{ pf.code }}</span>{{ pf.name }}
        </VCardTitle>
        <VCardSubtitle>{{ pf.description || t('project_management.portfolio.no_description') }}</VCardSubtitle>
        <template #append>
          <div v-if="can.costs" class="text-end">
            <div class="text-body-2">{{ money(pf.total) }} / {{ money(pf.budget) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.portfolio.committed_of_allowance') }}</div>
          </div>
        </template>
      </VCardItem>

      <VCardText v-if="can.costs && usage(pf) !== null" class="pt-0">
        <VProgressLinear :model-value="usage(pf)!" :color="usage(pf)! > 100 ? 'error' : 'primary'" height="6" rounded />
      </VCardText>

      <VDivider />

      <VList density="compact">
        <template v-for="pg in pf.programmes" :key="pg.id">
          <VListSubheader class="d-flex align-center gap-2">
            <VIcon icon="tabler-target" size="16" />
            {{ pg.name }}
            <span v-if="pg.outcome" class="text-caption text-medium-emphasis">— {{ pg.outcome }}</span>
            <VSpacer />
            <span v-if="can.costs" class="text-caption">{{ money(pg.total) }}</span>
          </VListSubheader>
          <VListItem v-for="p in pg.projects" :key="p.id" class="ps-8" link @click="openProject(p.id)">
            <VListItemTitle class="text-body-2">{{ p.code }} · {{ p.name }}</VListItemTitle>
            <template #append>
              <VChip size="x-small" label>{{ t(`project_management.status.${p.status}`) }}</VChip>
              <span v-if="can.costs" class="text-caption ms-2">{{ money(p.budget) }}</span>
            </template>
          </VListItem>
          <VListItem v-if="!pg.projects.length" class="ps-8">
            <VListItemSubtitle class="text-caption">{{ t('project_management.portfolio.no_projects') }}</VListItemSubtitle>
          </VListItem>
        </template>

        <template v-if="pf.projects.length">
          <VListSubheader>{{ t('project_management.portfolio.direct') }}</VListSubheader>
          <VListItem v-for="p in pf.projects" :key="p.id" class="ps-8" link @click="openProject(p.id)">
            <VListItemTitle class="text-body-2">{{ p.code }} · {{ p.name }}</VListItemTitle>
            <template #append>
              <span v-if="can.costs" class="text-caption">{{ money(p.budget) }}</span>
            </template>
          </VListItem>
        </template>
      </VList>

      <VCardActions v-if="can.manage">
        <VBtn size="small" variant="text" prepend-icon="tabler-target" @click="openProgramme(pf.id)">
          {{ t('project_management.portfolio.add_programme_here') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- Everything not filed anywhere. Shown rather than hidden: a rollup that silently omits
         eleven projects is a rollup nobody should trust. -->
    <VCard v-if="unassigned.length" border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.portfolio.unassigned') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.portfolio.unassigned_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VList density="compact">
        <VListItem v-for="p in unassigned" :key="p.id">
          <VListItemTitle class="text-body-2">
            <a href="javascript:void(0)" @click="openProject(p.id)">{{ p.code }} · {{ p.name }}</a>
          </VListItemTitle>
          <template #append>
            <VBtn v-if="can.manage" size="small" variant="tonal" @click="openAssign(p)">
              {{ t('project_management.portfolio.file_it') }}
            </VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- ── Dialogs ──────────────────────────────────────────────────────── -->
    <VDialog v-model="pfDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portfolio.add_portfolio') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.portfolio.portfolio_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextField v-model="pfForm.name" :label="t('project_management.portfolio.field_name')"
                      :error-messages="pfForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="pfForm.code" :label="t('project_management.portfolio.field_code')"
                      variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="pfForm.description" :label="t('project_management.portfolio.field_description')"
                     variant="outlined" density="compact" rows="2" class="mb-3" />
          <VTextField v-if="can.costs" v-model.number="pfForm.budget_amount" type="number" step="0.01"
                      :label="t('project_management.portfolio.field_budget')"
                      :hint="t('project_management.portfolio.budget_hint')" persistent-hint
                      variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="pfDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="pfForm.processing" :disabled="!pfForm.name" @click="savePortfolio">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="progDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portfolio.add_programme') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.portfolio.programme_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAutocomplete v-model="progForm.portfolio_id"
                   :items="[{ title: t('project_management.portfolio.no_portfolio'), value: null }, ...portfolios.map(p => ({ title: p.name, value: p.id }))]"
                   :label="t('project_management.portfolio.field_portfolio')"
                   variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="progForm.name" :label="t('project_management.portfolio.field_name')"
                      :error-messages="progForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="progForm.outcome" :label="t('project_management.portfolio.field_outcome')"
                      :hint="t('project_management.portfolio.outcome_hint')" persistent-hint
                      variant="outlined" density="compact" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="progForm.starts_on" type="date"
                          :label="t('project_management.portfolio.field_starts')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="progForm.ends_on" type="date"
                          :label="t('project_management.portfolio.field_ends')"
                          :error-messages="progForm.errors.ends_on"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
          <VTextField v-if="can.costs" v-model.number="progForm.budget_amount" type="number" step="0.01"
                      :label="t('project_management.portfolio.field_budget')"
                      variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="progDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="progForm.processing" :disabled="!progForm.name" @click="saveProgramme">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="assignDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.portfolio.file_it') }}</VCardTitle>
          <VCardSubtitle>{{ assigning?.code }} · {{ assigning?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Says the rule out loud rather than surprising somebody with it after saving. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.portfolio.assign_note') }}
          </VAlert>
          <VAutocomplete v-model="assignForm.programme_id"
                   :items="[{ title: t('project_management.portfolio.no_programme'), value: null }, ...programmes.map(p => ({ title: p.name, value: p.id }))]"
                   :label="t('project_management.portfolio.field_programme')"
                   variant="outlined" density="compact" class="mb-3" />
          <VAutocomplete v-model="assignForm.portfolio_id" :disabled="!!assignForm.programme_id"
                   :items="[{ title: t('project_management.portfolio.no_portfolio'), value: null }, ...portfolios.map(p => ({ title: p.name, value: p.id }))]"
                   :label="t('project_management.portfolio.field_portfolio')"
                   variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="assignDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="assignForm.processing" @click="saveAssign">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
