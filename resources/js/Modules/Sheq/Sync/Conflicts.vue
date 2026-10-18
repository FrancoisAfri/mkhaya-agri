<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Conflict = {
  uuid: string; entity_type: string; entity_uuid: string; field: string
  base_value: unknown; server_value: unknown; client_value: unknown
  rule: string; detected_at: string; status: string
}

defineProps<{
  conflicts: Conflict[]
  resolved: number
  pendingOperations: number
  noCompany?: boolean
}>()

const active = ref<Conflict | null>(null)

const form = useForm({ resolution: 'TOOK_CLIENT', notes: '' })

const open = (conflict: Conflict, resolution: string) => {
  active.value = conflict
  form.resolution = resolution
  form.notes = ''
}

const submit = () => {
  if (!active.value) return
  form.post(`/sheq/sync/conflicts/${active.value.uuid}/resolve`, {
    preserveScroll: true,
    onSuccess: () => { active.value = null },
  })
}

const show = (value: unknown) =>
  value === null || value === undefined
    ? '—'
    : (typeof value === 'object' ? JSON.stringify(value) : String(value))
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.sync.conflicts.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.sync.conflicts.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="!conflicts.length" type="success" variant="tonal" density="comfortable" class="mb-4"
      >
        {{ t('sheq.sync.conflicts.all_clear') }}
      </VAlert>

      <VAlert v-if="pendingOperations" type="warning" variant="tonal" density="compact" class="mb-4">
        {{ t('sheq.sync.conflicts.rejected_operations', { count: pendingOperations }) }}
      </VAlert>

      <!-- Both values side by side, which is the whole requirement (SHEQ-OFF-5). A queue that
           showed only the winner would be a record of data loss rather than a way to prevent it. -->
      <VCard v-for="conflict in conflicts" :key="conflict.uuid" elevation="0" border rounded="lg" class="mb-3">
        <VCardText>
          <div class="d-flex align-center flex-wrap gap-2 mb-3">
            <VChip size="x-small" variant="tonal">{{ conflict.entity_type }}</VChip>
            <span class="font-weight-medium">{{ conflict.field }}</span>
            <VSpacer />
            <span class="text-caption text-medium-emphasis">{{ conflict.detected_at }}</span>
          </div>

          <VRow>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">
                {{ t('sheq.sync.conflicts.on_server') }}
              </div>
              <VSheet border rounded class="pa-3 text-body-2 h-100">{{ show(conflict.server_value) }}</VSheet>
            </VCol>
            <VCol cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">
                {{ t('sheq.sync.conflicts.from_device') }}
              </div>
              <VSheet border rounded class="pa-3 text-body-2 h-100">{{ show(conflict.client_value) }}</VSheet>
            </VCol>
          </VRow>

          <div class="text-caption text-medium-emphasis mt-2">
            {{ t('sheq.sync.conflicts.was') }}: {{ show(conflict.base_value) }}
          </div>
        </VCardText>

        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" size="small" @click="open(conflict, 'TOOK_SERVER')">
            {{ t('sheq.sync.conflicts.keep_server') }}
          </VBtn>
          <VBtn color="primary" variant="flat" size="small" @click="open(conflict, 'TOOK_CLIENT')">
            {{ t('sheq.sync.conflicts.keep_device') }}
          </VBtn>
        </VCardActions>
      </VCard>

      <p v-if="resolved" class="text-caption text-medium-emphasis">
        {{ t('sheq.sync.conflicts.resolved_count', { count: resolved }) }}
      </p>
    </template>

    <VDialog :model-value="!!active" max-width="620" @update:model-value="active = null">
      <VCard v-if="active">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.sync.conflicts.confirm_title') }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-3">
            {{ form.resolution === 'TOOK_CLIENT'
              ? t('sheq.sync.conflicts.confirm_device')
              : t('sheq.sync.conflicts.confirm_server') }}
          </p>
          <VSheet border rounded class="pa-3 text-body-2 mb-3">
            {{ show(form.resolution === 'TOOK_CLIENT' ? active.client_value : active.server_value) }}
          </VSheet>
          <VTextarea
            v-model="form.notes" :label="t('sheq.sync.conflicts.notes')" rows="2"
            variant="outlined" density="comfortable" hide-details
          />
          <p class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('sheq.sync.conflicts.both_kept') }}
          </p>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="active = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="submit">
            {{ t('sheq.sync.conflicts.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
