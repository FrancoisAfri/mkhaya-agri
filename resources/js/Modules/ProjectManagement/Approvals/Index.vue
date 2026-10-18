<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; subject: string; label: string | null
  project: { id: string; code: string; name: string } | null
  amount: number | null; currency: string | null
  status: string; note: string | null; reason: string | null
  step: string | null; position: number; steps: number; flow: string | null
  waiting: number; created_at: string | null
  can_act: boolean; can_cancel: boolean
}

const props = defineProps<{
  inbox: Row[]
  mine: Row[]
  tab: string
  types: string[]
}>()

const tab = ref(props.tab)
const confirmer = ref()

const statusColor: Record<string, string> = {
  pending: 'warning', approved: 'success', rejected: 'error', cancelled: 'grey',
}

const subjectIcon: Record<string, string> = {
  timesheet: 'tabler-clock',
  expense: 'tabler-receipt',
  document: 'tabler-file',
  project_stage: 'tabler-flag',
}

function money(row: Row) {
  if (row.amount === null) return null
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: row.currency || 'ZAR' }).format(row.amount)
  } catch {
    return `${row.currency ?? ''} ${row.amount.toFixed(2)}`
  }
}

/**
 * How long something has been sitting, in words.
 *
 * The number is what makes the queue self-policing: "3 days" beside a request is the thing that
 * gets it looked at, where a timestamp is just another column.
 */
function waited(hours: number) {
  if (hours < 1) return t('project_management.approvals.waited_under_hour')
  if (hours < 24) return t('project_management.approvals.waited_hours', { count: hours })
  return t('project_management.approvals.waited_days', { count: Math.floor(hours / 24) })
}

function waitColor(hours: number) {
  if (hours >= 72) return 'error'
  if (hours >= 24) return 'warning'
  return 'grey'
}

// ── Deciding ─────────────────────────────────────────────────────────────────

const approving = ref<string | null>(null)

function approve(row: Row) {
  confirmer.value.open({
    title: t('project_management.approvals.confirm_approve_title'),
    message: t('project_management.approvals.confirm_approve_body', { label: row.label ?? '—' }),
    confirmText: t('project_management.approvals.approve'),
    confirmColor: 'success',
    icon: 'tabler-check',
  }, () => {
    approving.value = row.id
    router.post(route('project-management.approvals.approve', row.id), {}, {
      preserveScroll: true,
      onFinish: () => { approving.value = null },
    })
  })
}

const rejectDialog = ref(false)
const rejecting = ref<Row | null>(null)
const rejectForm = useForm({ reason: '' })

function openReject(row: Row) {
  rejecting.value = row
  rejectForm.reset()
  rejectDialog.value = true
}

function saveReject() {
  rejectForm.post(route('project-management.approvals.reject', rejecting.value!.id), {
    preserveScroll: true,
    onSuccess: () => { rejectDialog.value = false },
  })
}

function withdraw(row: Row) {
  confirmer.value.open({
    title: t('project_management.approvals.confirm_withdraw_title'),
    message: t('project_management.approvals.confirm_withdraw_body'),
    confirmText: t('project_management.approvals.withdraw'),
    confirmColor: 'warning',
    icon: 'tabler-arrow-back-up',
  }, () => router.post(route('project-management.approvals.cancel', row.id), {}, { preserveScroll: true }))
}

