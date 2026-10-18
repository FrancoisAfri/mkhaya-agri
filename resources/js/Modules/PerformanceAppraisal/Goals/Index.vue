<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Cycle = { id: number; name: string }
type Unit  = { id: number; name: string; type: string }
type Agent = { id: number; name: string }

type Goal = {
  id: number
  title: string
  description: string | null
  level: 'organizational' | 'department' | 'team'
  status: string
  target_value: number | null
  current_value: number | null
  unit: string | null
  progress_pct: number
  start_date: string | null
  end_date: string | null
  cycle: { id: number; name: string } | null
  owner_name: string
  kpis_count: number
  cycle_id: number | null
  owner_id: number | null
  owner_unit_id: number | null
}

const props = defineProps<{
  goals: Goal[]
  cycles: Cycle[]
  agents: Agent[]
  units: Unit[]
}>()

const drawer    = ref(false)
const delOpen   = ref(false)
const delItem   = ref<Goal | null>(null)
const editing   = ref<Partial<Goal> | null>(null)
const filterLevel = ref<string>('')

const filteredGoals = computed(() =>
  filterLevel.value ? props.goals.filter(g => g.level === filterLevel.value) : props.goals
)

function openDrawer(goal?: Goal) {
  editing.value = goal ? { ...goal } : {
    title: '', description: null, level: 'organizational', status: 'draft',
    target_value: null, unit: '%', current_value: null,
    start_date: null, end_date: null, cycle_id: null, owner_id: null, owner_unit_id: null,
  }
  drawer.value = true
}

function save() {
  if (!editing.value) return
  const g = editing.value
  if (g.id) {
    router.patch(route('performance.goals.update', g.id), g, { onSuccess: () => { drawer.value = false } })
  } else {
    router.post(route('performance.goals.store'), g, { onSuccess: () => { drawer.value = false } })
  }
}

const statusColor = (s: string) => ({
  draft: 'default', active: 'primary', achieved: 'success', missed: 'error', cancelled: 'secondary'
})[s] ?? 'default'

