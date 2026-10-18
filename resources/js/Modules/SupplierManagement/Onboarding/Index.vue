<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Onboarding {
  id: number
  company_name: string
  contact_person: string | null
  supplier_name: string | null
  contact_person_email: string | null
  status: string
  expires_at: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
defineProps<{
  onboardings: Onboarding[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  pending: 'warning',
  sent: 'info',
  completed: 'success',
  expired: 'error',
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: t('supplier_onboarding.statuses.pending'),
    sent: t('supplier_onboarding.statuses.sent'),
    completed: t('supplier_onboarding.statuses.completed'),
    expired: t('supplier_onboarding.statuses.expired'),
  }
  return map[status] || status
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

// ── Methods ────────────────────────────────────────────────────────────────────
function sendMail(id: number): void {
  router.post(
    route('suppliers.onboarding.send-mail', id),
    {},
    { preserveScroll: true }
  )
}

function destroy(id: number): void {
  confirmDialog.value?.open(
    {
      title: t('supplier_onboarding.actions.delete_confirm_title'),
      message: t('supplier_onboarding.actions.delete_confirm_message'),
      confirmText: t('supplier_onboarding.actions.delete_confirm_button'),
      confirmColor: 'error',
      icon: 'tabler-trash',
    },
    () => {
      router.delete(route('suppliers.onboarding.destroy', id), { preserveScroll: true })
    }
  )
}

function goToCreate(): void {
  router.get(route('suppliers.onboarding.create'))
}

function goToEdit(id: number): void {
  router.get(route('suppliers.onboarding.edit', id))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h4 mb-0">{{ t('supplier_onboarding.page_title') }}</h1>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
        {{ t('supplier_onboarding.new_invite') }}
      </VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_onboarding.table.company') }}</th>
            <th>{{ t('supplier_onboarding.table.contact') }}</th>
            <th>{{ t('supplier_onboarding.table.email') }}</th>
            <th>{{ t('supplier_onboarding.table.status') }}</th>
            <th>{{ t('supplier_onboarding.table.expires') }}</th>
            <th class="text-right">{{ t('supplier_onboarding.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!onboardings.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              {{ t('supplier_onboarding.no_onboardings') }}
            </td>
          </tr>
          <tr v-for="o in onboardings" :key="o.id">
            <td class="font-weight-medium">{{ o.company_name }}</td>
            <td>{{ o.contact_person ?? o.supplier_name ?? '—' }}</td>
            <td>{{ o.contact_person_email ?? '—' }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor[o.status] ?? 'default'"
                variant="tonal"
              >
                {{ getStatusLabel(o.status) }}
              </VChip>
            </td>
            <td>{{ formatDate(o.expires_at) }}</td>
            <td class="text-right">
              <VBtn
                size="x-small"
                variant="tonal"
                color="primary"
                class="me-2"
                @click="sendMail(o.id)"
              >
                {{ t('supplier_onboarding.actions.send_mail') }}
              </VBtn>
              <VBtn
                size="x-small"
                variant="text"
                @click="goToEdit(o.id)"
              >
                {{ t('supplier_onboarding.actions.edit') }}
              </VBtn>
              <VBtn
                icon
                size="small"
                :aria-label="t('supplier_onboarding.actions.delete')"
                :title="t('supplier_onboarding.actions.delete')"
                variant="text"
                color="error"
                @click="destroy(o.id)"
              >
                <VIcon icon="tabler-trash" />
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
