<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.probation.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.probation.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('pa.probation.new') }}
      </VBtn>
    </div>

    <!-- Stats strip -->
    <VRow dense class="mb-5">
      <VCol v-for="stat in stats" :key="stat.label" cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="`text-${stat.color}`">{{ stat.value }}</p>
          <p class="text-caption text-medium-emphasis">{{ stat.label }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- Review cards -->
    <VRow v-if="reviews.length" dense>
      <VCol v-for="r in reviews" :key="r.id" cols="12" sm="6" xl="4">
        <VCard border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar :color="riskColor(r.risk_level)" variant="tonal" size="40">
                <VIcon size="18">tabler-user-clock</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-medium">
              {{ r.employee_name }}
            </VCardTitle>
            <VCardSubtitle>
              {{ t('pa.common.manager') }}: {{ r.manager_name }}
            </VCardSubtitle>
            <template #append>
              <div class="d-flex flex-column align-end ga-1">
                <VChip size="x-small" :color="statusColor(r.status)" variant="tonal">
                  {{ r.status }}
                </VChip>
                <VChip
                  v-if="r.risk_level"
                  size="x-small"
                  :color="riskColor(r.risk_level)"
                  variant="flat"
                >
                  {{ r.risk_level }} {{ t('pa.probation.risk') }}
                </VChip>
              </div>
            </template>
          </VCardItem>

          <VCardText class="pb-1">
            <!-- Progress bar -->
            <div class="d-flex justify-space-between text-caption text-medium-emphasis mb-1">
              <span>{{ probationLabel(r.probation_type) }}</span>
              <span>{{ r.percent_elapsed }}% {{ t('pa.probation.elapsed') }}</span>
            </div>
            <VProgressLinear
              :model-value="r.percent_elapsed"
              :color="progressColor(r.percent_elapsed, r.risk_level)"
              height="6"
              rounded
              class="mb-2"
            />

            <!-- Risk score gauge if available -->
            <div v-if="r.risk_score !== null" class="d-flex align-center ga-2 mb-1">
              <span class="text-caption text-medium-emphasis">{{ t('pa.probation.risk_score') }}</span>
              <VProgressLinear
                :model-value="r.risk_score"
                :color="riskColor(r.risk_level)"
                height="6"
                rounded
                class="flex-grow-1"
              />
              <span class="text-caption font-weight-medium">{{ r.risk_score }}/100</span>
            </div>

            <div class="d-flex align-center ga-3 text-caption text-medium-emphasis mt-2">
              <span><VIcon size="12">tabler-target</VIcon> {{ r.objectives_count }} {{ t('pa.pip.tab_objectives') }}</span>
              <span><VIcon size="12">tabler-calendar-check</VIcon> {{ r.checkins_count }} {{ t('pa.checkins.title') }}</span>
              <span><VIcon size="12">tabler-message</VIcon> {{ r.feedback_count }} {{ t('pa.feedback.title') }}</span>
            </div>
          </VCardText>

          <VDivider />

          <VCardActions class="px-4 pb-3 pt-2">
            <div class="d-flex flex-column text-caption text-medium-emphasis">
              <span>{{ r.start_date }} → {{ r.end_date }}</span>
              <span v-if="r.days_remaining > 0">{{ r.days_remaining }} {{ t('pa.pip.days_remaining') }}</span>
              <span v-else class="text-error">{{ t('pa.pip.overdue') }}</span>
            </div>
            <VSpacer />
            <VBtn
              size="x-small"
              variant="tonal"
              color="primary"
              @click="router.visit(route('performance.probation.show', r.id))"
            >
              {{ t('pa.probation.manage') }}
            </VBtn>
            <VBtn
              size="x-small"
              icon
              variant="text"
              color="error"
              class="ml-1"
              @click="openDelete(r)"
            >
              <VIcon>tabler-trash</VIcon>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-else type="info" variant="tonal" class="mt-2">{{ t('pa.probation.empty') }}</VAlert>

    <!-- Create drawer -->
    <VNavigationDrawer v-model="createOpen" location="right" width="480" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.probation.new') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="createOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>

      <VForm ref="createForm" @submit.prevent="submitCreate" class="pa-4">
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
        />
        <VSelect
          v-model="form.manager_id"
          :items="employees"
          item-title="name"
          item-value="id"
          :label="t('pa.probation.direct_manager')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
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
        />
        <VSelect
          v-model="form.probation_type"
          :items="probationTypes"
          item-title="label"
          item-value="value"
          :label="t('pa.probation.probation_type')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VRow dense class="mb-3">
          <VCol cols="6">
            <VTextField
              v-model="form.start_date"
              :label="t('pa.cycles.start_date')"
              type="date"
              density="compact"
              variant="outlined"
              :rules="[v => !!v || 'Required']"
            />
          </VCol>
          <VCol cols="6">
            <VTextField
              v-model="form.end_date"
              :label="t('pa.cycles.end_date')"
              type="date"
              density="compact"
              variant="outlined"
              :rules="[v => !!v || 'Required']"
            />
          </VCol>
        </VRow>
        <VTextarea
          v-model="form.notes"
          :label="t('pa.kpis.notes_optional')"
          density="compact"
          variant="outlined"
          rows="3"
          class="mb-4"
        />
        <VBtn type="submit" color="primary" block :loading="saving">
          {{ t('pa.probation.create') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Delete dialog -->
    <VDialog v-model="delOpen" max-width="420">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.probation.delete_title') }}</VCardTitle>
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

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface Review {
  id: number; employee_name: string; manager_name: string; cycle_name: string | null
  probation_type: string; start_date: string; end_date: string; status: string
  recommendation: string | null; risk_score: number | null; risk_level: string | null
  success_probability: number | null; days_remaining: number; percent_elapsed: number
  objectives_count: number; checkins_count: number; feedback_count: number
}

interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{
  reviews:   Review[]
  cycles:    Cycle[]
  employees: Employee[]
}>()

// Stats
const stats = computed(() => [
  { label: 'Total Active',  value: props.reviews.filter(r => r.status === 'active').length,    color: 'primary' },
  { label: 'High Risk',     value: props.reviews.filter(r => ['high','critical'].includes(r.risk_level ?? '')).length, color: 'error' },
  { label: 'Confirmed',     value: props.reviews.filter(r => r.status === 'confirmed').length, color: 'success' },
  { label: 'Extended',      value: props.reviews.filter(r => r.status === 'extended').length,  color: 'warning' },
])

// Create
const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()

const form = reactive({
  employee_id:    null as number | null,
  manager_id:     null as number | null,
  cycle_id:       null as number | null,
  probation_type: '3_month',
  start_date:     '',
  end_date:       '',
  notes:          '',
})

function openCreate() {
  Object.assign(form, {
    employee_id: null, manager_id: null, cycle_id: null,
    probation_type: '3_month', start_date: '', end_date: '', notes: '',
  })
  createOpen.value = true
}

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.probation.store'), form as any, {
    onSuccess: () => { createOpen.value = false },
    onFinish:  () => { saving.value = false },
  })
}

// Delete
const delOpen  = ref(false)
const delItem  = ref<Review | null>(null)
const deleting = ref(false)

function openDelete(r: Review) { delItem.value = r; delOpen.value = true }
function confirmDelete() {
  if (!delItem.value) return
  deleting.value = true
  router.delete(route('performance.probation.destroy', delItem.value.id), {
    onSuccess: () => { delOpen.value = false },
    onFinish:  () => { deleting.value = false },
  })
}

// Helpers
const probationTypes = [
  { value: '3_month', label: '3-Month Probation' },
  { value: '6_month', label: '6-Month Probation' },
  { value: 'custom',  label: 'Custom Period' },
]

function probationLabel(type: string) {
  return probationTypes.find(t => t.value === type)?.label ?? type
}

function statusColor(status: string) {
  return { active: 'blue', extended: 'orange', confirmed: 'green', terminated: 'error' }[status] ?? 'grey'
}

function riskColor(level: string | null) {
  return { low: 'green', medium: 'orange', high: 'error', critical: 'deep-purple' }[level ?? ''] ?? 'grey'
}

function progressColor(pct: number, level: string | null) {
  if (level === 'critical' || level === 'high') return 'error'
  if (pct > 80) return 'warning'
  return 'primary'
}
</script>
