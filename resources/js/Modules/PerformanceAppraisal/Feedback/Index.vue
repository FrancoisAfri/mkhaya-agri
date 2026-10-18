
<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface FeedbackItem {
  id: number
  feedback_type: string
  content: string
  rating: number | null
  is_anonymous: boolean
  author_name: string
  cycle_name: string | null
  acknowledged_at: string | null
  created_at: string
}

interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{
  feedback: FeedbackItem[]
  cycles: Cycle[]
  employees: Employee[]
  selectedEmployee: number
  selectedCycle: number | null
}>()

// Filters
const selectedEmployee = ref<number>(props.selectedEmployee)
const selectedCycle    = ref<number | null>(props.selectedCycle)

function applyFilter() {
  router.get(route('performance.feedback.index'), {
    employee_id: selectedEmployee.value ?? undefined,
    cycle_id:    selectedCycle.value    ?? undefined,
  }, { preserveState: true, replace: true })
}

// AI Summary
const aiSummary      = ref('')
const summaryLoading = ref(false)

async function loadSummary() {
  if (!selectedEmployee.value) return
  summaryLoading.value = true
  try {
    const { data } = await axios.post(route('performance.feedback.summary'), {
      employee_id: selectedEmployee.value,
      cycle_id:    selectedCycle.value,
    })
    aiSummary.value = data.summary
  } finally {
    summaryLoading.value = false
  }
}

// Create
const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()
const form = reactive({
  employee_id:   null as number | null,
  feedback_type: '',
  cycle_id:      null as number | null,
  content:       '',
  rating:        null as number | null,
  is_anonymous:  false,
  is_draft:      false,
})

function openCreate() {
  form.employee_id   = null
  form.feedback_type = ''
  form.cycle_id      = null
  form.content       = ''
  form.rating        = null
  form.is_anonymous  = false
  form.is_draft      = false
  createOpen.value   = true
}

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.feedback.store'), form as any, {
    onSuccess: () => { createOpen.value = false },
    onFinish:  () => { saving.value = false },
  })
}

// Delete
const delOpen  = ref(false)
const delItem  = ref<FeedbackItem | null>(null)
const deleting = ref(false)

function openDelete(item: FeedbackItem) {
  delItem.value = item
  delOpen.value = true
}

function confirmDelete() {
  if (!delItem.value) return
  deleting.value = true
  router.delete(route('performance.feedback.destroy', delItem.value.id), {
    onSuccess: () => { delOpen.value = false },
    onFinish:  () => { deleting.value = false },
  })
}

// Acknowledge
function acknowledge(id: number) {
  router.patch(route('performance.feedback.acknowledge', id))
}

// Helpers — réactif avec computed
const feedbackTypes = computed(() => [
  { value: 'manager',  label: t('perfomance_feedback.feedback_types.manager') },
  { value: 'peer',     label: t('perfomance_feedback.feedback_types.peer') },
  { value: 'upward',   label: t('perfomance_feedback.feedback_types.upward') },
  { value: 'self',     label: t('perfomance_feedback.feedback_types.self') },
])

function typeLabel(type: string) {
  return feedbackTypes.value.find(ft => ft.value === type)?.label ?? type
}

function typeIcon(type: string): string {
  const map: Record<string, string> = {
    manager: 'tabler-user-tie',
    peer:    'tabler-users',
    upward:  'tabler-circle-arrow-up',
    self:    'tabler-mirror',
  }
  return map[type] ?? 'tabler-message'
}