const pendingMine = computed(() => props.mine.filter(r => r.status === 'pending').length)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.approvals.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.approvals.subtitle') }}</p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="inbox">
        {{ t('project_management.approvals.tab_inbox') }}
        <VChip v-if="inbox.length" size="x-small" color="warning" class="ms-2" label>{{ inbox.length }}</VChip>
      </VTab>
      <VTab value="mine">
        {{ t('project_management.approvals.tab_mine') }}
        <VChip v-if="pendingMine" size="x-small" class="ms-2" label>{{ pendingMine }}</VChip>
      </VTab>
    </VTabs>

    <!-- Waiting on me ------------------------------------------------------ -->
    <VCard v-if="tab === 'inbox'" border>
      <VList v-if="inbox.length" lines="two">
        <template v-for="(row, i) in inbox" :key="row.id">
          <VDivider v-if="i" />
          <VListItem>
            <template #prepend>
              <VAvatar :color="waitColor(row.waiting)" variant="tonal" rounded size="40" class="me-3">
                <VIcon :icon="subjectIcon[row.subject] ?? 'tabler-file-check'" />
              </VAvatar>
            </template>

            <VListItemTitle class="d-flex align-center flex-wrap gap-2">
              <span>{{ row.label ?? t('project_management.approvals.untitled') }}</span>
              <VChip size="x-small" label>{{ t(`project_management.approvals.subject_${row.subject}`) }}</VChip>
              <VChip v-if="money(row)" size="x-small" color="primary" label>{{ money(row) }}</VChip>
            </VListItemTitle>

            <VListItemSubtitle class="text-caption">
              <template v-if="row.project">{{ row.project.code }} · {{ row.project.name }} — </template>
              <span :class="`text-${waitColor(row.waiting)}`">{{ waited(row.waiting) }}</span>
              <template v-if="row.step">
                · {{ t('project_management.approvals.step_of', { step: row.step, n: row.position, total: row.steps }) }}
              </template>
              <template v-if="row.note"> · {{ row.note }}</template>
            </VListItemSubtitle>

            <template #append>
              <div class="d-flex gap-2">
                <VBtn
                  color="success" variant="tonal" size="small" prepend-icon="tabler-check"
                  :loading="approving === row.id" @click="approve(row)"
                >
                  {{ t('project_management.approvals.approve') }}
                </VBtn>
                <VBtn color="error" variant="text" size="small" prepend-icon="tabler-x" @click="openReject(row)">
                  {{ t('project_management.approvals.reject') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </template>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        <VIcon icon="tabler-inbox" size="36" class="mb-2 d-block mx-auto" />
        {{ t('project_management.approvals.inbox_empty') }}
      </VCardText>
    </VCard>

    <!-- What I submitted --------------------------------------------------- -->
    <VCard v-else border>
      <VTable v-if="mine.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.approvals.col_what') }}</th>
            <th>{{ t('project_management.approvals.col_project') }}</th>
            <th class="text-right">{{ t('project_management.approvals.col_amount') }}</th>
            <th>{{ t('project_management.approvals.col_status') }}</th>
            <th>{{ t('project_management.approvals.col_with') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in mine" :key="row.id">
            <td>
              {{ row.label ?? t('project_management.approvals.untitled') }}
              <div class="text-caption text-medium-emphasis">
                {{ t(`project_management.approvals.subject_${row.subject}`) }}
              </div>
              <div v-if="row.reason" class="text-caption text-error">{{ row.reason }}</div>
            </td>
            <td class="text-caption">{{ row.project ? `${row.project.code}` : '—' }}</td>
            <td class="text-right">{{ money(row) ?? '—' }}</td>
            <td>
              <VChip size="x-small" label :color="statusColor[row.status] ?? 'grey'">
                {{ t(`project_management.approvals.status_${row.status}`) }}
              </VChip>
            </td>
            <td class="text-caption">
              <template v-if="row.status === 'pending'">
                {{ row.step ?? t('project_management.approvals.default_step') }} · {{ waited(row.waiting) }}
              </template>
              <template v-else>—</template>
            </td>
            <td class="text-right">
              <VBtn
                v-if="row.can_cancel" size="small" variant="text" icon="tabler-arrow-back-up"
                :title="t('project_management.approvals.withdraw')" @click="withdraw(row)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        {{ t('project_management.approvals.mine_empty') }}
      </VCardText>
    </VCard>

    <VDialog v-model="rejectDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.approvals.reject') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ rejecting?.label }}</p>
          <VTextarea
            v-model="rejectForm.reason" rows="3" autofocus
            :label="t('project_management.approvals.reject_reason')"
            :hint="t('project_management.approvals.reject_reason_hint')" persistent-hint
            :error-messages="rejectForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="saveReject">
            {{ t('project_management.approvals.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
