<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const f = (key: string, a?: Record<string, unknown>) => t('bureau.filespec.' + key, a ?? {})

interface Spec {
  uuid: string; code: string; name: string; vendor: string | null
  version: number; effective_from: string; effective_to: string | null
  format: string; is_active: boolean; fields_count: number; shared: boolean
}

const props = defineProps<{
  specifications: Spec[]
  codes: string[]
  filters: Record<string, string>
}>()

const code = ref(props.filters.code ?? '')
const codeItems = computed(() => [{ title: f('all_files'), value: '' }, ...props.codes.map(c => ({ title: c, value: c }))])

function filter() {
  router.get(route('payroll.file-specs.index'), { code: code.value }, { preserveState: true, replace: true })
}

const dialog = ref(false)
const form = useForm({
  code: '', name: '', vendor: '', effective_from: new Date().toISOString().slice(0, 10),
  format: 'fixed', delimiter: ',', line_ending: 'crlf',
})
function save() { form.post(route('payroll.file-specs.store'), { onSuccess: () => { dialog.value = false; form.reset() } }) }

function open(spec: Spec) { router.get(route('payroll.file-specs.show', spec.uuid)) }
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ f('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ f('description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ f('new_layout') }}</VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VSelect
          v-model="code" :items="codeItems" :label="f('file')" density="compact" hide-details
          style="max-width:320px" @update:model-value="filter"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ f('file') }}</th>
            <th>{{ f('vendor') }}</th>
            <th>{{ f('version') }}</th>
            <th>{{ f('in_force') }}</th>
            <th>{{ f('format') }}</th>
            <th class="text-end">{{ f('fields') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in specifications" :key="s.uuid" style="cursor:pointer" @click="open(s)">
            <td>
              <div class="font-weight-medium">{{ s.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ s.code }}
                <!-- A shipped layout belongs to every tenant, so it is read-only here. -->
                <VChip v-if="s.shared" size="x-small" variant="tonal" class="ms-1">{{ f('shipped') }}</VChip>
                <VChip v-if="!s.is_active" size="x-small" color="grey" variant="tonal" class="ms-1">{{ f('inactive') }}</VChip>
              </div>
            </td>
            <td>{{ s.vendor || '—' }}</td>
            <td>v{{ s.version }}</td>
            <td class="text-caption">{{ s.effective_from }} → {{ s.effective_to || f('current') }}</td>
            <td class="text-capitalize">{{ s.format }}</td>
            <td class="text-end">{{ s.fields_count }}</td>
          </tr>
          <tr v-if="!specifications.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">{{ f('empty') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="620">
      <VCard>
        <VCardTitle>{{ f('new_layout') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ f('new_help') }}</p>
          <VTextField v-model="form.code" :label="f('code')" :hint="f('code_hint')" persistent-hint :error-messages="form.errors.code" />
          <VTextField v-model="form.name" :label="f('name')" :error-messages="form.errors.name" />
          <div class="d-flex ga-2">
            <VTextField v-model="form.vendor" :label="f('vendor')" />
            <VTextField v-model="form.effective_from" type="date" :label="f('effective_from')" :error-messages="form.errors.effective_from" />
          </div>
          <div class="d-flex ga-2">
            <VSelect v-model="form.format" :items="['fixed', 'delimited']" :label="f('format')" />
            <VTextField v-if="form.format === 'delimited'" v-model="form.delimiter" :label="f('delimiter')" style="max-width:120px" />
            <VSelect v-model="form.line_ending" :items="['crlf', 'lf', 'cr']" :label="f('line_ending')" style="max-width:140px" />
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('bureau.ui.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