function typeColor(type: string): string {
  const map: Record<string, string> = {
    manager: 'blue',
    peer:    'green',
    upward:  'orange',
    self:    'purple',
  }
  return map[type] ?? 'grey'
}
</script>
<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.feedback.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.feedback.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('pa.feedback.give_feedback') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VRow class="mb-4" dense>
      <VCol cols="12" sm="4">
        <VSelect
          v-model="selectedEmployee"
          :items="employees"
          item-title="name"
          item-value="id"
          :label="t('pa.common.employee')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          @update:model-value="applyFilter"
        />
      </VCol>
      <VCol cols="12" sm="4">
        <VSelect
          v-model="selectedCycle"
          :items="cycles"
          item-title="name"
          item-value="id"
          :label="t('pa.analytics.review_cycle')"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          @update:model-value="applyFilter"
        />
      </VCol>
      <VCol cols="12" sm="4" class="d-flex align-center">
        <VBtn
          variant="tonal"
          prepend-icon="tabler-robot"
          :loading="summaryLoading"
          @click="loadSummary"
        >
          {{ t('pa.feedback.ai_summary') }}
        </VBtn>
      </VCol>
    </VRow>

    <!-- AI Summary -->
    <VExpandTransition>
      <VAlert
        v-if="aiSummary"
        type="info"
        variant="tonal"
        class="mb-4"
        closable
        @click:close="aiSummary = ''"
      >
        <p class="text-body-2 font-weight-bold mb-1">{{ t('pa.feedback.ai_summary') }}</p>
        <p class="text-body-2" style="white-space: pre-wrap">{{ aiSummary }}</p>
      </VAlert>
    </VExpandTransition>

    <!-- Feedback list -->
    <VRow v-if="feedback.length" dense>
      <VCol v-for="item in feedback" :key="item.id" cols="12" sm="6" lg="4">
        <VCard border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar :color="typeColor(item.feedback_type)" variant="tonal" size="38">
                <VIcon size="18">{{ typeIcon(item.feedback_type) }}</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-medium">
              {{ typeLabel(item.feedback_type) }}
            </VCardTitle>
            <VCardSubtitle>
              {{ item.is_anonymous ? t('pa.feedback.anonymous') : item.author_name }}
              <span v-if="item.cycle_name"> · {{ item.cycle_name }}</span>
            </VCardSubtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="success"
                  :disabled="!!item.acknowledged_at"
                  @click="acknowledge(item.id)"
                >
                  <VIcon>tabler-circle-check</VIcon>
                  <VTooltip activator="parent" location="top">
                    {{ item.acknowledged_at ? t('perfomance_feedback.acknowledged_tooltip', { date: item.acknowledged_at }) : t('perfomance_feedback.acknowledge_tooltip') }}
                  </VTooltip>
                </VBtn>
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="openDelete(item)"
                >
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VCardItem>

          <VDivider />

          <VCardText>
            <p class="text-body-2">{{ item.content }}</p>
            <div v-if="item.rating !== null" class="mt-2 d-flex align-center ga-1">
              <VIcon size="14" color="amber">tabler-star</VIcon>
              <span class="text-caption font-weight-medium">{{ item.rating }}/5</span>
            </div>
          </VCardText>

          <VCardActions class="px-4 pb-3">
            <span class="text-caption text-medium-emphasis">{{ item.created_at }}</span>
            <VChip
              v-if="item.acknowledged_at"
              size="x-small"
              color="success"
              variant="tonal"
              class="ml-auto"
            >
              {{ t('pa.feedback.acknowledged') }}
            </VChip>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-else type="info" variant="tonal" class="mt-4">{{ t('pa.feedback.empty') }}</VAlert>

    <!-- Create drawer -->
    <VNavigationDrawer
      v-model="createOpen"
      location="right"
      width="480"
      temporary
    >
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.feedback.give_feedback') }}</VToolbarTitle>
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
          :label="t('pa.feedback.about_employee')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || t('perfomance_feedback.required')]"
          class="mb-3"
        />
        <VSelect
          v-model="form.feedback_type"
          :items="feedbackTypes"
          item-title="label"
          item-value="value"
          :label="t('perfomance_feedback.feedback_type')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || t('perfomance_feedback.required')]"
          class="mb-3"
        />
        <VSelect
          v-model="form.cycle_id"
          :items="cycles"
          item-title="name"
          item-value="id"
          :label="t('pa.development.cycle_optional')"
          density="compact"
          variant="outlined"
          clearable
          class="mb-3"
        />
        <VTextarea
          v-model="form.content"
          :label="t('pa.feedback.feedback_label')"
          density="compact"
          variant="outlined"
          :rules="[v => (v && v.length >= 20) || t('perfomance_feedback.min_chars')]"
          rows="5"
          class="mb-3"
        />
        <VSlider
          v-model="form.rating"
          :label="t('pa.feedback.rating_optional')"
          min="0"
          max="5"
          step="0.5"
          thumb-label
          color="amber"
          class="mb-3"
        />
        <VSwitch
          v-model="form.is_anonymous"
          :label="t('pa.feedback.submit_anonymously')"
          density="compact"
          color="primary"
          class="mb-3"
        />
        <VSwitch
          v-model="form.is_draft"
          :label="t('pa.feedback.save_draft')"
          density="compact"
          color="warning"
          class="mb-4"
        />
        <VBtn type="submit" color="primary" block :loading="saving">
          {{ t('pa.feedback.submit') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Delete dialog -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.feedback.delete_title') }}</VCardTitle>
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


