<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Approval {
  id: number; uuid: string; type: string; type_label: string; title: string; amount: number | null
  deal: string | null; requested_by: string | null; approver: string | null
  status: string; status_label: string; status_color: string
  request_note: string | null; decision_note: string | null; created_at: string | null; decided_at: string | null
}
interface Notif { id: number; type: string; title: string; body: string | null; read: boolean; at: string | null }

const props = defineProps<{
  pending: Approval[]
  mine: Approval[]
  notifications: Notif[]
  unreadCount: number
  types: Record<string, string>
  statuses: Record<string, { label: string; color: string }>
  deals: { id: number; title: string; amount: number }[]
  users: { id: number; name: string }[]
}>()

const tab = ref<'pending' | 'mine'>('pending')
const dialog = ref(false)
const decideTarget = ref<Approval | null>(null)
const decideDialog = ref(false)

const reqForm = useForm({ deal_id: null as number | null, type: 'discount', title: '', amount: null as number | null, approver_id: null as number | null, request_note: '', mentioned: [] as number[] })
const decideForm = useForm({ status: 'approved', decision_note: '' })

const money = (n: number | null) => n === null ? '' : 'R ' + n.toLocaleString('en-ZA', { maximumFractionDigits: 0 })

function submitRequest() { reqForm.post(route('crm.approvals.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; reqForm.reset() } }) }
function openDecide(a: Approval, status: string) { decideTarget.value = a; decideForm.reset(); decideForm.status = status; decideDialog.value = true }
function submitDecision() {
  if (!decideTarget.value) return
  decideForm.post(route('crm.approvals.decide', decideTarget.value.id), { preserveScroll: true, onSuccess: () => decideDialog.value = false })
}
function cancel(a: Approval) { useForm({}).post(route('crm.approvals.cancel', a.id), { preserveScroll: true }) }
function markRead(n: Notif) { useForm({}).post(route('crm.notifications.read', n.id), { preserveScroll: true }) }
function markAllRead() { useForm({}).post(route('crm.notifications.read-all'), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_approvals_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_approvals_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('crm_approvals_index.request_approval') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VTabs v-model="tab" class="mb-2">
          <VTab value="pending">
            {{ t('crm_approvals_index.tabs.pending') }}
            <VChip v-if="pending.length" size="x-small" color="warning" label class="ml-2">{{ pending.length }}</VChip>
          </VTab>
          <VTab value="mine">{{ t('crm_approvals_index.tabs.mine') }}</VTab>
        </VTabs>

        <template v-if="tab === 'pending'">
          <VCard v-for="a in pending" :key="a.id" class="mb-3" border elevation="0">
            <VCardText>
              <div class="d-flex justify-space-between align-start">
                <div>
                  <div class="font-weight-semibold">{{ a.title }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ a.type_label }}<span v-if="a.amount !== null"> · {{ money(a.amount) }}</span>
                    <span v-if="a.deal"> · {{ a.deal }}</span> · {{ t('crm_approvals_index.tabs.pending') }} {{ a.requested_by }} · {{ a.created_at }}
                  </div>
                  <p v-if="a.request_note" class="text-body-2 mt-2 mb-0">{{ a.request_note }}</p>
                </div>
                <div class="d-flex gap-1">
                  <VBtn size="small" color="success" variant="tonal" @click="openDecide(a, 'approved')">{{ t('crm_approvals_index.actions.approve') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="openDecide(a, 'rejected')">{{ t('crm_approvals_index.actions.reject') }}</VBtn>
                </div>
              </div>
            </VCardText>
          </VCard>
          <VAlert v-if="!pending.length" type="success" variant="tonal" icon="tabler-circle-check">{{ t('crm_approvals_index.nothing_pending') }}</VAlert>
        </template>

        <template v-else>
          <VCard v-for="a in mine" :key="a.id" class="mb-3" border elevation="0">
            <VCardText class="d-flex justify-space-between align-center">
              <div>
                <div class="font-weight-semibold">{{ a.title }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ a.type_label }}<span v-if="a.amount !== null"> · {{ money(a.amount) }}</span> · approver {{ a.approver }} · {{ a.created_at }}
                </div>
                <p v-if="a.decision_note" class="text-body-2 mt-1 mb-0"><strong>Note:</strong> {{ a.decision_note }}</p>
              </div>
              <div class="d-flex align-center gap-2">
                <VChip :color="a.status_color" size="small" label>{{ a.status_label }}</VChip>
                <VBtn v-if="a.status === 'pending'" size="x-small" variant="text" color="error" @click="cancel(a)">{{ t('crm_approvals_index.actions.cancel_request') }}</VBtn>
              </div>
            </VCardText>
          </VCard>
          <VAlert v-if="!mine.length" type="info" variant="tonal">{{ t('crm_approvals_index.no_requests') }}</VAlert>
        </template>
      </VCol>

      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="d-flex align-center text-subtitle-1">
            {{ t('crm_approvals_index.notifications') }}
            <VChip v-if="unreadCount" size="x-small" color="error" label class="ml-2">{{ unreadCount }}</VChip>
            <VSpacer />
            <VBtn v-if="unreadCount" size="x-small" variant="text" @click="markAllRead">{{ t('crm_approvals_index.mark_all_read') }}</VBtn>
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="n in notifications" :key="n.id" :class="n.read ? '' : 'bg-primary-lighten-5'" @click="markRead(n)">
              <template #prepend>
                <VAvatar size="30" variant="tonal" :color="n.read ? 'blue-grey' : 'primary'">
                  <VIcon size="16" :icon="n.type === 'approval_decided' ? 'tabler-gavel' : n.type === 'mention' ? 'tabler-at' : 'tabler-bell'" />
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2">{{ n.title }}</VListItemTitle>
              <VListItemSubtitle>{{ n.at }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!notifications.length" :title="t('crm_approvals_index.no_notifications')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_approvals_index.dialog.title') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="6"><VSelect v-model="reqForm.type" :label="t('crm_approvals_index.dialog.type')" :items="Object.entries(types).map(([v, tp]) => ({ title: tp, value: v }))" /></VCol>
            <VCol cols="6"><VTextField v-model.number="reqForm.amount" type="number" :label="t('crm_approvals_index.dialog.amount_optional')" /></VCol>
            <VCol cols="12"><VTextField v-model="reqForm.title" :label="t('crm_approvals_index.dialog.title_label')" :placeholder="t('crm_approvals_index.dialog.title_placeholder')" /></VCol>
            <VCol cols="12"><VSelect v-model="reqForm.deal_id" :label="t('crm_approvals_index.dialog.deal_optional')" clearable :items="deals.map(d => ({ title: d.title, value: d.id }))" /></VCol>
            <VCol cols="12"><VSelect v-model="reqForm.approver_id" :label="t('crm_approvals_index.dialog.approver')" :items="users.map(u => ({ title: u.name, value: u.id }))" /></VCol>
            <VCol cols="12"><VSelect v-model="reqForm.mentioned" :label="t('crm_approvals_index.dialog.notify')" multiple chips closable-chips :items="users.map(u => ({ title: u.name, value: u.id }))" /></VCol>
            <VCol cols="12"><VTextarea v-model="reqForm.request_note" :label="t('crm_approvals_index.dialog.note')" rows="2" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_approvals_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="reqForm.processing" @click="submitRequest">{{ t('crm_approvals_index.dialog.request') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="decideDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle>{{ decideForm.status === 'approved' ? t('crm_approvals_index.decide_dialog.title_approve', { title: decideTarget?.title }) : t('crm_approvals_index.decide_dialog.title_reject', { title: decideTarget?.title }) }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="decideForm.decision_note" :label="t('crm_approvals_index.decide_dialog.note_optional')" rows="3" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="tonal" @click="decideDialog = false">{{ t('crm_approvals_index.decide_dialog.cancel') }}</VBtn>
          <VBtn :color="decideForm.status === 'approved' ? 'success' : 'error'" :loading="decideForm.processing" @click="submitDecision">
            {{ decideForm.status === 'approved' ? t('crm_approvals_index.decide_dialog.approve') : t('crm_approvals_index.decide_dialog.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
