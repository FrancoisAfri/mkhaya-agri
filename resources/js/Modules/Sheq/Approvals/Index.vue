<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Awaiting = {
  uuid: string; subject_type: string; subject_id: number; subject_label: string | null
  step_name: string; position: number; total_steps: number
  requires_signature: boolean; due_at: string | null; overdue: boolean; created_at: string
}

const props = defineProps<{
  awaiting: Awaiting[]
  raised: Array<Record<string, any>>
  noCompany?: boolean
}>()

const form = useForm({ decision: '', note: '', signature: '' })
const open = ref<string | null>(null)

/** The pending item the decision dialog is about, or null when it is closed. */
const openItem = computed(() => props.awaiting.find(i => i.uuid === open.value) ?? null)

// A reason belongs to the approval it was typed against. Clearing on close is what stops one
// item's wording being submitted as another's.
watch(open, () => form.reset())

/** Overdue first: a queue sorted by arrival buries the thing that is already late. */
const queue = computed(() =>
  [...props.awaiting].sort((a, b) => Number(b.overdue) - Number(a.overdue)))

const overdueCount = computed(() => props.awaiting.filter(a => a.overdue).length)

const submit = (uuid: string, decision: 'APPROVED' | 'REJECTED') => {
  form.decision = decision
  form.post(`/sheq/approvals/${uuid}/decide`, {
    preserveScroll: true,
    onSuccess: () => { form.reset(); open.value = null },
  })
}

const subjectLabel = (type: string) =>
  t(`sheq.approvals.subjects.${type.toLowerCase()}`, type.replace(/_/g, ' ').toLowerCase())

const statusColour = (s: string) =>
  ({ APPROVED: 'success', REJECTED: 'error', CANCELLED: 'grey', PENDING: 'warning' }[s] ?? 'grey')

const formatDate = (v: string | null) => {
  if (!v) return '—'
  try { return d(new Date(v), 'short') } catch { return v }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.approvals.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.approvals.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="overdueCount" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite"
      >
        {{ overdueCount }} {{ t('sheq.approvals.overdue').toLowerCase() }}
      </VAlert>

      <VRow>
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.approvals.waiting') }}
              <VChip v-if="queue.length" size="small" class="ms-2" variant="tonal">
                {{ queue.length }}
              </VChip>
            </VCardTitle>
            <VDivider />

            <div v-if="!queue.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-check-all" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.approvals.none_waiting') }}</div>
            </div>

            <div v-for="item in queue" :key="item.uuid" class="border-b">
              <div
                class="pa-4 d-flex align-start ga-3 row-hover"
                role="button" tabindex="0" :aria-expanded="open === item.uuid"
                @click="open = open === item.uuid ? null : item.uuid"
                @keydown.enter.prevent="open = open === item.uuid ? null : item.uuid"
                @keydown.space.prevent="open = open === item.uuid ? null : item.uuid"
              >
                <VIcon
                  :icon="item.overdue ? 'mdi-clock-alert-outline' : 'mdi-check-decagram-outline'"
                  :color="item.overdue ? 'warning' : 'grey'" size="20" class="mt-1"
                />

                <div class="flex-grow-1 min-width-0">
                  <div class="d-flex align-center flex-wrap ga-2">
                    <span class="text-body-1 font-weight-medium">
                      {{ item.subject_label || subjectLabel(item.subject_type) }}
                    </span>
                    <VChip size="x-small" variant="tonal">
                      {{ subjectLabel(item.subject_type) }}
                    </VChip>
                    <!-- Colour is never the only signal. -->
                    <VChip v-if="item.overdue" size="x-small" color="warning" variant="tonal">
                      {{ t('sheq.approvals.overdue') }}
                    </VChip>
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ item.step_name }} ·
                    {{ t('sheq.approvals.step', { n: item.position, total: item.total_steps }) }}
                    <template v-if="item.due_at"> · {{ formatDate(item.due_at) }}</template>
                  </div>
                </div>

                <VIcon
                  :icon="open === item.uuid ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  size="20" class="mt-1"
                />
              </div>

            </div>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.approvals.mine') }}
            </VCardTitle>
            <VDivider />

            <div v-if="!raised.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">{{ t('sheq.approvals.none_mine') }}</div>
            </div>

            <VTable v-else density="compact">
              <tbody>
                <tr v-for="r in raised" :key="r.uuid">
                  <td class="text-body-2">{{ r.subject_label || r.subject_type }}</td>
                  <td style="width: 110px">
                    <VChip size="x-small" :color="statusColour(r.status)" variant="tonal">
                      {{ r.status }}
                    </VChip>
                  </td>
                  <td class="text-caption text-medium-emphasis" style="width: 110px">
                    {{ formatDate(r.created_at) }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>

      <!--
        One decision dialog for the whole queue.

        Folded into each row it pushed every item below it down the page as the note grew, and an
        approver scrolling to find the row they had just opened is an approver who stops reading
        before deciding. `openItem` is the row; closing clears the note, so a reason typed against
        one approval can never be submitted against the next.
      -->
      <VDialog :model-value="openItem !== null" max-width="720" scrollable
               @update:model-value="open = null">
        <VCard v-if="openItem">
          <VCardTitle class="text-body-1 font-weight-semibold">
            {{ openItem.subject_label || subjectLabel(openItem.subject_type) }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <VTextarea
              v-model="form.note" rows="3" auto-grow variant="outlined" density="comfortable"
              :label="t('sheq.approvals.note')"
            />

            <VAlert
              v-if="openItem.requires_signature" type="info" variant="tonal"
              density="compact" class="mt-3"
            >
              {{ t('sheq.approvals.sign') }}
            </VAlert>

            <p v-if="!form.note.trim()" class="text-caption text-medium-emphasis mt-2 mb-0">
              {{ t('sheq.ai.reject_needs_note') }}
            </p>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4 pb-4">
            <VBtn variant="text" @click="open = null">{{ t('sheq.action.cancel') }}</VBtn>
            <VSpacer />
            <VBtn
              color="error" variant="outlined"
              :disabled="form.processing || !form.note.trim()"
              @click="submit(openItem.uuid, 'REJECTED')"
            >
              {{ t('sheq.approvals.reject') }}
            </VBtn>
            <VBtn
              color="success" variant="flat"
              :loading="form.processing && form.decision === 'APPROVED'"
              :disabled="form.processing"
              @click="submit(openItem.uuid, 'APPROVED')"
            >
              {{ t('sheq.approvals.approve') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.row-hover:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.min-width-0 { min-width: 0; }

@media (prefers-reduced-motion: reduce) {
  .row-hover { transition: none; }
}
</style>
