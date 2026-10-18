<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Item = {
  id: number
  uuid: string
  resolution: { value: string; label: string; color: string }
  asset: { uuid: string; name: string; asset_tag: string | null } | null
  resolvedBy: { name: string } | null
  notes: string | null
}

type Clearance = {
  uuid: string
  status: { value: string; label: string; color: string }
  user: { name: string } | null
  initiator: { name: string } | null
  target_exit_date: string
  cleared_at: string | null
  notes: string | null
  items: Item[]
}

const props = defineProps<{
  clearance: Clearance
  locations: { id: number; name: string }[]
  users: { id: number; name: string }[]
}>()

const resolvingItem = ref<Item | null>(null)
const confirmDialog = ref()
const resolveForm = useForm({
  resolution: '',
  transfer_to_user_id: null as number | null,
  transfer_to_location_id: null as number | null,
  waiver_reason: '',
  notes: '',
})

const resolutionOptions = computed(() => [
  { title: t('assets_exit_clearances_show.resolutions.returned'), value: 'returned' },
  { title: t('assets_exit_clearances_show.resolutions.transferred'), value: 'transferred' },
  { title: t('assets_exit_clearances_show.resolutions.waived'), value: 'waived' },
])

function openResolve(item: Item) {
  resolvingItem.value = item
  resolveForm.reset()
}

function submitResolve() {
  if (!resolvingItem.value) return
  resolveForm.patch(route('assets.exit-clearances.items.resolve', [props.clearance.uuid, resolvingItem.value.id]), {
    onSuccess: () => { resolvingItem.value = null },
  })
}

function deleteClearance() {
  confirmDialog.value.open(
    { title: t('assets_exit_clearances_show.confirm.delete_title'), message: t('assets_exit_clearances_show.confirm.delete_message'), confirmText: t('assets_exit_clearances_show.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.exit-clearances.destroy', props.clearance.uuid)),
  )
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_exit_clearances_show.breadcrumbs.exit_clearances'), href: route('assets.exit-clearances.index') },
          { title: clearance.user?.name ?? 'Clearance' },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold">{{ t('assets_exit_clearances_show.title_prefix') }} {{ clearance.user?.name }}</h4>
      </div>
      <div class="d-flex gap-2">
        <VChip :color="clearance.status.color">{{ clearance.status.label }}</VChip>
        <VBtn
          v-if="clearance.status.value === 'pending'"
          color="error"
          variant="tonal"
          prepend-icon="tabler-trash"
          @click="deleteClearance"
        >
          {{ t('assets_exit_clearances_show.actions.delete') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-3">{{ t('assets_exit_clearances_show.details.title') }}</p>
            <div class="d-flex flex-column gap-2 text-body-2">
              <div><span class="text-medium-emphasis">{{ t('assets_exit_clearances_show.details.employee') }}:</span> {{ clearance.user?.name }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_exit_clearances_show.details.target_exit') }}:</span> {{ formatDate(clearance.target_exit_date) }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_exit_clearances_show.details.initiated_by') }}:</span> {{ clearance.initiator?.name ?? '—' }}</div>
              <div v-if="clearance.cleared_at"><span class="text-medium-emphasis">{{ t('assets_exit_clearances_show.details.cleared') }}:</span> {{ formatDate(clearance.cleared_at) }}</div>
              <div v-if="clearance.notes"><span class="text-medium-emphasis">{{ t('assets_exit_clearances_show.details.notes') }}:</span> {{ clearance.notes }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-3">{{ t('assets_exit_clearances_show.asset_items') }} ({{ clearance.items.length }})</p>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('assets_exit_clearances_show.table.asset') }}</th>
                  <th>{{ t('assets_exit_clearances_show.table.resolution') }}</th>
                  <th>{{ t('assets_exit_clearances_show.table.resolved_by') }}</th>
                  <th>{{ t('assets_exit_clearances_show.table.notes') }}</th>
                  <th v-if="clearance.status.value !== 'cleared'" />
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in clearance.items" :key="item.id">
                  <td>
                    <p class="mb-0 font-weight-medium">{{ item.asset?.name ?? '—' }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ item.asset?.asset_tag }}</p>
                  </td>
                  <td>
                    <VChip size="small" :color="item.resolution.color">{{ item.resolution.label }}</VChip>
                  </td>
                  <td>{{ item.resolvedBy?.name ?? '—' }}</td>
                  <td>{{ item.notes ?? '—' }}</td>
                  <td v-if="clearance.status.value !== 'cleared'">
                    <VBtn
                      v-if="item.resolution.value === 'pending'"
                      size="small"
                      variant="tonal"
                      color="primary"
                      @click="openResolve(item)"
                    >
                      {{ t('assets_exit_clearances_show.table.resolve') }}
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="!clearance.items.length">
                  <td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('assets_exit_clearances_show.table.no_items') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolvingItem" max-width="520">
      <VCard>
        <VCardTitle class="pa-6 pb-0">{{ t('assets_exit_clearances_show.resolve_dialog.title_prefix') }} {{ resolvingItem?.asset?.name }}</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitResolve">
            <VRow>
              <VCol cols="12">
                <VSelect
                  v-model="resolveForm.resolution"
                  :items="resolutionOptions"
                  item-title="title"
                  item-value="value"
                  :label="t('assets_exit_clearances_show.resolve_dialog.resolution')"
                  :error-messages="resolveForm.errors.resolution"
                />
              </VCol>
              <VCol v-if="resolveForm.resolution === 'transferred'" cols="12">
                <VAutocomplete
                  v-model="resolveForm.transfer_to_user_id"
                  :items="users"
                  item-title="name"
                  item-value="id"
                  :label="t('assets_exit_clearances_show.resolve_dialog.transfer_to_user')"
                  clearable
                  :error-messages="resolveForm.errors.transfer_to_user_id"
                />
              </VCol>
              <VCol v-if="resolveForm.resolution === 'transferred'" cols="12">
                <VAutocomplete
                  v-model="resolveForm.transfer_to_location_id"
                  :items="locations"
                  item-title="name"
                  item-value="id"
                  :label="t('assets_exit_clearances_show.resolve_dialog.transfer_to_location')"
                  clearable
                />
              </VCol>
              <VCol v-if="resolveForm.resolution === 'waived'" cols="12">
                <VTextarea
                  v-model="resolveForm.waiver_reason"
                  :label="t('assets_exit_clearances_show.resolve_dialog.waiver_reason')"
                  rows="3"
                  :error-messages="resolveForm.errors.waiver_reason"
                />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="resolveForm.notes" :label="t('assets_exit_clearances_show.resolve_dialog.notes')" rows="2" />
              </VCol>
            </VRow>
            <div class="d-flex gap-3 mt-2">
              <VBtn type="submit" color="primary" :loading="resolveForm.processing">{{ t('assets_exit_clearances_show.resolve_dialog.confirm') }}</VBtn>
              <VBtn variant="text" @click="resolvingItem = null">{{ t('assets_exit_clearances_show.resolve_dialog.cancel') }}</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
