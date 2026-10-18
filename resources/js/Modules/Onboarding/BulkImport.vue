<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * Bulk onboarding: download the spreadsheet, fill it in, upload it back.
 *
 * The column list and the required set come from the server rather than being restated here, so the
 * instructions on screen cannot drift from what the importer actually accepts.
 */

type TemplateOption = { id: number; name: string; reference: string; industry: string | null; shared: boolean }
type RowError = { row: number; name: string; error: string }
type ImportRun = { id: string; filename: string; total: number; created: number; failed: number; errors: RowError[]; at: string }

const props = defineProps<{
  templates: TemplateOption[]
  columns: string[]
  required: string[]
  recent: ImportRun[]
}>()

const { t } = useI18n()
const route = ziggyRoute
const page = usePage()

const file = ref<File | null>(null)
const templateId = ref<number | null>(null)
const uploading = ref(false)

/** Set by the controller after an upload; drives the result banner. */
const result = computed(() => (page.props as any).flash?.bulk_result ?? null)

const errors = computed(() => (page.props as any).errors ?? {})

function submit() {
  if (!file.value) return

  uploading.value = true

  router.post(route('onboarding.bulk.import'), {
    file: file.value,
    template_id: templateId.value,
  }, {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => { uploading.value = false; file.value = null },
  })
}

const isRequired = (column: string) => props.required.includes(column)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('onboarding_bulk.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding_bulk.subtitle') }}</p>
      </div>
      <VBtn
        color="primary" variant="tonal" prepend-icon="tabler-download"
        :href="route('onboarding.bulk.template')"
      >
        {{ t('onboarding_bulk.download_template') }}
      </VBtn>
    </div>

    <!-- Result of the last upload. Shown before the form: it is what the user came back to see. -->
    <VAlert
      v-if="result"
      :type="result.failed ? 'warning' : 'success'"
      variant="tonal"
      class="mb-4"
      :title="t('onboarding_bulk.result_title', { created: result.created, total: result.total })"
    >
      <p v-if="result.failed" class="mb-2">
        {{ t('onboarding_bulk.result_failed', { failed: result.failed }) }}
      </p>
      <VTable v-if="result.errors?.length" density="compact" class="bg-transparent">
        <thead>
          <tr>
            <th>{{ t('onboarding_bulk.col_row') }}</th>
            <th>{{ t('onboarding_bulk.col_name') }}</th>
            <th>{{ t('onboarding_bulk.col_reason') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in result.errors" :key="e.row">
            <td>{{ e.row }}</td>
            <td>{{ e.name || '—' }}</td>
            <td class="text-error">{{ e.error }}</td>
          </tr>
        </tbody>
      </VTable>
    </VAlert>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('onboarding_bulk.upload_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_bulk.upload_sub') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VFileInput
              v-model="file"
              accept=".csv,text/csv"
              :label="t('onboarding_bulk.field_file')"
              prepend-icon="tabler-file-spreadsheet"
              :error-messages="errors.file"
              class="mb-4"
            />

            <VSelect
              v-model="templateId"
              :items="templates"
              item-title="name"
              item-value="id"
              clearable
              :label="t('onboarding_bulk.field_default_template')"
              :hint="t('onboarding_bulk.field_default_template_hint')"
              persistent-hint
              class="mb-4"
            >
              <template #item="{ props: itemProps, item }">
                <VListItem v-bind="itemProps" :subtitle="item.raw.shared ? t('onboarding_bulk.shared_template') : t('onboarding_bulk.own_template')" />
              </template>
            </VSelect>

            <VBtn
              color="primary" prepend-icon="tabler-upload"
              :loading="uploading" :disabled="!file"
              @click="submit"
            >
              {{ t('onboarding_bulk.import_btn') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('onboarding_bulk.columns_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_bulk.columns_sub') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <div class="d-flex flex-wrap gap-2">
              <VChip
                v-for="column in columns" :key="column"
                size="small" label
                :color="isRequired(column) ? 'primary' : undefined"
              >
                {{ column }}<span v-if="isRequired(column)" class="ms-1">*</span>
              </VChip>
            </div>
            <p class="text-caption text-medium-emphasis mt-3 mb-0">
              {{ t('onboarding_bulk.columns_hint') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard border class="mt-4">
      <VCardItem>
        <VCardTitle>{{ t('onboarding_bulk.recent_title') }}</VCardTitle>
      </VCardItem>
      <VDivider />
      <VTable v-if="recent.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('onboarding_bulk.col_file') }}</th>
            <th class="text-right">{{ t('onboarding_bulk.col_rows') }}</th>
            <th class="text-right">{{ t('onboarding_bulk.col_created') }}</th>
            <th class="text-right">{{ t('onboarding_bulk.col_failed') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="run in recent" :key="run.id">
            <td>{{ run.filename }}</td>
            <td class="text-right">{{ run.total }}</td>
            <td class="text-right text-success">{{ run.created }}</td>
            <td class="text-right" :class="run.failed ? 'text-error' : 'text-medium-emphasis'">{{ run.failed }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">
        {{ t('onboarding_bulk.no_runs') }}
      </VCardText>
    </VCard>
  </div>
</template>
