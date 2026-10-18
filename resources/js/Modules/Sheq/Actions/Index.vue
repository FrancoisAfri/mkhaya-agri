<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Action = {
  uuid: string; reference: string; title: string; type: string; source: string
  hierarchy_level: string | null; priority: string; due_date: string; days_remaining: number
  extension_count: number; status: string; is_overdue: boolean; is_mine: boolean
  assignee: string | null; site_name: string | null
  effectiveness_review_due: string | null; effectiveness_result: string | null
  evidence_required: boolean; needs_independent_verification: boolean
}

const props = defineProps<{
  actions: Action[]
  summary: { mine: number; overdue: number; awaiting_verification: number; weak_controls: number }
  noCompany?: boolean
  can: { complete: boolean; verify: boolean }
}>()

/** SHEQ-CAP-1: one list regardless of source. The filters slice it; they never split it. */
const filter = ref<'mine' | 'open' | 'verification' | 'all'>('mine')

const filtered = computed(() => props.actions.filter(a => {
  if (filter.value === 'all') return true
  if (filter.value === 'verification') return a.status === 'PENDING_VERIFICATION'
  if (filter.value === 'mine') return a.is_mine && ['OPEN', 'IN_PROGRESS'].includes(a.status)
  return ['OPEN', 'IN_PROGRESS'].includes(a.status)
}))

const extending = ref<Action | null>(null)
const extendForm = useForm({ due_date: '', reason: '' })
const completeForm = useForm({ notes: '' })
const verifyForm = useForm({ notes: '' })

function complete(a: Action) {
  completeForm.post(`/sheq/actions/${a.uuid}/complete`, { preserveScroll: true })
}

function verify(a: Action) {
  verifyForm.post(`/sheq/actions/${a.uuid}/verify`, { preserveScroll: true })
}

function submitExtend() {
  if (!extending.value) return
  extendForm.post(`/sheq/actions/${extending.value.uuid}/extend`, {
    preserveScroll: true,
    onSuccess: () => { extending.value = null; extendForm.reset() },
  })
}

const priorityColour: Record<string, string> = {
  CRITICAL: 'error', HIGH: 'deep-orange', MEDIUM: 'warning', LOW: 'grey',
}

/** Administrative and PPE sit at the bottom of the hierarchy of control — worth showing as weak. */
const isWeakControl = (level: string | null) => level === 'ADMINISTRATIVE' || level === 'PPE'
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.action.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.action.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.action.mine') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.mine }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg" :color="summary.overdue ? 'error' : undefined"
               :variant="summary.overdue ? 'tonal' : 'elevated'"><VCardText>
          <div class="text-caption">{{ t('sheq.action.overdue') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.overdue }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.action.awaiting_verification') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.awaiting_verification }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.action.weak_controls') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.weak_controls }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.action.weak_controls_hint') }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VBtnToggle v-model="filter" mandatory density="compact" variant="outlined" class="mb-3">
      <VBtn value="mine" size="small">{{ t('sheq.action.filter_mine') }}</VBtn>
      <VBtn value="open" size="small">{{ t('sheq.obligation.filter_open') }}</VBtn>
      <VBtn value="verification" size="small">{{ t('sheq.action.filter_verification') }}</VBtn>
      <VBtn value="all" size="small">{{ t('sheq.obligation.filter_all') }}</VBtn>
    </VBtnToggle>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.action.action') }}</th>
            <th scope="col">{{ t('sheq.action.source') }}</th>
            <th scope="col">{{ t('sheq.action.control') }}</th>
            <th scope="col">{{ t('sheq.action.priority') }}</th>
            <th scope="col">{{ t('sheq.action.due') }}</th>
            <th scope="col">{{ t('sheq.incident.status') }}</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in filtered" :key="a.uuid">
            <td>
              <div class="text-body-2 font-weight-medium">{{ a.title }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ a.reference }}<span v-if="a.site_name"> · {{ a.site_name }}</span>
              </div>
            </td>
            <td><VChip size="x-small" variant="tonal">{{ a.source }}</VChip></td>
            <td>
              <VChip v-if="a.hierarchy_level" size="x-small" variant="tonal"
                     :color="isWeakControl(a.hierarchy_level) ? 'warning' : 'success'">
                {{ a.hierarchy_level }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td><VChip :color="priorityColour[a.priority]" size="x-small" variant="tonal">{{ a.priority }}</VChip></td>
            <td class="text-body-2">
              {{ a.due_date }}
              <div class="text-caption" :class="a.is_overdue ? 'text-error font-weight-bold' : 'text-medium-emphasis'">
                <template v-if="a.is_overdue">{{ t('sheq.action.days_overdue', { count: Math.abs(a.days_remaining) }) }}</template>
                <template v-else-if="['OPEN', 'IN_PROGRESS'].includes(a.status)">
                  {{ t('sheq.action.days_left', { count: a.days_remaining }) }}
                </template>
                <!-- SHEQ-CAP-4: extensions are visible on the record, not hidden in an audit log. -->
                <span v-if="a.extension_count" class="ms-1">
                  · {{ t('sheq.action.extended_times', { count: a.extension_count }) }}
                </span>
              </div>
            </td>
            <td>
              <VChip size="x-small" variant="tonal"
                     :color="a.status === 'VERIFIED_CLOSED' ? 'success'
                            : a.status === 'PENDING_VERIFICATION' ? 'info'
                            : a.is_overdue ? 'error' : 'warning'">
                {{ a.status }}
              </VChip>
              <div v-if="a.effectiveness_result" class="text-caption text-medium-emphasis mt-1">
                {{ a.effectiveness_result }}
              </div>
            </td>
            <td class="text-end" style="white-space: nowrap">
              <VBtn v-if="can.complete && ['OPEN', 'IN_PROGRESS'].includes(a.status)"
                    size="x-small" variant="tonal" class="me-1" @click="complete(a)">
                {{ t('sheq.action.complete') }}
              </VBtn>
              <VBtn v-if="can.verify && a.status === 'PENDING_VERIFICATION'"
                    size="x-small" variant="tonal" color="success" class="me-1" @click="verify(a)">
                {{ t('sheq.action.verify') }}
              </VBtn>
              <VBtn v-if="can.complete && ['OPEN', 'IN_PROGRESS'].includes(a.status)"
                    size="x-small" variant="text" @click="extending = a">
                {{ t('sheq.action.extend') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="7" class="text-center py-10 text-medium-emphasis">{{ t('sheq.action.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Extension: reason is mandatory server-side (SHEQ-CAP-4), so the dialog asks for it plainly. -->
    <VDialog :model-value="!!extending" max-width="620" @update:model-value="extending = null">
      <VCard rounded="lg">
        <VCardTitle class="pt-5 px-5">{{ t('sheq.action.extend_title') }}</VCardTitle>
        <VCardText class="px-5">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ extending?.title }}</p>
          <VTextField v-model="extendForm.due_date" type="date" :label="t('sheq.action.new_due')"
                      :error-messages="extendForm.errors.due_date"
                      variant="outlined" density="comfortable" class="mb-3" />
          <VTextarea v-model="extendForm.reason" rows="3" :label="t('sheq.action.extend_reason')"
                     :error-messages="extendForm.errors.reason || extendForm.errors.extend"
                     :hint="t('sheq.action.extend_reason_hint')" persistent-hint variant="outlined" />
        </VCardText>
        <VCardActions class="px-5 pb-5 justify-end gap-2">
          <VBtn variant="tonal" @click="extending = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="extendForm.processing" @click="submitExtend">
            {{ t('sheq.action.extend') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
