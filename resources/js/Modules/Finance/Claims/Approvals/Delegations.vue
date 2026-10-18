<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ActiveDelegation {
  id: number
  delegate: string
  start_date: string
  end_date: string
  reason: string | null
}

interface AuditEntry {
  id: number
  direction: 'outbound' | 'inbound'
  other_party: string
  start_date: string
  end_date: string
  reason: string | null
  is_active: boolean
  revoked_at: string | null
}

interface UserOption {
  id: number
  name: string
  email: string
}

const props = defineProps<{
  activeDelegation: ActiveDelegation | null
  auditLog: AuditEntry[]
  users: UserOption[]
}>()

const createDialog  = ref(false)
const revokeDialog  = ref(false)
const revokeTarget  = ref<{ id: number; delegate: string } | null>(null)

const form = useForm({
  delegate_id: null as number | null,
  start_date:  '',
  end_date:    '',
  reason:      '',
})

const today = new Date().toISOString().slice(0, 10)

function openCreate() {
  form.reset()
  createDialog.value = true
}

function saveDelegate() {
  form.post(route('claims.approvals.delegations.store'), {
    onSuccess: () => { createDialog.value = false; form.reset() },
  })
}

function confirmRevoke(id: number, delegate: string) {
  revokeTarget.value = { id, delegate }
  revokeDialog.value  = true
}

function doRevoke() {
  if (!revokeTarget.value) return
  router.delete(route('claims.approvals.delegations.revoke', { delegation: revokeTarget.value.id }), {
    onSuccess: () => { revokeDialog.value = false; revokeTarget.value = null },
  })
}

const directionColor = (d: string) => d === 'outbound' ? 'primary' : 'secondary'
const statusColor    = (entry: AuditEntry) => entry.is_active ? 'success' : entry.revoked_at ? 'error' : 'grey'
const statusLabel    = (entry: AuditEntry) => entry.is_active ? t('claims_delegations.active') : entry.revoked_at ? t('claims_delegations.revoked_status') : t('claims_delegations.expired')

const userItems = computed(() => props.users.map(user => ({ value: user.id, title: user.name, subtitle: user.email })))
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_delegations.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_delegations.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="tabler-arrow-left" :href="route('claims.approvals.index')">
          {{ t('claims_delegations.back_to_approvals') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :disabled="!!activeDelegation" @click="openCreate">
          {{ t('claims_delegations.new_delegation') }}
        </VBtn>
      </div>
    </div>

    <!-- Active delegation banner -->
    <VAlert
      v-if="activeDelegation"
      type="info"
      variant="tonal"
      class="mb-5"
      icon="tabler-user-share"
    >
      <div class="d-flex align-center justify-space-between flex-wrap ga-2">
        <div>
          {{ t('claims_delegations.active_delegation_banner', { delegate: activeDelegation.delegate, start: activeDelegation.start_date, end: activeDelegation.end_date }) }}
          <span v-if="activeDelegation.reason" class="text-caption"> — "{{ activeDelegation.reason }}"</span>
        </div>
        <VBtn
          size="small"
          color="error"
          variant="outlined"
          prepend-icon="tabler-x"
          @click="confirmRevoke(activeDelegation.id, activeDelegation.delegate)"
        >
          {{ t('claims_delegations.revoke') }}
        </VBtn>
      </div>
    </VAlert>

    <VAlert
      v-else
      type="success"
      variant="tonal"
      class="mb-5"
      icon="tabler-circle-check"
      :text="t('claims_delegations.no_active_delegation')"
    />

    <!-- Audit log -->
    <VCard>
      <VCardTitle class="text-body-1 font-weight-bold pa-4 pb-0">{{ t('claims_delegations.delegation_history') }}</VCardTitle>
      <VCardText v-if="!auditLog.length" class="text-center pa-8 text-medium-emphasis">
        {{ t('claims_delegations.no_history') }}
      </VCardText>
      <VDataTable
        v-else
        :items="auditLog"
        :headers="[
          { title: t('claims_delegations.table.direction'),   key: 'direction',    width: '110px' },
          { title: t('claims_delegations.table.other_party'), key: 'other_party' },
          { title: t('claims_delegations.table.from'),        key: 'start_date',   width: '130px' },
          { title: t('claims_delegations.table.to'),          key: 'end_date',     width: '130px' },
          { title: t('claims_delegations.table.reason'),      key: 'reason' },
          { title: t('claims_delegations.table.status'),      key: 'status',       sortable: false, width: '110px' },
          { title: t('claims_delegations.table.revoked'),     key: 'revoked_at',   width: '150px' },
          { title: '',            key: 'actions',      sortable: false, width: '80px' },
        ]"
        density="comfortable"
        hover
      >
        <template #item.direction="{ item }">
          <VChip :color="directionColor(item.direction)" size="x-small" variant="tonal">
            <VIcon start size="12">{{ item.direction === 'outbound' ? 'tabler-arrow-right' : 'tabler-arrow-left' }}</VIcon>
            {{ item.direction === 'outbound' ? t('claims_delegations.outbound') : t('claims_delegations.inbound') }}
          </VChip>
        </template>
        <template #item.reason="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.reason || '—' }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item)" size="x-small" variant="tonal">{{ statusLabel(item) }}</VChip>
        </template>
        <template #item.revoked_at="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.revoked_at || '—' }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.is_active && item.direction === 'outbound'"
            size="x-small"
            color="error"
            variant="tonal"
            icon="tabler-x"
            :title="t('claims_delegations.revoke')"
            @click="confirmRevoke(item.id, item.other_party)"
          />
        </template>
      </VDataTable>
    </VCard>

    <!-- Create Delegation Dialog -->
    <VDialog v-model="createDialog" max-width="480" persistent>
      <VCard :title="t('claims_delegations.dialog.create_title')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claims_delegations.dialog.create_description') }}
          </p>
          <VAutocomplete
            v-model="form.delegate_id"
            :items="userItems"
            item-value="value"
            item-title="title"
            :label="t('claims_delegations.dialog.delegate_to')"
            variant="outlined"
            density="compact"
            :error-messages="form.errors.delegate_id"
            clearable
            class="mb-3"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
            </template>
          </VAutocomplete>

          <VRow dense>
            <VCol cols="6">
              <VTextField
                v-model="form.start_date"
                :label="t('claims_delegations.dialog.start_date')"
                type="date"
                :min="today"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.start_date"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.end_date"
                :label="t('claims_delegations.dialog.end_date')"
                type="date"
                :min="form.start_date || today"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.end_date"
              />
            </VCol>
          </VRow>

          <VTextarea
            v-model="form.reason"
            :label="t('claims_delegations.dialog.reason')"
            variant="outlined"
            density="compact"
            rows="2"
            :error-messages="form.errors.reason"
            class="mt-1"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="createDialog = false">{{ t('claims_delegations.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="saveDelegate">{{ t('claims_delegations.dialog.create_delegation') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Revoke Confirmation Dialog -->
    <VDialog v-model="revokeDialog" max-width="400">
      <VCard :title="t('claims_delegations.dialog.revoke_title')">
        <VCardText>
          {{ t('claims_delegations.dialog.revoke_message', { delegate: revokeTarget?.delegate }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="revokeDialog = false">{{ t('claims_delegations.dialog.cancel') }}</VBtn>
          <VBtn color="error" @click="doRevoke">{{ t('claims_delegations.dialog.revoke_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
