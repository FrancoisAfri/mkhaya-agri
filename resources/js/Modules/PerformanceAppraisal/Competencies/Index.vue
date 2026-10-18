<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Anchor = { id: number; level: number; label: string | null; behavior: string | null }
type Competency = { id: number; name: string; category: string | null; description: string | null; max_level: number; is_active: boolean; anchors: Anchor[]; role_count: number }

const props = defineProps<{
  competencies: Competency[]
  positions: { id: number; name: string }[]
  roleMap: Record<number, any[]>
  categories: string[]
}>()

const confirmDialog = ref()
const dialog = ref(false)
const form = ref({ name: '', category: null as string | null, description: '', max_level: 5 })
const anchorDialog = ref(false)
const anchorTarget = ref<Competency | null>(null)
const anchorForm = ref({ level: 1, label: '', behavior: '' })
const roleDialog = ref(false)
const roleForm = ref({ competency_id: null as number | null, position_id: null as number | null, expected_level: 3, is_critical: false })

function create() {
  router.post(route('performance.competencies.store'), form.value, {
    onSuccess: () => { dialog.value = false; form.value = { name: '', category: null, description: '', max_level: 5 } },
  })
}
function openAnchors(c: Competency) { anchorTarget.value = c; anchorForm.value = { level: 1, label: '', behavior: '' }; anchorDialog.value = true }
function saveAnchor() {
  if (!anchorTarget.value) return
  router.post(route('performance.competencies.anchors.save', anchorTarget.value.id), anchorForm.value, { preserveScroll: true, onSuccess: () => (anchorDialog.value = false) })
}
function saveRole() {
  router.post(route('performance.competencies.map-role'), roleForm.value, { preserveScroll: true, onSuccess: () => (roleDialog.value = false) })
}
function remove(c: Competency) {
  if (confirm(t('performance_competencie.delete_confirm', { name: c.name }))) router.delete(route('performance.competencies.destroy', c.id), { preserveScroll: true })
  confirmDialog.value.open(
    { title: 'Delete competency', message: `Delete competency “${c.name}”?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => { router.delete(route('performance.competencies.destroy', c.id), { preserveScroll: true }) },
  )
}
const catColor: Record<string, string> = { core: 'primary', leadership: 'warning', technical: 'info', functional: 'teal' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="teal" variant="tonal" size="48" rounded><VIcon icon="tabler-stack-2" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('performance_competencie.title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('performance_competencie.subtitle') }}</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-link" @click="roleDialog = true">{{ t('performance_competencie.map_to_role_button') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('performance_competencie.new_competency_button') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol v-for="c in competencies" :key="c.id" cols="12" md="6" lg="4">
        <VCard border class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-h6">{{ c.name }}</span>
              <VChip v-if="c.category" size="x-small" :color="catColor[c.category]" variant="tonal">{{ c.category }}</VChip>
            </div>
            <p class="text-caption text-medium-emphasis mb-2">{{ c.description }}</p>
            <div class="d-flex align-center gap-2 mb-2">
              <VChip size="x-small" variant="tonal">{{ t('performance_competencie.card.levels', { count: c.max_level }) }}</VChip>
              <VChip size="x-small" variant="tonal">{{ t('performance_competencie.card.anchors', { count: c.anchors.length }) }}</VChip>
              <VChip size="x-small" variant="tonal" color="indigo">{{ t('performance_competencie.card.roles', { count: c.role_count }) }}</VChip>
            </div>
            <div class="d-flex gap-1">
              <VBtn size="x-small" variant="tonal" prepend-icon="tabler-list-check" @click="openAnchors(c)">{{ t('performance_competencie.card.anchors_button') }}</VBtn>
              <VSpacer />
              <VBtn size="x-small" variant="text" icon="tabler-trash" @click="remove(c)" />
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!competencies.length" cols="12">
        <div class="text-center py-12 text-medium-emphasis">{{ t('performance_competencie.empty') }}</div>
      </VCol>
    </VRow>

    <!-- Create competency -->
    <VDialog v-model="dialog" max-width="500">
      <VCard>
        <VCardTitle>{{ t('performance_competencie.dialog_create.title') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('performance_competencie.dialog_create.name_label')" variant="outlined" class="mb-3" />
          <VSelect v-model="form.category" :items="categories" :label="t('performance_competencie.dialog_create.category_label')" variant="outlined" clearable class="mb-3" />
          <VTextarea v-model="form.description" :label="t('performance_competencie.dialog_create.description_label')" variant="outlined" rows="2" class="mb-3" />
          <VTextField v-model.number="form.max_level" type="number" :label="t('performance_competencie.dialog_create.max_level_label')" variant="outlined" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('performance_competencie.dialog_create.cancel') }}</VBtn><VBtn color="primary" :disabled="!form.name" @click="create">{{ t('performance_competencie.dialog_create.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Anchor editor -->
    <VDialog v-model="anchorDialog" max-width="540">
      <VCard>
        <VCardTitle>{{ t('performance_competencie.dialog_anchors.title', { name: anchorTarget?.name }) }}</VCardTitle>
        <VCardText>
          <div v-if="anchorTarget?.anchors.length" class="mb-3">
            <div v-for="a in anchorTarget.anchors" :key="a.id" class="d-flex gap-2 py-1 border-b">
              <VChip size="x-small" color="primary" variant="tonal">L{{ a.level }}</VChip>
              <div><strong class="text-body-2">{{ a.label }}</strong><div class="text-caption text-medium-emphasis">{{ a.behavior }}</div></div>
            </div>
          </div>
          <VRow>
            <VCol cols="4"><VTextField v-model.number="anchorForm.level" type="number" :label="t('performance_competencie.dialog_anchors.level_label')" variant="outlined" /></VCol>
            <VCol cols="8"><VTextField v-model="anchorForm.label" :label="t('performance_competencie.dialog_anchors.label_label')" variant="outlined" /></VCol>
          </VRow>
          <VTextarea v-model="anchorForm.behavior" :label="t('performance_competencie.dialog_anchors.behavior_label')" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="anchorDialog = false">{{ t('performance_competencie.dialog_anchors.close') }}</VBtn><VBtn color="primary" @click="saveAnchor">{{ t('performance_competencie.dialog_anchors.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Map to role -->
    <VDialog v-model="roleDialog" max-width="500">
      <VCard>
        <VCardTitle>{{ t('performance_competencie.dialog_role.title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="roleForm.competency_id" :items="competencies" item-title="name" item-value="id" :label="t('performance_competencie.dialog_role.competency_label')" variant="outlined" class="mb-3" />
          <VSelect v-model="roleForm.position_id" :items="positions" item-title="name" item-value="id" :label="t('performance_competencie.dialog_role.position_label')" variant="outlined" class="mb-3" />
          <VTextField v-model.number="roleForm.expected_level" type="number" :label="t('performance_competencie.dialog_role.expected_level_label')" variant="outlined" class="mb-3" />
          <VSwitch v-model="roleForm.is_critical" :label="t('performance_competencie.dialog_role.critical_label')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="roleDialog = false">{{ t('performance_competencie.dialog_role.cancel') }}</VBtn><VBtn color="primary" :disabled="!roleForm.competency_id || !roleForm.position_id" @click="saveRole">{{ t('performance_competencie.dialog_role.map') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
