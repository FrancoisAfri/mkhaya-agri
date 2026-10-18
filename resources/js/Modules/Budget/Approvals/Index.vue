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
  budget: { id: number; code: string; name: string } | null
  amount: number | null; currency: string | null
  status: string; note: string | null; reason: string | null
  step: string | null; position: number; steps: number; flow: string | null
  waiting: number; created_at: string | null
  can_act: boolean; can_cancel: boolean
}

type Delegation = {
  id: string; from: string; to: string; from_id: number
  starts_on: string | null; ends_on: string | null
  subject: string | null; max_amount: number | null; reason: string | null
  in_force: boolean
}

const props = defineProps<{
  inbox: Row[]
  mine: Row[]
  tab: string
  types: string[]
  delegations: { given: Delegation[]; received: Delegation[] }
  people: Array<{ id: number; label: string }>
  currentUserId: number
  canManageOthers: boolean
}>()

const tab = ref(props.tab)
const confirmer = ref()

const statusColor: Record<string, string> = {
  pending: 'warning', approved: 'success', rejected: 'error', cancelled: 'grey',
}

const subjectIcon: Record<string, string> = {
  budget: 'tabler-wallet',
  adjustment: 'tabler-adjustments',
  override: 'tabler-shield-lock',
  reservation: 'tabler-lock',
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
  if (hours < 1) return t('budgets_approvals.waited_under_hour')
  if (hours < 24) return t('budgets_approvals.waited_hours', { count: hours })
  return t('budgets_approvals.waited_days', { count: Math.floor(hours / 24) })
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
    title: t('budgets_approvals.confirm_approve_title'),
    message: t('budgets_approvals.confirm_approve_body', { label: row.label ?? '—' }),
    confirmText: t('budgets_approvals.approve'),
    confirmColor: 'success',
    icon: 'tabler-check',
  }, () => {
    approving.value = row.id
    router.post(route('budgets.approvals.approve', row.id), {}, {
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
  rejectForm.post(route('budgets.approvals.reject', rejecting.value!.id), {
    preserveScroll: true,
    onSuccess: () => { rejectDialog.value = false },
  })
}

function withdraw(row: Row) {
  confirmer.value.open({
    title: t('budgets_approvals.confirm_withdraw_title'),
    message: t('budgets_approvals.confirm_withdraw_body'),
    confirmText: t('budgets_approvals.withdraw'),
    confirmColor: 'warning',
    icon: 'tabler-arrow-back-up',
  }, () => router.post(route('budgets.approvals.cancel', row.id), {}, { preserveScroll: true }))
}

const pendingMine = computed(() => props.mine.filter(r => r.status === 'pending').length)

// ── Cover: handing your approvals to somebody while you are away ──────────────

const delegateDialog = ref(false)

const delegateForm = useForm<{
  from_user_id: number | null; to_user_id: number | null
  starts_on: string; ends_on: string
  subject_type: string | null; max_amount: number | null; reason: string
}>({
  from_user_id: null, to_user_id: null,
  starts_on: new Date().toISOString().slice(0, 10), ends_on: '',
  subject_type: null, max_amount: null, reason: '',
})

function openDelegate() {
  delegateForm.reset()
  delegateForm.clearErrors()
  // Arranging your own cover is the common case, so it is the default; an administrator can
  // change it to somebody else.
  delegateForm.from_user_id = props.currentUserId
  delegateDialog.value = true
}

function saveDelegate() {
  delegateForm
    .transform(data => ({ ...data, ends_on: data.ends_on || null }))
    .post(route('budgets.approvals.delegations.store'), {
      preserveScroll: true,
      onSuccess: () => { delegateDialog.value = false },
    })
}

function endDelegation(row: Delegation) {
  confirmer.value.open({
    title: t('budgets_approvals.confirm_end_delegation_title'),
    message: t('budgets_approvals.confirm_end_delegation_body', { to: row.to }),
    confirmText: t('budgets_approvals.end_delegation'),
    confirmColor: 'warning',
    icon: 'tabler-user-x',
  }, () => router.delete(route('budgets.approvals.delegations.destroy', row.id), { preserveScroll: true }))
}

/** A delegation in one line: who, until when, and any limit on it. */
function describeDelegation(row: Delegation) {
  const bits: string[] = []
  bits.push(row.ends_on
    ? t('budgets_approvals.delegation_dates', { from: row.starts_on, to: row.ends_on })
    : t('budgets_approvals.delegation_open', { from: row.starts_on }))

  if (row.subject) bits.push(t(`budgets_approvals.subject_${row.subject}`))
  if (row.max_amount !== null) bits.push(t('budgets_approvals.delegation_cap', { amount: row.max_amount }))

  return bits.join(' · ')
}

/** Live cover shown beside the inbox count — the answer to "why am I seeing this?". */
const coveringNow = computed(() => props.delegations.received.filter(d => d.in_force))

/** Money waiting on a decision — the number that gets a queue worked before it gets a reminder. */
const inboxValue = computed(() => {
  const total = props.inbox.reduce((sum, r) => sum + (r.amount ?? 0), 0)
  const currency = props.inbox.find(r => r.currency)?.currency ?? 'ZAR'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(total)
  } catch {
    return total.toFixed(2)
  }
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_approvals.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_approvals.subtitle') }}</p>
      </div>
      <VChip v-if="inbox.length" color="warning" variant="tonal" prepend-icon="tabler-coin">
        {{ t('budgets_approvals.value_waiting', { amount: inboxValue }) }}
      </VChip>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="inbox">
        {{ t('budgets_approvals.tab_inbox') }}
        <VChip v-if="inbox.length" size="x-small" color="warning" class="ms-2" label>{{ inbox.length }}</VChip>
      </VTab>
      <VTab value="mine">
        {{ t('budgets_approvals.tab_mine') }}
        <VChip v-if="pendingMine" size="x-small" class="ms-2" label>{{ pendingMine }}</VChip>
      </VTab>
      <VTab value="cover">
        {{ t('budgets_approvals.tab_cover') }}
        <VChip v-if="delegations.given.filter(d => d.in_force).length" size="x-small" color="info" class="ms-2" label>
          {{ delegations.given.filter(d => d.in_force).length }}
        </VChip>
      </VTab>
    </VTabs>

    <!--
      Says why an approval nobody assigned to them is in their queue. Without it a stand-in sees
      somebody else's work appear and assumes it is a bug.
    -->
    <VAlert v-if="tab === 'inbox' && coveringNow.length" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('budgets_approvals.covering_notice', { names: coveringNow.map(d => d.from).join(', ') }) }}
    </VAlert>

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
              <span>{{ row.label ?? t('budgets_approvals.untitled') }}</span>
              <VChip size="x-small" label>{{ t(`budgets_approvals.subject_${row.subject}`) }}</VChip>
              <VChip v-if="money(row)" size="x-small" color="primary" label>{{ money(row) }}</VChip>
            </VListItemTitle>

            <VListItemSubtitle class="text-caption">
              <template v-if="row.budget">{{ row.budget.code }} · {{ row.budget.name }} — </template>
              <span :class="`text-${waitColor(row.waiting)}`">{{ waited(row.waiting) }}</span>
              <template v-if="row.step">
                · {{ t('budgets_approvals.step_of', { step: row.step, n: row.position, total: row.steps }) }}
              </template>
              <template v-if="row.note"> · {{ row.note }}</template>
            </VListItemSubtitle>

            <template #append>
              <div class="d-flex gap-2">
                <VBtn
                  color="success" variant="tonal" size="small" prepend-icon="tabler-check"
                  :loading="approving === row.id" @click="approve(row)"
                >
                  {{ t('budgets_approvals.approve') }}
                </VBtn>
                <VBtn color="error" variant="text" size="small" prepend-icon="tabler-x" @click="openReject(row)">
                  {{ t('budgets_approvals.reject') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </template>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        <VIcon icon="tabler-inbox" size="36" class="mb-2 d-block mx-auto" />
        {{ t('budgets_approvals.inbox_empty') }}
      </VCardText>
    </VCard>

    <!-- Cover ---------------------------------------------------------------- -->
    <template v-else-if="tab === 'cover'">
      <VCard border class="mb-4">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_approvals.given_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_approvals.given_sub') }}</VCardSubtitle>
          <template #append>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-user-share" @click="openDelegate">
              {{ t('budgets_approvals.delegate') }}
            </VBtn>
          </template>
        </VCardItem>
        <VDivider />

        <VList v-if="delegations.given.length" density="compact">
          <VListItem v-for="row in delegations.given" :key="row.id" :class="row.in_force ? '' : 'text-medium-emphasis'">
            <VListItemTitle class="d-flex align-center gap-2">
              {{ row.to }}
              <VChip v-if="row.in_force" size="x-small" color="success" label>
                {{ t('budgets_approvals.in_force') }}
              </VChip>
              <VChip v-else size="x-small" label>{{ t('budgets_approvals.not_in_force') }}</VChip>
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ describeDelegation(row) }}
              <template v-if="row.reason"> — {{ row.reason }}</template>
            </VListItemSubtitle>
            <template #append>
              <VBtn size="small" variant="text" icon="tabler-user-x" color="error"
                    :title="t('budgets_approvals.end_delegation')" @click="endDelegation(row)" />
            </template>
          </VListItem>
        </VList>

        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('budgets_approvals.no_delegations') }}
        </VCardText>
      </VCard>

      <VCard border>
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_approvals.received_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_approvals.received_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />

        <VList v-if="delegations.received.length" density="compact">
          <VListItem v-for="row in delegations.received" :key="row.id" :class="row.in_force ? '' : 'text-medium-emphasis'">
            <VListItemTitle class="d-flex align-center gap-2">
              {{ row.from }}
              <VChip v-if="row.in_force" size="x-small" color="success" label>
                {{ t('budgets_approvals.in_force') }}
              </VChip>
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">{{ describeDelegation(row) }}</VListItemSubtitle>
          </VListItem>
        </VList>

        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('budgets_approvals.no_cover') }}
        </VCardText>
      </VCard>
    </template>

    <!-- What I submitted --------------------------------------------------- -->
    <VCard v-else border>
      <VTable v-if="mine.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('budgets_approvals.col_what') }}</th>
            <th>{{ t('budgets_approvals.col_budget') }}</th>
            <th class="text-right">{{ t('budgets_approvals.col_amount') }}</th>
            <th>{{ t('budgets_approvals.col_status') }}</th>
            <th>{{ t('budgets_approvals.col_with') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in mine" :key="row.id">
            <td>
              {{ row.label ?? t('budgets_approvals.untitled') }}
              <div class="text-caption text-medium-emphasis">
                {{ t(`budgets_approvals.subject_${row.subject}`) }}
              </div>
              <div v-if="row.reason" class="text-caption text-error">{{ row.reason }}</div>
            </td>
            <td class="text-caption">{{ row.budget ? row.budget.code : '—' }}</td>
            <td class="text-right">{{ money(row) ?? '—' }}</td>
            <td>
              <VChip size="x-small" label :color="statusColor[row.status] ?? 'grey'">
                {{ t(`budgets_approvals.status_${row.status}`) }}
              </VChip>
            </td>
            <td class="text-caption">
              <template v-if="row.status === 'pending'">
                {{ row.step ?? t('budgets_approvals.default_step') }} · {{ waited(row.waiting) }}
              </template>
              <template v-else>—</template>
            </td>
            <td class="text-right">
              <VBtn
                v-if="row.can_cancel" size="small" variant="text" icon="tabler-arrow-back-up"
                :title="t('budgets_approvals.withdraw')" @click="withdraw(row)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        {{ t('budgets_approvals.mine_empty') }}
      </VCardText>
    </VCard>

    <VDialog v-model="rejectDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('budgets_approvals.reject') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ rejecting?.label }}</p>
          <VTextarea
            v-model="rejectForm.reason" rows="3" autofocus
            :label="t('budgets_approvals.reject_reason')"
            :hint="t('budgets_approvals.reject_reason_hint')" persistent-hint
            :error-messages="rejectForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('budgets_approvals.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="saveReject">
            {{ t('budgets_approvals.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Hand over cover ------------------------------------------------------ -->
    <VDialog v-model="delegateDialog" max-width="620" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('budgets_approvals.delegate') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_approvals.delegate_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <!-- Only an administrator may arrange cover on somebody else's behalf; everybody else
                 is arranging their own, so the field is not worth showing them. -->
            <VCol v-if="canManageOthers" cols="12">
              <VAutocomplete
                v-model="delegateForm.from_user_id"
                :items="people.map(p => ({ title: p.label, value: p.id }))"
                :label="t('budgets_approvals.field_from_user')"
                :hint="t('budgets_approvals.hint_from_user')" persistent-hint
                :error-messages="delegateForm.errors.from_user_id"
                variant="outlined" density="compact" clearable
              />
            </VCol>

            <VCol cols="12">
              <VAutocomplete
                v-model="delegateForm.to_user_id"
                :items="people.map(p => ({ title: p.label, value: p.id }))"
                :label="t('budgets_approvals.field_to_user')"
                :error-messages="delegateForm.errors.to_user_id"
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="delegateForm.starts_on" type="date"
                :label="t('budgets_approvals.field_starts_on')"
                :error-messages="delegateForm.errors.starts_on"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="delegateForm.ends_on" type="date"
                :label="t('budgets_approvals.field_ends_on')"
                :hint="t('budgets_approvals.hint_ends_on')" persistent-hint
                :error-messages="delegateForm.errors.ends_on"
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="delegateForm.subject_type"
                :items="types.map(s => ({ title: t(`budgets_approvals.subject_${s}`), value: s }))"
                :label="t('budgets_approvals.field_subject_limit')"
                :hint="t('budgets_approvals.hint_subject_limit')" persistent-hint
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="delegateForm.max_amount" type="number"
                :label="t('budgets_approvals.field_max_amount')"
                :hint="t('budgets_approvals.hint_max_amount')" persistent-hint
                :error-messages="delegateForm.errors.max_amount"
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="delegateForm.reason" :label="t('budgets_approvals.field_reason')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <VAlert type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('budgets_approvals.delegate_notice') }}
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="delegateDialog = false">{{ t('budgets_approvals.cancel') }}</VBtn>
          <VBtn color="primary" :loading="delegateForm.processing" :disabled="!delegateForm.to_user_id" @click="saveDelegate">
            {{ t('budgets_approvals.delegate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
