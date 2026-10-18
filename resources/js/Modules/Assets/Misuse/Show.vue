<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Flag = {
  uuid: string
  flag_type: { value: string; label: string }
  severity: { value: string; label: string; color: string }
  status: { value: string; label: string; color: string }
  description: string
  evidence: Record<string, unknown> | null
  detected_at: string
  resolution_notes: string | null
  asset: { uuid: string; name: string; asset_tag: string | null } | null
  flaggedBy: { name: string } | null
  investigator: { name: string } | null
  resolvedBy: { name: string } | null
}

const props = defineProps<{
  flag: Flag
  users: { id: number; name: string }[]
}>()

const confirmDialog = ref()

const updateForm = useForm({
  status: '',
  assigned_investigator: null as number | null,
  resolution_notes: '',
})

const statusActionOptions = computed(() => {
  if (props.flag.status.value === 'open') {
    return [{ title: t('assets_misuse_flags_show.status_actions.assign_investigator'), value: 'investigating' }]
  }
  return [
    { title: t('assets_misuse_flags_show.status_actions.dismiss'), value: 'dismissed' },
    { title: t('assets_misuse_flags_show.status_actions.confirm'), value: 'confirmed' },
  ]
})

function submitUpdate() {
  updateForm.patch(route('assets.misuse-flags.update', props.flag.uuid), {
    onSuccess: () => { updateForm.reset() },
  })
}

function deleteFlag() {
  confirmDialog.value.open(
    { title: t('assets_misuse_flags_show.confirm.delete_title'), message: t('assets_misuse_flags_show.confirm.delete_message'), confirmText: t('assets_misuse_flags_show.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.misuse-flags.destroy', props.flag.uuid)),
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
          { title: t('assets_misuse_flags_show.breadcrumbs.misuse_flags'), href: route('assets.misuse-flags.index') },
          { title: flag.flag_type.label },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold">{{ flag.flag_type.label }}</h4>
      </div>
      <div class="d-flex gap-2">
        <VChip :color="flag.severity.color">{{ flag.severity.label }}</VChip>
        <VChip :color="flag.status.color">{{ flag.status.label }}</VChip>
        <VBtn v-if="flag.status.value === 'open'" color="error" variant="tonal" size="small" @click="deleteFlag">{{ t('assets_misuse_flags_show.actions.delete') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="5">
        <VCard elevation="0" border class="mb-4">
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-3">{{ t('assets_misuse_flags_show.details.title') }}</p>
            <div class="d-flex flex-column gap-2 text-body-2">
              <div><span class="text-medium-emphasis">{{ t('assets_misuse_flags_show.details.asset') }}:</span>
                <a :href="route('assets.show', flag.asset?.uuid)" class="text-primary">{{ flag.asset?.name }}</a>
                <span class="text-medium-emphasis"> ({{ flag.asset?.asset_tag }})</span>
              </div>
              <div><span class="text-medium-emphasis">{{ t('assets_misuse_flags_show.details.detected') }}:</span> {{ formatDate(flag.detected_at) }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_misuse_flags_show.details.flagged_by') }}:</span> {{ flag.flaggedBy?.name ?? 'System' }}</div>
              <div v-if="flag.investigator"><span class="text-medium-emphasis">{{ t('assets_misuse_flags_show.details.investigator') }}:</span> {{ flag.investigator.name }}</div>
              <div v-if="flag.resolvedBy"><span class="text-medium-emphasis">{{ t('assets_misuse_flags_show.details.resolved_by') }}:</span> {{ flag.resolvedBy.name }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-2">{{ t('assets_misuse_flags_show.description') }}</p>
            <p class="text-body-2 mb-0">{{ flag.description }}</p>
          </VCardText>
        </VCard>

        <VCard v-if="flag.resolution_notes" elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-2">{{ t('assets_misuse_flags_show.resolution_notes') }}</p>
            <p class="text-body-2 mb-0">{{ flag.resolution_notes }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard v-if="flag.status.value !== 'dismissed' && flag.status.value !== 'confirmed'" elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-4">{{ t('assets_misuse_flags_show.update_status.title') }}</p>
            <VForm @submit.prevent="submitUpdate">
              <VRow>
                <VCol cols="12">
                  <VSelect
                    v-model="updateForm.status"
                    :items="statusActionOptions"
                    item-title="title"
                    item-value="value"
                    :label="t('assets_misuse_flags_show.update_status.new_status')"
                    :error-messages="updateForm.errors.status"
                  />
                </VCol>
                <VCol v-if="updateForm.status === 'investigating'" cols="12">
                  <VAutocomplete
                    v-model="updateForm.assigned_investigator"
                    :items="users"
                    item-title="name"
                    item-value="id"
                    :label="t('assets_misuse_flags_show.update_status.investigator')"
                    :error-messages="updateForm.errors.assigned_investigator"
                  />
                </VCol>
                <VCol v-if="['dismissed', 'confirmed'].includes(updateForm.status)" cols="12">
                  <VTextarea
                    v-model="updateForm.resolution_notes"
                    :label="t('assets_misuse_flags_show.update_status.resolution_notes')"
                    rows="4"
                    :error-messages="updateForm.errors.resolution_notes"
                  />
                </VCol>
              </VRow>
              <VBtn type="submit" color="primary" :loading="updateForm.processing" class="mt-2">{{ t('assets_misuse_flags_show.update_status.update') }}</VBtn>
            </VForm>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
