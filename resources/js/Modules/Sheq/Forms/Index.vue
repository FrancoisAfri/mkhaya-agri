<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type FormRef = {
  form_code: string; title: string; defined: boolean
  generated: boolean; status: string | null; uuid: string | null
}

type Row = {
  id: number; uuid: string; reference: string; occurred_at: string
  statutory_category: string | null; status: string; site_name: string | null
  forms: FormRef[]
}

const props = defineProps<{ rows: Row[]; noCompany?: boolean }>()

const generate = useForm({ incident_uuid: '', form_code: '' })

const create = (incidentUuid: string, formCode: string) => {
  generate.incident_uuid = incidentUuid
  generate.form_code = formCode
  generate.post('/sheq/forms')
}

/** Outstanding = a form the pack demands that nobody has produced yet. */
const outstanding = computed(() =>
  props.rows.reduce((n, r) => n + r.forms.filter(f => !f.generated).length, 0))

const formatDate = (v: string) => { try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.forms.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.forms.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="outstanding > 0" type="warning" variant="tonal" class="mb-4"
        aria-live="polite" role="status"
      >
        {{ t('sheq.forms.outstanding', { count: outstanding }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <div v-if="!rows.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-file-document-check-outline" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.forms.none_reportable') }}</div>
        </div>

        <VTable v-else density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.incident.reference') }}</th>
              <th scope="col">{{ t('sheq.incident.occurred') }}</th>
              <th scope="col">{{ t('sheq.registers.col.site') }}</th>
              <th scope="col">{{ t('sheq.incident.classification') }}</th>
              <th scope="col">{{ t('sheq.forms.required_forms') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.uuid">
              <td>
                <Link :href="`/sheq/incidents/${row.uuid}`"
                      class="text-primary text-decoration-none font-weight-medium">
                  {{ row.reference }}
                </Link>
              </td>
              <td class="text-body-2">{{ formatDate(row.occurred_at) }}</td>
              <td class="text-body-2 text-medium-emphasis">{{ row.site_name ?? '—' }}</td>
              <td>
                <VChip color="error" size="x-small" variant="tonal" prepend-icon="mdi-gavel">
                  {{ row.statutory_category }}
                </VChip>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-2 py-1">
                  <template v-for="form in row.forms" :key="form.form_code">
                    <!-- Generated: go and read it. Status in words, not colour alone. -->
                    <Link v-if="form.generated && form.uuid" :href="`/sheq/forms/${form.uuid}`"
                          class="text-decoration-none">
                      <VChip
                        :color="form.status === 'FINAL' ? 'success' : 'warning'"
                        size="small" variant="tonal" link
                        :prepend-icon="form.status === 'FINAL' ? 'mdi-check-decagram' : 'mdi-pencil-outline'"
                      >
                        {{ form.form_code }} · {{ form.status === 'FINAL'
                          ? t('sheq.forms.final') : t('sheq.forms.draft') }}
                      </VChip>
                    </Link>

                    <!-- Not yet produced: the statutory deadline is running. -->
                    <VBtn
                      v-else-if="form.defined"
                      size="small" variant="tonal" color="primary" prepend-icon="mdi-file-plus-outline"
                      :loading="generate.processing && generate.form_code === form.form_code
                        && generate.incident_uuid === row.uuid"
                      :disabled="generate.processing"
                      @click="create(row.uuid, form.form_code)"
                    >{{ t('sheq.forms.generate', { code: form.form_code }) }}</VBtn>

                    <!-- The pack names a form it does not define — a pack bug, said out loud
                         rather than offered as a button that fails when pressed. -->
                    <VChip v-else size="small" variant="tonal" color="grey"
                           prepend-icon="mdi-alert-outline">
                      {{ t('sheq.forms.not_defined', { code: form.form_code }) }}
                    </VChip>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
