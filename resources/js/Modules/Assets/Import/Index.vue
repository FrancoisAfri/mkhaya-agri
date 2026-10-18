<script setup lang="ts">
import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

interface RowError { row: number; message: string }
interface ImportResult {
  imported: number
  skipped: number
  errors: RowError[]
}
interface ColumnGuide { column: string; required: boolean; notes: string }

const props = defineProps<{
  result: ImportResult | null
  columns: ColumnGuide[]
}>()

const page = usePage()

const file = ref<File | null>(null)
const uploading = ref(false)
const fileError = ref<string | null>(null)

function onFileChange(value: File | File[] | null) {
  file.value = Array.isArray(value) ? (value[0] ?? null) : value
  fileError.value = null
}

function submit() {
  if (!file.value) {
    fileError.value = 'Please choose a CSV or Excel file to import.'
    return
  }

  uploading.value = true

  router.post(
    route('assets.import.store'),
    { file: file.value },
    {
      forceFormData: true,
      preserveScroll: true,
      onError: (errors) => {
        fileError.value = errors.file ?? 'The file could not be imported.'
      },
      onFinish: () => {
        uploading.value = false
        file.value = null
      },
    },
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 mb-1">Bulk Import Assets</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Upload a CSV or Excel file to register many assets at once.
        </p>
      </div>
      <VBtn
        color="secondary"
        variant="outlined"
        prepend-icon="tabler-download"
        :href="route('assets.import.template')"
      >
        Download template
      </VBtn>
    </div>

    <!-- Flash message -->
    <VAlert
      v-if="page.props.flash?.success"
      type="success"
      variant="tonal"
      class="mb-4"
      closable
    >
      {{ page.props.flash.success }}
    </VAlert>
    <VAlert
      v-if="page.props.flash?.error"
      type="error"
      variant="tonal"
      class="mb-4"
      closable
    >
      {{ page.props.flash.error }}
    </VAlert>

    <VRow>
      <!-- Upload form -->
      <VCol cols="12" md="7">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">Upload file</VCardTitle>
          <VCardText class="pa-4">
            <VFileInput
              label="CSV / Excel file"
              accept=".csv,.txt,.xlsx,.xls"
              prepend-icon="tabler-file-spreadsheet"
              :error-messages="fileError ? [fileError] : []"
              show-size
              clearable
              @update:model-value="onFileChange"
            />

            <VBtn
              color="primary"
              class="mt-2"
              prepend-icon="tabler-upload"
              :loading="uploading"
              :disabled="uploading"
              @click="submit"
            >
              Import assets
            </VBtn>
          </VCardText>
        </VCard>

        <!-- Results panel -->
        <VCard v-if="props.result" class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">Import results</VCardTitle>
          <VCardText class="pa-4">
            <div class="d-flex ga-6 mb-4">
              <div>
                <div class="text-h4 text-success">{{ props.result.imported }}</div>
                <div class="text-caption text-medium-emphasis">Imported</div>
              </div>
              <div>
                <div class="text-h4 text-warning">{{ props.result.skipped }}</div>
                <div class="text-caption text-medium-emphasis">Skipped</div>
              </div>
            </div>

            <template v-if="props.result.errors.length">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 mb-2">Row issues</div>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th class="text-left" style="width: 90px">Row</th>
                    <th class="text-left">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(err, i) in props.result.errors" :key="i">
                    <td>{{ err.row || '—' }}</td>
                    <td>{{ err.message }}</td>
                  </tr>
                </tbody>
              </VTable>
            </template>
            <VAlert
              v-else
              type="success"
              variant="tonal"
              density="compact"
            >
              All rows processed without issues.
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Column reference -->
      <VCol cols="12" md="5">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">Column reference</VCardTitle>
          <VCardText class="pa-4">
            <p class="text-body-2 text-medium-emphasis mb-3">
              The first row of your file must be the heading row using these exact
              column names. Only <strong>name</strong> is required.
            </p>
            <VTable density="compact">
              <thead>
                <tr>
                  <th class="text-left">Column</th>
                  <th class="text-left">Req.</th>
                  <th class="text-left">Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="col in props.columns" :key="col.column">
                  <td><code>{{ col.column }}</code></td>
                  <td>
                    <VChip
                      :color="col.required ? 'error' : 'default'"
                      size="x-small"
                      variant="tonal"
                    >
                      {{ col.required ? 'Yes' : 'No' }}
                    </VChip>
                  </td>
                  <td class="text-caption">{{ col.notes }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
