<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The subcontractor register — what the Subcontractors ribbon lands on.
 *
 * One row per firm, however many projects they work on, because "everything about this
 * subcontractor" is a question about the firm and not about one job.
 *
 * Where the tenant has the Supplier module these rows are its records; where it does not, they are
 * Project Management's own. The page says which, rather than implying PM keeps a second list
 * beside procurement's.
 */
const props = defineProps<{
  contractors: Array<{
    id: string; name: string; email: string | null; source: string
    meta: Record<string, string>; projects: number; awaiting: number
  }>
  q: string | null
  integrated: boolean
  projects: Array<{ id: string; label: string }>
  can: { add: boolean }
}>()

const search = ref(props.q ?? '')

let debounce: ReturnType<typeof setTimeout>
watch(search, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('project-management.contractors.register'), { q: search.value || undefined },
      { preserveState: true, replace: true })
  }, 300)
})

const addDialog = ref(false)
const form = useForm({
  name: '', email: '', phone: '', registration_number: '', vat_number: '', notes: '', project: '',
})

function add() {
  form.transform(d => ({ ...d, project: d.project || null }))
    .post(route('project-management.contractors.store'), {
      preserveScroll: true,
      onSuccess: () => { addDialog.value = false; form.reset() },
    })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ t('project_management.contractors.register_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.contractors.register_sub') }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          variant="text" prepend-icon="tabler-file-invoice"
          :to="route('project-management.contractors.index')" :href="route('project-management.contractors.index')"
        >
          {{ t('project_management.contractors.title') }}
        </VBtn>
        <VBtn
          v-if="props.can.add" color="primary" prepend-icon="tabler-plus"
          @click="addDialog = true"
        >
          {{ t('project_management.contractors.add') }}
        </VBtn>
      </div>
    </div>

    <!-- Where procurement owns these records, say so plainly. -->
    <VAlert
      v-if="props.integrated" type="info" variant="tonal" density="compact" class="mb-4"
      icon="tabler-plug-connected"
    >
      {{ t('project_management.contractors.from_suppliers') }}
    </VAlert>

    <VCard border class="mb-4">
      <VCardText>
        <VTextField
          v-model="search" prepend-inner-icon="tabler-search" clearable
          :label="t('project_management.contractors.search')"
          density="compact" variant="outlined" hide-details style="max-width: 26rem"
        />
      </VCardText>
    </VCard>

    <VCard v-if="!props.contractors.length" border>
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ search
          ? t('project_management.contractors.none_matching')
          : t('project_management.contractors.register_empty') }}
      </VCardText>
    </VCard>

    <VRow v-else dense>
      <VCol v-for="c in props.contractors" :key="c.id" cols="12" md="6" lg="4">
        <VCard
          border class="h-100 contractor-card" link
          :href="route('project-management.contractors.show', c.id)"
        >
          <VCardItem>
            <VCardTitle class="text-body-1">{{ c.name }}</VCardTitle>
            <VCardSubtitle>{{ c.email ?? t('project_management.contractors.no_email_short') }}</VCardSubtitle>
            <template #append>
              <VChip v-if="c.awaiting" color="warning" size="small" variant="tonal">
                {{ t('project_management.contractors.awaiting', { count: c.awaiting }) }}
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <VChip size="x-small" variant="tonal" prepend-icon="tabler-folders">
                {{ t('project_management.contractors.on_projects', { count: c.projects }) }}
              </VChip>
              <VChip v-if="c.meta.vat_number" size="x-small" variant="tonal" color="grey">
                {{ t('project_management.contractors.vat_short') }} {{ c.meta.vat_number }}
              </VChip>
              <VChip v-if="c.meta.bbbee_level" size="x-small" variant="tonal" color="teal">
                B-BBEE {{ c.meta.bbbee_level }}
              </VChip>
            </div>
            <div v-if="c.meta.phone" class="text-caption text-medium-emphasis">{{ c.meta.phone }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Add a subcontractor ────────────────────────────────────────────── -->
    <VDialog v-model="addDialog" max-width="640">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.add') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contractors.add_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="7">
              <VTextField
                v-model="form.name" :label="t('project_management.contractors.field_name')"
                :error-messages="form.errors.name" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VTextField
                v-model="form.phone" :label="t('project_management.contractors.field_phone')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.email" type="email"
                :label="t('project_management.contractors.field_email')"
                :hint="t('project_management.contractors.field_email_hint')" persistent-hint
                :error-messages="form.errors.email" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.registration_number"
                :label="t('project_management.contractors.field_registration')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.vat_number" :label="t('project_management.contractors.field_vat')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.project"
                :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
                :label="t('project_management.contractors.field_project')"
                :hint="t('project_management.contractors.field_project_hint')" persistent-hint
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes" rows="2" auto-grow
                :label="t('project_management.contractors.field_notes')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="add">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.contractor-card { transition: box-shadow 0.15s ease; }
.contractor-card:hover { box-shadow: 0 2px 12px rgb(0 0 0 / 0.08); }
</style>
