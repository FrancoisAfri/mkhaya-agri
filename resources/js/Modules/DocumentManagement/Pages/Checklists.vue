<script setup lang="ts">
/**
 * What should be on file, against what is.
 *
 * The repository can say what it holds. This is the only screen that says what it is missing, which
 * is the thing people are actually asked about — "is the supplier pack complete", "what are we
 * still waiting on for Thandi".
 *
 * Packs still outstanding come first, and a finished one collapses to a line. The list is read to
 * find the gaps, and a completed pack shown in full is thirty rows of nothing to do.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Item {
  uuid: string; title: string; description: string | null; category: string | null
  required: boolean; satisfied: boolean
  document: string | null; document_title: string | null; requested: boolean
}

interface Checklist {
  uuid: string; name: string; subject: string | null
  folder: string | null; folder_uuid: string | null
  due_at: string | null; overdue: boolean; completed_at: string | null
  progress: { total: number; required: number; satisfied: number; outstanding: number; percent: number }
  items: Item[]
}

defineProps<{
  checklists: Checklist[]
  templates: { uuid: string; name: string; description: string | null; items: { title: string; category: string | null; required: boolean }[] }[]
  folders: { uuid: string; name: string; depth: number }[]
  categories: { uuid: string; name: string }[]
  people: { id: number; name: string | null }[]
  can_manage: boolean
}>()

const starting = ref(false)
const buildingTemplate = ref(false)
const chasing = ref<Checklist | null>(null)

const startForm = useForm({
  template: '', name: '',
  // Picked rather than typed: a typed name is one spelling away from being unfindable, and
  // picking several starts a pack each.
  people: [] as number[],
  subject: '',
  folder: '', due_at: '',
})
const chaseForm = useForm({ people: [] as number[], due_at: '' })

const templateForm = useForm({
  name: '', description: '',
  items: [{ title: '', category: '', required: true }] as { title: string; category: string; required: boolean }[],
})

function start() {
  startForm.post(route('document-management.checklists.store'), {
    preserveScroll: true,
    onSuccess: () => { starting.value = false; startForm.reset() },
  })
}

function saveTemplate() {
  templateForm.post(route('document-management.checklists.templates.store'), {
    preserveScroll: true,
    onSuccess: () => {
      buildingTemplate.value = false
      templateForm.reset()
      templateForm.items = [{ title: '', category: '', required: true }]
    },
  })
}

function chase() {
  if (!chasing.value) return

  chaseForm.post(route('document-management.checklists.chase', chasing.value.uuid), {
    preserveScroll: true,
    onSuccess: () => { chasing.value = null; chaseForm.reset() },
  })
}

function clearItem(item: Item) {
  router.post(
    route('document-management.checklists.items.satisfy', item.uuid),
    { clear: true },
    { preserveScroll: true },
  )
}
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ d('checklists_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ d('checklists_subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn v-if="can_manage" variant="text" prepend-icon="mdi-playlist-edit" @click="buildingTemplate = true">
        {{ d('checklists_new_template') }}
      </VBtn>
      <VBtn
        color="primary" prepend-icon="mdi-format-list-checks"
        :disabled="!templates.length" @click="starting = true"
      >{{ d('checklists_start') }}</VBtn>
    </div>

    <VAlert v-if="!templates.length" type="info" variant="tonal" density="compact" class="mb-4">
      {{ d('checklists_no_templates') }}
    </VAlert>

    <VCard v-for="checklist in checklists" :key="checklist.uuid" elevation="0" border class="mb-3">
      <VCardTitle class="text-subtitle-1 d-flex align-center flex-wrap ga-2">
        <span>{{ checklist.name }}</span>
        <span v-if="checklist.subject" class="text-body-2 text-medium-emphasis">· {{ checklist.subject }}</span>
        <VChip
          v-if="checklist.completed_at" size="x-small" variant="tonal" color="success"
        >{{ d('checklists_complete_on', { date: checklist.completed_at }) }}</VChip>
        <VChip
          v-else-if="checklist.overdue" size="x-small" variant="tonal" color="error"
        >{{ d('requests_due', { date: checklist.due_at }) }}</VChip>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">
          {{ d('checklists_n_of_m', { n: checklist.progress.satisfied, m: checklist.progress.required }) }}
        </span>
      </VCardTitle>

      <VProgressLinear
        :model-value="checklist.progress.percent" height="4"
        :color="checklist.progress.percent === 100 ? 'success' : 'primary'"
      />

      <VCardText>
        <div class="text-caption text-medium-emphasis mb-2">
          <template v-if="checklist.folder">{{ d('checklists_filed_in', { folder: checklist.folder }) }}</template>
          <template v-else>{{ d('checklists_no_folder') }}</template>
        </div>

        <VList density="compact">
          <VListItem v-for="item in checklist.items" :key="item.uuid" class="px-0">
            <template #prepend>
              <VIcon
                size="18" class="me-2"
                :icon="item.satisfied ? 'mdi-check-circle' : item.required ? 'mdi-circle-outline' : 'mdi-circle-small'"
                :color="item.satisfied ? 'success' : item.required ? 'warning' : undefined"
              />
            </template>

            <VListItemTitle class="text-body-2">
              {{ item.title }}
              <span v-if="!item.required" class="text-caption text-medium-emphasis">
                · {{ d('checklists_optional') }}
              </span>
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              <template v-if="item.satisfied && item.document_title">{{ item.document_title }}</template>
              <template v-else-if="item.category">{{ d('checklists_expects', { category: item.category }) }}</template>
              <template v-else>{{ d('checklists_expects_anything') }}</template>
              <span v-if="!item.satisfied && item.requested"> · {{ d('checklists_already_asked') }}</span>
            </VListItemSubtitle>

            <template #append>
              <VBtn
                v-if="item.satisfied && item.document" size="x-small" variant="text"
                :href="route('document-management.documents.show', item.document)"
              >{{ d('view') }}</VBtn>
              <VBtn
                v-if="item.satisfied" size="x-small" variant="text" @click="clearItem(item)"
              >{{ d('checklists_unlink') }}</VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>

      <VCardActions>
        <VBtn
          v-if="checklist.folder_uuid" size="small" variant="text"
          :href="route('document-management.index', { folder: checklist.folder_uuid })"
        >{{ d('checklists_open_folder') }}</VBtn>
        <VSpacer />
        <!--
          Six missing documents asked for in one act. Asking one at a time is where the sixth gets
          forgotten, which is the failure this whole screen exists to prevent.
        -->
        <VBtn
          v-if="checklist.progress.outstanding" size="small" variant="tonal" color="primary"
          @click="chasing = checklist"
        >{{ d('checklists_chase', { n: checklist.progress.outstanding }) }}</VBtn>
      </VCardActions>
    </VCard>

    <VCard v-if="!checklists.length" elevation="0" border>
      <VCardText class="text-body-2 text-medium-emphasis">{{ d('checklists_none') }}</VCardText>
    </VCard>

    <!-- ── Starting a pack ──────────────────────────────────────────────── -->
    <VDialog v-model="starting" max-width="560">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('checklists_start') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VSelect
            v-model="startForm.template" :label="d('checklists_which_template')"
            :error-messages="startForm.errors.template"
            :items="templates.map(t => ({ value: t.uuid, title: t.name }))"
          />
          <VSelect
            v-model="startForm.people" multiple chips closable-chips
            :label="d('checklists_people')" :hint="d('checklists_people_hint')" persistent-hint
            :items="people.map(p => ({ value: p.id, title: p.name ?? '' }))"
          />
          <!--
            Kept alongside the picker, because a pack is as often about a supplier, a tender or a
            property as it is about a person — and none of those are in the staff list.
          -->
          <VTextField
            v-model="startForm.subject" :label="d('checklists_subject')"
            :hint="d('checklists_subject_hint')" persistent-hint
          />
          <VSelect
            v-model="startForm.folder" clearable :label="d('checklists_folder')"
            :hint="d('checklists_folder_hint')" persistent-hint
            :items="folders.map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name }))"
          />
          <VTextField v-model="startForm.due_at" type="date" :label="d('requests_by_when')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="starting = false">{{ d('cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="startForm.processing"
            :disabled="!startForm.template || (!startForm.people.length && !startForm.subject.trim())"
            @click="start"
          >{{ d('checklists_start') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Writing the rule ─────────────────────────────────────────────── -->
    <VDialog v-model="buildingTemplate" max-width="680">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('checklists_new_template') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VTextField
            v-model="templateForm.name" :label="d('checklists_template_name')"
            :error-messages="templateForm.errors.name" :hint="d('checklists_template_hint')" persistent-hint
          />
          <VTextarea v-model="templateForm.description" rows="2" :label="d('field_description')" />

          <div v-for="(item, index) in templateForm.items" :key="index" class="d-flex ga-2 align-center">
            <VTextField v-model="item.title" density="compact" hide-details :label="d('checklists_item')" />
            <VSelect
              v-model="item.category" density="compact" hide-details clearable style="max-width:200px"
              :label="d('field_category')" :items="categories.map(c => ({ value: c.uuid, title: c.name }))"
            />
            <VCheckbox v-model="item.required" density="compact" hide-details :label="d('checklists_required')" />
            <VBtn
              icon="mdi-close" size="x-small" variant="text"
              :disabled="templateForm.items.length === 1"
              @click="templateForm.items.splice(index, 1)"
            />
          </div>

          <VBtn
            size="small" variant="text" prepend-icon="mdi-plus"
            @click="templateForm.items.push({ title: '', category: '', required: true })"
          >{{ d('checklists_add_item') }}</VBtn>

          <p class="text-caption text-medium-emphasis mb-0">{{ d('checklists_category_hint') }}</p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="buildingTemplate = false">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :loading="templateForm.processing" @click="saveTemplate">{{ d('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Asking for what is missing ───────────────────────────────────── -->
    <VDialog :model-value="chasing !== null" max-width="520" @update:model-value="chasing = null">
      <VCard v-if="chasing">
        <VCardTitle class="text-subtitle-1">
          {{ d('checklists_chase', { n: chasing.progress.outstanding }) }}
        </VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ d('checklists_chase_hint') }}</p>
          <VSelect
            v-model="chaseForm.people" multiple chips closable-chips
            :label="d('requests_who')" :error-messages="chaseForm.errors.people"
            :items="people.map(p => ({ value: p.id, title: p.name ?? '' }))"
          />
          <VTextField v-model="chaseForm.due_at" type="date" :label="d('requests_by_when')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="chasing = null">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :loading="chaseForm.processing" @click="chase">{{ d('requests_send') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
