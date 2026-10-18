<script setup lang="ts">
/**
 * What is using the company's storage, and which files to look at first.
 *
 * The list of largest files sits next to the number on purpose. "68.4 GB of 100 GB" tells an
 * administrator they have a problem and nothing about what to do; the twenty-five biggest files
 * tell them where it is.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

const props = defineProps<{
  storage: { used: number; quota: number; enforced: boolean; breakdown: { label: string; bytes: number }[] }
  largest: { name: string; mime: string | null; bytes: number; uploaded_at: string | null; document: string | null }[]
  quotas: { uuid: string; scope: string; scope_id: number; name: string | null; limit: number; used: number; percent: number; warn_percent: number; note: string | null }[]
  subjects: {
    departments: { id: number; name: string }[]
    folders: { id: number; name: string }[]
    people: { id: number; name: string }[]
  }
}>()

const bytes = (value: number) => {
  if (!value) return '0 B'

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = value
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++ }

  return `${size.toFixed(unit <= 1 ? 0 : 1)} ${units[unit]}`
}

const percent = computed(() =>
  props.storage.quota > 0 ? Math.min(100, Math.round((props.storage.used / props.storage.quota) * 100)) : 0,
)

const colour = computed(() => (percent.value >= 90 ? 'error' : percent.value >= 75 ? 'warning' : 'primary'))

/** The share of the total each bucket takes, for the bar under the headline figure. */
const share = (value: number) => (props.storage.used > 0 ? (value / props.storage.used) * 100 : 0)

// ── Limits below the company's own ─────────────────────────────────────────

const settingQuota = ref(false)

const quotaForm = useForm({
  scope: 'department',
  scope_id: null as number | null,
  // In gigabytes: bytes in a form means somebody typing eleven digits and getting one wrong.
  gigabytes: 10,
  warn_percent: 85,
  note: '',
})

const subjectsFor = computed(() => {
  if (quotaForm.scope === 'department') return props.subjects.departments
  if (quotaForm.scope === 'folder') return props.subjects.folders

  return props.subjects.people
})

