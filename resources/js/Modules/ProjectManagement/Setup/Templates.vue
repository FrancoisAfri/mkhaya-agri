<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Template = {
  id: string; name: string; industry: string; sub_type: string | null
  description: string | null; is_builtin: boolean; is_active: boolean; times_used: number
  counts: { wbs: number; tasks: number; gates: number; risks: number }
}

const props = defineProps<{
  templates: Template[]
  industries: string[]
  projects: Array<{ id: string; label: string }>
  can: { manage: boolean }
}>()

const filter = ref<string>('all')

const shown = computed(() => filter.value === 'all'
  ? props.templates
  : props.templates.filter(x => x.industry === filter.value))

function industryIcon(industry: string) {
  return ({
    construction: 'tabler-building-crane', it: 'tabler-device-desktop-code',
    marketing: 'tabler-speakerphone', manufacturing: 'tabler-building-factory-2',
    research: 'tabler-microscope', government: 'tabler-building-bank',
    engineering: 'tabler-tool', events: 'tabler-confetti', hr: 'tabler-users',
    training: 'tabler-school',
  } as Record<string, string>)[industry] ?? 'tabler-template'
}

// ── Capture a project as a template ─────────────────────────────────────────
const captureDialog = ref(false)
const captureForm = useForm({
  project_id: '', name: '', industry: 'construction', sub_type: '', description: '',
})

function saveCapture() {
  captureForm.post(route('project-management.setup.templates.capture'), {
    preserveScroll: true, onSuccess: () => { captureDialog.value = false; captureForm.reset() },
  })
}

const confirmer = ref()

function remove(x: Template) {
  confirmer.value.open({
    title: t('project_management.templates.confirm_remove_title'),
    message: x.is_builtin
      ? t('project_management.templates.confirm_hide_body', { name: x.name })
      : t('project_management.templates.confirm_remove_body', { name: x.name }),
    confirmText: x.is_builtin ? t('project_management.templates.hide') : t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(route('project-management.setup.templates.destroy', x.id), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.templates.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.templates.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-copy" @click="captureDialog = true">
        {{ t('project_management.templates.capture') }}
      </VBtn>
    </div>

    <VChipGroup v-model="filter" mandatory selected-class="text-primary" class="mb-2">
      <VChip value="all" size="small" label>{{ t('project_management.templates.all') }}</VChip>
      <VChip v-for="i in industries" :key="i" :value="i" size="small" label>
        {{ t(`project_management.templates.industry_${i}`) }}
      </VChip>
    </VChipGroup>

    <VRow v-if="shown.length" dense>
      <VCol v-for="x in shown" :key="x.id" cols="12" md="6" lg="4">
        <VCard border :class="{ 'opacity-60': !x.is_active }" height="100%">
          <VCardItem>
            <template #prepend>
              <VAvatar :color="x.is_active ? 'primary' : 'grey'" variant="tonal">
                <VIcon :icon="industryIcon(x.industry)" />
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1">{{ x.name }}</VCardTitle>
            <VCardSubtitle>
              {{ t(`project_management.templates.industry_${x.industry}`) }}
              <template v-if="x.sub_type"> · {{ x.sub_type }}</template>
            </VCardSubtitle>
            <template #append>
              <VBtn v-if="can.manage" icon="tabler-trash" variant="text" size="small"
                    :aria-label="t('project_management.templates.remove_named', { name: x.name })"
                    @click="remove(x)" />
            </template>
          </VCardItem>

          <VCardText class="pt-0">
            <p v-if="x.description" class="text-body-2 text-medium-emphasis">{{ x.description }}</p>
            <div class="d-flex gap-1 flex-wrap">
              <VChip size="x-small" label variant="tonal">
                {{ t('project_management.templates.n_wbs', { n: x.counts.wbs }) }}
              </VChip>
              <VChip size="x-small" label variant="tonal">
                {{ t('project_management.templates.n_tasks', { n: x.counts.tasks }) }}
              </VChip>
              <VChip v-if="x.counts.gates" size="x-small" label variant="tonal">
                {{ t('project_management.templates.n_gates', { n: x.counts.gates }) }}
              </VChip>
              <VChip v-if="x.counts.risks" size="x-small" label variant="tonal">
                {{ t('project_management.templates.n_risks', { n: x.counts.risks }) }}
              </VChip>
            </div>
            <!-- Usage is shown so a library of forty templates where two are ever used is
                 visible, rather than assumed to be forty useful templates. -->
            <div class="text-caption text-medium-emphasis mt-2">
              {{ t('project_management.templates.used_n', { n: x.times_used }) }}
              <template v-if="x.is_builtin"> · {{ t('project_management.templates.builtin') }}</template>
              <template v-if="!x.is_active"> · {{ t('project_management.templates.hidden') }}</template>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-else border>
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ t('project_management.templates.none') }}
        <div class="text-caption mt-1">{{ t('project_management.templates.none_hint') }}</div>
      </VCardText>
    </VCard>

    <VDialog v-model="captureDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.templates.capture') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.templates.capture_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.templates.capture_note') }}
          </VAlert>
          <VAutocomplete v-model="captureForm.project_id"
                         :items="projects.map(p => ({ title: p.label, value: p.id }))"
                         :label="t('project_management.templates.field_project')"
                         :error-messages="captureForm.errors.project_id"
                         variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="captureForm.name" :label="t('project_management.templates.field_name')"
                      :error-messages="captureForm.errors.name"
                      variant="outlined" density="compact" class="mb-3" />
          <VSelect v-model="captureForm.industry"
                   :items="industries.map(i => ({ title: t(`project_management.templates.industry_${i}`), value: i }))"
                   :label="t('project_management.templates.field_industry')"
                   variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="captureForm.sub_type" :label="t('project_management.templates.field_sub_type')"
                      :hint="t('project_management.templates.sub_type_hint')" persistent-hint
                      variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="captureForm.description" :label="t('project_management.templates.field_description')"
                     variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="captureDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="captureForm.processing"
                :disabled="!captureForm.project_id || !captureForm.name" @click="saveCapture">
            {{ t('project_management.templates.capture') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
