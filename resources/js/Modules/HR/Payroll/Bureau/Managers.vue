<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.mg.' + key, a ?? {})

/**
 * Who approves whose leave, changed for a whole team at once.
 *
 * This changes far more often than anything else about an employee — somebody resigns, a department
 * splits, a supervisor goes on long leave — and it always changes for a group. Doing it one employee
 * form at a time means remembering which fourteen people, which in practice means it is left half
 * done and requests pile up against a manager who left in March.
 *
 * Organised by manager rather than by employee, because "who reports to whom" is the question being
 * asked and a flat list of four hundred names does not answer it.
 */
interface Member { uuid: string; name: string; number: string | null; occupation: string | null }
interface Team { manager: string | null; manager_name: string | null; members: Member[] }

const props = defineProps<{
  company: { uuid: string; name: string }
  teams: Team[]
  people: { uuid: string; name: string; number: string | null }[]
}>()

const page = usePage()

const selected = ref<string[]>([])
const newManager = ref<string | null>(null)
const confirming = ref(false)
const saving = ref(false)

function toggleTeam(team: Team, on: boolean) {
  const ids = team.members.map(m => m.uuid)
  selected.value = on
    ? Array.from(new Set([...selected.value, ...ids]))
    : selected.value.filter(id => !ids.includes(id))
}

function teamState(team: Team) {
  const ids = team.members.map(m => m.uuid)
  const picked = ids.filter(id => selected.value.includes(id)).length

  return { all: picked === ids.length && ids.length > 0, some: picked > 0 && picked < ids.length }
}

const people = computed(() =>
  props.people.map(p => ({ title: `${p.name}${p.number ? ' · ' + p.number : ''}`, value: p.uuid })))

// The destination cannot be one of the people being moved, or they would approve their own leave.
const managerOptions = computed(() => people.value.filter(p => !selected.value.includes(p.value)))

const newManagerName = computed(() =>
  props.people.find(p => p.uuid === newManager.value)?.name ?? 'nobody')

const selectedNames = computed(() =>
  props.teams.flatMap(t => t.members).filter(m => selected.value.includes(m.uuid)))

function apply() {
  saving.value = true
  router.post(route('payroll.bureau.managers.reassign', props.company.uuid), {
    employees: selected.value,
    manager: newManager.value,
  }, {
    preserveScroll: true,
    onSuccess: () => { selected.value = []; newManager.value = null; confirming.value = false },
    onFinish: () => { saving.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" :href="route('payroll.bureau.employees.index', company.uuid)" class="mb-1">
      {{ company.name }}
    </VBtn>

    <h1 class="text-h5 font-weight-medium mb-1">{{ u('reporting_lines') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">
      {{ k('intro', { client: company.name }) }}
    </p>

    <VAlert v-if="(page.props.flash as any)?.success" type="success" variant="tonal" density="compact" class="mb-4">
      {{ (page.props.flash as any).success }}
    </VAlert>

    <VCard v-for="team in teams" :key="team.manager ?? 'none'" variant="outlined" class="mb-4">
      <VCardItem>
        <template #prepend>
          <VCheckbox
            :model-value="teamState(team).all"
            :indeterminate="teamState(team).some"
            hide-details
            density="compact"
            :aria-label="`Select everyone reporting to ${team.manager_name ?? 'nobody'}`"
            @update:model-value="v => toggleTeam(team, !!v)"
          />
        </template>
        <VCardTitle class="text-subtitle-1">
          <template v-if="team.manager_name">{{ u('reports_to') }} {{ team.manager_name }}</template>
          <span v-else class="text-warning">{{ k('nobody_assigned') }}</span>
        </VCardTitle>
        <VCardSubtitle>
          {{ k('employees_count', { count: team.members.length }) }}
          <template v-if="!team.manager_name"> {{ k('no_one_to_go_to') }}</template>
        </VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VTable density="compact">
        <tbody>
          <tr v-for="m in team.members" :key="m.uuid">
            <td style="width:56px">
              <VCheckbox v-model="selected" :value="m.uuid" hide-details density="compact" :aria-label="`Select ${m.name}`" />
            </td>
            <td>{{ m.name }}</td>
            <td class="text-medium-emphasis">{{ m.number }}</td>
            <td class="text-medium-emphasis">{{ m.occupation }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Sticks to the bottom while the officer scrolls a long register picking people. -->
    <VCard v-if="selected.length" variant="flat" color="primary" class="pa-3 d-flex flex-wrap align-center ga-3" style="position:sticky;bottom:1rem">
      <span class="font-weight-medium">{{ k('selected', { count: selected.length }) }}</span>
      <VSpacer />
      <VAutocomplete
        v-model="newManager"
        :items="managerOptions"
        :label="k('now_reports_to')"
        density="compact"
        variant="solo"
        hide-details
        clearable
        style="min-width:18rem;max-width:24rem"
      />
      <VBtn variant="text" @click="selected = []">{{ u('clear') }}</VBtn>
      <VBtn color="white" variant="flat" class="text-primary" @click="confirming = true">{{ k('reassign') }}</VBtn>
    </VCard>

    <!-- Confirmed rather than applied straight away: this decides where every one of these people's
         leave requests goes from now on, and getting it wrong is quiet until somebody's leave sits
         unapproved for a fortnight. -->
    <VDialog v-model="confirming" max-width="560" :persistent="saving">
      <VCard>
        <VCardItem>
          <template #prepend>
            <VAvatar color="warning" variant="tonal" size="40"><VIcon icon="tabler-users-group" /></VAvatar>
          </template>
          <VCardTitle>{{ k('move_title', { count: selected.length }) }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 mb-3">
            <template v-if="newManager">{{ k('move_body', { manager: newManagerName }) }}</template>
            <template v-else>{{ k('move_body_none') }}</template>
          </p>
          <VAlert v-if="!newManager" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ k('unassigned_warning') }}
          </VAlert>
          <div class="text-caption text-medium-emphasis mb-1">{{ k('moving') }}</div>
          <div class="d-flex flex-wrap ga-1" style="max-height:11rem;overflow-y:auto">
            <VChip v-for="m in selectedNames" :key="m.uuid" size="small" variant="tonal" label>{{ m.name }}</VChip>
          </div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="text" :disabled="saving" @click="confirming = false">{{ u('go_back') }}</VBtn>
          <VBtn color="warning" variant="flat" :loading="saving" @click="apply">{{ k('yes_reassign') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