function saveQuota() {
  quotaForm.post(route('document-management.quotas.store'), {
    preserveScroll: true,
    onSuccess: () => { settingQuota.value = false; quotaForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1000px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('storage_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('storage_subtitle') }}</p>

    <VCard elevation="0" border class="mb-4">
      <VCardText>
        <div class="d-flex align-baseline ga-2 mb-2">
          <span class="text-h4 font-weight-bold">{{ bytes(storage.used) }}</span>
          <span v-if="storage.quota" class="text-body-1 text-medium-emphasis">
            / {{ bytes(storage.quota) }}
          </span>
          <VSpacer />
          <!-- Said plainly: a quota nobody enforces is a figure, not a limit. -->
          <VChip v-if="!storage.enforced" size="small" variant="tonal">{{ d('storage_not_enforced') }}</VChip>
        </div>

        <VProgressLinear
          v-if="storage.quota" :model-value="percent" :color="colour" height="10" rounded
          :aria-label="d('dashboard_storage_used', { percent })"
        />

        <VTable v-if="storage.breakdown.length" density="compact" class="mt-4">
          <tbody>
            <tr v-for="row in storage.breakdown" :key="row.label">
              <td style="width:9rem">{{ d('storage_' + row.label) }}</td>
              <td style="width:7rem" class="text-body-2 font-weight-medium">{{ bytes(row.bytes) }}</td>
              <td>
                <VProgressLinear :model-value="share(row.bytes)" height="6" rounded color="primary" />
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!--
      The company's own limit is the wrong instrument for the problem it is usually asked to solve:
      one department filling it stops everybody, everywhere, and the first anybody hears is that
      nothing uploads. These are the limits that would have caught it.
    -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1 d-flex align-center">
        {{ d('quotas_title') }}
        <VSpacer />
        <VBtn size="small" variant="tonal" prepend-icon="mdi-plus" @click="settingQuota = true">
          {{ d('quotas_add') }}
        </VBtn>
      </VCardTitle>
      <VCardSubtitle class="text-caption pb-2">{{ d('quotas_subtitle') }}</VCardSubtitle>

      <VTable v-if="quotas.length" density="comfortable">
        <tbody>
          <tr v-for="quota in quotas" :key="quota.uuid">
            <td>
              <VChip size="x-small" variant="tonal" class="me-2">{{ d('quotas_scope_' + quota.scope) }}</VChip>
              <span class="font-weight-medium">{{ quota.name }}</span>
              <div v-if="quota.note" class="text-caption text-medium-emphasis">{{ quota.note }}</div>
            </td>
            <td style="min-inline-size:12rem">
              <VProgressLinear
                :model-value="quota.percent" height="6" rounded
                :color="quota.percent >= 100 ? 'error' : quota.percent >= quota.warn_percent ? 'warning' : 'primary'"
              />
              <div class="text-caption text-medium-emphasis mt-1">
                {{ bytes(quota.used) }} / {{ bytes(quota.limit) }} · {{ quota.percent }}%
              </div>
            </td>
            <td class="text-right">
              <ConfirmAction
                :title="d('quotas_remove_title')"
                :body="d('quotas_remove_body', { name: quota.name })"
                :consequence="d('quotas_remove_consequence')"
                :confirm-label="d('quotas_remove')"
                @confirm="router.delete(route('document-management.quotas.destroy', quota.uuid), { preserveScroll: true })"
              >
                <template #activator="{ open }">
                  <VBtn size="small" variant="text" color="error" @click="open">
                    {{ d('quotas_remove') }}
                  </VBtn>
                </template>
              </ConfirmAction>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('quotas_none') }}</VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VCardText class="d-flex align-center ga-2 pb-2">
        <VIcon size="20" icon="mdi-file-chart-outline" />
        <span class="text-body-2 font-weight-medium">{{ d('storage_largest') }}</span>
      </VCardText>
      <VDivider />

      <div v-if="largest.length" style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ d('document') }}</th>
              <th>{{ d('storage_type') }}</th>
              <th class="text-end">{{ d('storage_size') }}</th>
              <th>{{ d('storage_uploaded') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(file, index) in largest" :key="index">
              <td>
                <a
                  v-if="file.document"
                  :href="route('document-management.documents.show', file.document)"
                >{{ file.name }}</a>
                <!-- A file with no document behind it belongs to another module. Named, not
                     linked, because there is nowhere here to send somebody. -->
                <span v-else class="text-body-2">{{ file.name }}</span>
              </td>
              <td class="text-caption">{{ file.mime }}</td>
              <td class="text-end text-body-2 font-weight-medium">{{ bytes(file.bytes) }}</td>
              <td class="text-caption">{{ file.uploaded_at }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('storage_no_files') }}</VCardText>
    </VCard>
  </div>

    <VDialog v-model="settingQuota" max-width="520">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('quotas_add') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VSelect
            v-model="quotaForm.scope" :label="d('quotas_scope')"
            :items="[
              { value: 'department', title: d('quotas_scope_department') },
              { value: 'folder', title: d('quotas_scope_folder') },
              { value: 'user', title: d('quotas_scope_user') },
            ]"
            @update:model-value="quotaForm.scope_id = null"
          />
          <VAutocomplete
            v-model="quotaForm.scope_id" :label="d('quotas_which')"
            :error-messages="quotaForm.errors.scope_id"
            :items="subjectsFor.map(s => ({ value: s.id, title: s.name }))"
          />
          <VTextField
            v-model.number="quotaForm.gigabytes" type="number" min="0.1" step="0.1"
            :label="d('quotas_gigabytes')" :error-messages="quotaForm.errors.gigabytes"
            style="max-width:12rem"
          />
          <!--
            The warning matters as much as the limit. A quota that refuses an upload with no
            warning beforehand is indistinguishable from the system being broken.
          -->
          <VTextField
            v-model.number="quotaForm.warn_percent" type="number" min="50" max="99"
            :label="d('quotas_warn_at')" :hint="d('quotas_warn_hint')" persistent-hint
            style="max-width:12rem"
          />
          <VTextField v-model="quotaForm.note" :label="d('quotas_note')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="settingQuota = false">{{ d('cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="quotaForm.processing"
            :disabled="!quotaForm.scope_id" @click="saveQuota"
          >{{ d('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>