const levelColor  = (l: string) => ({ organizational: 'deep-purple', department: 'blue', team: 'teal' })[l] ?? 'default'
const levels      = [
  { value: 'organizational', title: 'Organisational' },
  { value: 'department', title: 'Department' },
  { value: 'team', title: 'Team' },
]
const statuses = ['draft', 'active', 'achieved', 'missed', 'cancelled']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.goals.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.goals.subtitle') }}</p>
      </div>
      <VSpacer />
      <VSelect
        v-model="filterLevel"
        :items="[{ value: '', title: 'All Levels' }, ...levels]"
        item-title="title" item-value="value"
        density="compact" style="max-width:180px" class="mr-3"
      />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openDrawer()">{{ t('pa.goals.new') }}</VBtn>
    </div>

    <VRow>
      <VCol v-if="filteredGoals.length === 0" cols="12">
        <VCard class="text-center pa-8 text-medium-emphasis">{{ t('pa.goals.empty') }}</VCard>
      </VCol>
      <VCol v-for="goal in filteredGoals" :key="goal.id" cols="12" md="6" lg="4">
        <VCard class="h-100">
          <VCardTitle class="d-flex align-center gap-2 pb-1">
            <VChip size="x-small" :color="levelColor(goal.level)" label>{{ goal.level }}</VChip>
            <span class="text-subtitle-2 text-truncate flex-1">{{ goal.title }}</span>
          </VCardTitle>
          <VCardSubtitle class="d-flex align-center gap-2">
            <span>{{ goal.owner_name }}</span>
            <span v-if="goal.cycle" class="text-caption">· {{ goal.cycle.name }}</span>
          </VCardSubtitle>
          <VCardText>
            <p v-if="goal.description" class="text-body-2 text-medium-emphasis mb-3 text-truncate">{{ goal.description }}</p>

            <!-- Progress -->
            <div v-if="goal.target_value !== null" class="mb-2">
              <div class="d-flex justify-space-between text-caption mb-1">
                <span>{{ goal.current_value ?? 0 }} / {{ goal.target_value }} {{ goal.unit }}</span>
                <span class="font-weight-medium">{{ goal.progress_pct }}%</span>
              </div>
              <VProgressLinear
                :model-value="goal.progress_pct"
                :color="goal.progress_pct >= 100 ? 'success' : goal.progress_pct >= 60 ? 'primary' : 'warning'"
                rounded height="6"
              />
            </div>

            <div class="d-flex align-center gap-2 mt-2">
              <VChip size="x-small" :color="statusColor(goal.status)" label>{{ goal.status }}</VChip>
              <VChip size="x-small" variant="tonal">{{ goal.kpis_count }} KPI{{ goal.kpis_count !== 1 ? 's' : '' }}</VChip>
              <span v-if="goal.end_date" class="text-caption text-medium-emphasis ml-auto">{{ t('pa.goals.due') }} {{ goal.end_date }}</span>
            </div>
          </VCardText>
          <VCardActions>
            <VBtn size="small" variant="text" @click="openDrawer(goal)">{{ t('pa.common.edit') }}</VBtn>
            <VBtn size="small" variant="text" color="error" @click="delItem = goal; delOpen = true">{{ t('pa.common.delete') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- Create / Edit Drawer -->
    <VNavigationDrawer v-model="drawer" location="right" temporary width="520">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ editing?.id ? t('pa.goals.edit') : t('pa.goals.new') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="drawer = false" />
      </div>
      <div class="pa-4" v-if="editing">
        <VTextField v-model="editing.title" :label="t('pa.goals.goal_title')" class="mb-3" />
        <VTextField v-model="editing.description" :label="t('pa.cycles.description')" class="mb-3" />
        <VSelect v-model="editing.level" :items="levels" item-title="title" item-value="value" :label="t('pa.goals.level')" class="mb-3" />
        <VSelect v-model="editing.status" :items="statuses" :label="t('pa.common.status')" class="mb-3" />

        <VDivider class="my-3" />

        <VSelect v-model="editing.cycle_id" :items="props.cycles" item-title="name" item-value="id" :label="t('pa.goals.linked_cycle')" clearable class="mb-3" />
        <VSelect v-model="editing.owner_id" :items="props.agents" item-title="name" item-value="id" :label="t('pa.goals.owner_person')" clearable class="mb-3" />
        <VSelect v-model="editing.owner_unit_id" :items="props.units" item-title="name" item-value="id" :label="t('pa.goals.owner_unit')" clearable class="mb-3" />

        <VDivider class="my-3" />

        <VRow>
          <VCol cols="7">
            <VTextField v-model.number="editing.target_value" :label="t('pa.goals.target_value')" type="number" />
          </VCol>
          <VCol cols="5">
            <VTextField v-model="editing.unit" :label="t('pa.goals.unit_label')" />
          </VCol>
        </VRow>
        <VTextField v-model.number="editing.current_value" :label="t('pa.goals.current_value')" type="number" class="mb-3" />

        <VRow>
          <VCol cols="6"><VTextField v-model="editing.start_date" :label="t('pa.cycles.start_date')" type="date" /></VCol>
          <VCol cols="6"><VTextField v-model="editing.end_date" :label="t('pa.cycles.end_date')" type="date" /></VCol>
        </VRow>

        <div class="d-flex justify-end gap-2 mt-4">
          <VBtn variant="text" @click="drawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="save">{{ editing?.id ? t('pa.common.update') : t('pa.common.create') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Delete confirm -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard v-if="delItem">
        <VCardTitle>{{ t('pa.goals.delete_title') }}</VCardTitle>
        <VCardText>{{ t('pa.goals.delete_confirm') }} <strong>{{ delItem.title }}</strong>?</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" @click="router.delete(route('performance.goals.destroy', delItem.id), { onSuccess: () => { delOpen = false; delItem = null } })">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
