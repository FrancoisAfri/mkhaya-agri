<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TemplatePreview from './partials/TemplatePreview.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Template {
  id: number; name: string; doc_type: string; accent_color: string | null; orientation: string
  page_size: string; font: string; layout: string; use_accent: boolean; footer_note: string | null; terms: string | null
  show_logo: boolean; show_bank_details: boolean; is_default: boolean
  logo_position: string; logo_height: number
}
const props = defineProps<{ templates: Template[]; logo: string | null }>()

const docTypes = [
  { title: t('acc.tpl_both'), value: 'both' },
  { title: t('acc.tpl_invoice'), value: 'invoice' },
  { title: t('acc.tpl_quote'), value: 'quote' },
  { title: t('acc.tpl_statement'), value: 'statement' },
]
/*
 * Four arrangements, not four colours.
 *
 * The old set — Classic, Modern, Bold, Minimal — described a title weight and an accent, so picking
 * one changed almost nothing about where the information went. These describe the shape of the
 * page, which is the only thing worth choosing between.
 */
const layouts = [
  { title: t('acc.tpl_layout_letterhead'), value: 'letterhead' },
  { title: t('acc.tpl_layout_statement'), value: 'statement' },
  { title: t('acc.tpl_layout_panel'), value: 'panel' },
  { title: t('acc.tpl_layout_minimal'), value: 'minimal' },
]
const fonts = [
  { title: t('acc.tpl_font_sans'), value: 'sans' },
  { title: t('acc.tpl_font_serif'), value: 'serif' },
  { title: t('acc.tpl_font_mono'), value: 'mono' },
]
const pageSizes = [{ title: 'A4', value: 'a4' }, { title: 'Letter', value: 'letter' }]
const logoPositions = [
  { title: t('acc.tpl_logo_none'), value: 'none', icon: 'tabler-photo-off' },
  { title: t('acc.tpl_logo_left'), value: 'left', icon: 'tabler-align-left' },
  { title: t('acc.tpl_logo_center'), value: 'center', icon: 'tabler-align-center' },
  { title: t('acc.tpl_logo_right'), value: 'right', icon: 'tabler-align-right' },
]

const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = useForm<any>({
  name: '', doc_type: 'both', accent_color: '#374151', use_accent: false, orientation: 'portrait', page_size: 'a4',
  font: 'sans', layout: 'letterhead', footer_note: '', terms: '', show_logo: true,
  show_bank_details: true, is_default: false, logo_position: 'left', logo_height: 60,
})

function openNew() {
  editingId.value = null
  form.reset()
  form.accent_color = '#374151'
  dialog.value = true
}
function openEdit(tp: Template) {
  editingId.value = tp.id
  Object.assign(form, {
    name: tp.name, doc_type: tp.doc_type, accent_color: tp.accent_color || '#374151', use_accent: tp.use_accent ?? false,
    orientation: tp.orientation || 'portrait', page_size: tp.page_size || 'a4',
    font: tp.font || 'sans', layout: tp.layout || 'letterhead', footer_note: tp.footer_note || '',
    terms: tp.terms || '', show_logo: tp.show_logo, show_bank_details: tp.show_bank_details,
    is_default: tp.is_default, logo_position: tp.logo_position || 'left', logo_height: tp.logo_height || 60,
  })
  dialog.value = true
}
const layoutLabel = (v: string) => layouts.find(l => l.value === v)?.title ?? v
const docTypeLabel = (v: string) => docTypes.find(d => d.value === v)?.title ?? v

function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value
    ? form.patch(route('finance.accounting.templates.update', editingId.value), opts)
    : form.post(route('finance.accounting.templates.store'), opts)
}
function makeDefault(tp: Template) {
  router.post(route('finance.accounting.templates.default', tp.id), {}, { preserveScroll: true })
}
function remove(tp: Template) {
  if (confirm(t('acc.tpl_delete_confirm', { name: tp.name }))) {
    router.delete(route('finance.accounting.templates.destroy', tp.id), { preserveScroll: true })
  }
}

/**
 * The real thing: a sample document rendered by the PDF engine with this template. Opened in a
 * new tab rather than an iframe so the browser's own PDF viewer handles paging and printing.
 * Only available once saved — the renderer needs a stored template to read.
 */
function openPdfPreview(id: number, doc: 'invoice' | 'statement' = 'invoice') {
  window.open(`${route('finance.accounting.templates.preview', id)}?doc=${doc}`, '_blank', 'noopener')
}

/** Minimal ignores the accent colour, so the picker should stop pretending otherwise. */
/*
 * Colour is off unless somebody turns it on.
 *
 * A financial document is read for its numbers, and every template shipped with a saturated accent
 * behind the title, the table header and the total — which is why they all looked like the same
 * page four times over. Minimal has no accent at all, by definition.
 */
const accentDisabled = computed(() => form.layout === 'minimal' || !form.use_accent)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('acc.templates_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('acc.templates_subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_template') }}</VBtn>
    </div>

    <VAlert v-if="!logo" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('acc.tpl_no_logo_notice') }}
      <template #append>
        <VBtn size="small" variant="text" @click="router.get(route('finance.accounting.settings'))">
          {{ t('acc.tpl_upload_logo') }}
        </VBtn>
      </template>
    </VAlert>

    <VRow>
      <VCol v-for="tp in templates" :key="tp.id" cols="12" sm="6" md="4" lg="3">
        <VCard border rounded="lg" class="h-100 d-flex flex-column">
          <VCardText class="pb-2">
            <!-- What the document actually looks like, not just its name -->
            <TemplatePreview
              :layout="tp.layout"
              :accent="tp.accent_color"
              :logo-position="tp.logo_position"
              :show-logo="tp.show_logo"
              :logo="logo"
              class="mb-3"
            />
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-body-1 font-weight-semibold">{{ tp.name }}</span>
              <VChip v-if="tp.is_default" size="x-small" color="success" variant="tonal">{{ t('acc.default_label') }}</VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ docTypeLabel(tp.doc_type) }} · {{ layoutLabel(tp.layout) }} ·
              {{ tp.orientation === 'landscape' ? t('acc.tpl_landscape') : t('acc.tpl_portrait') }}
            </div>
          </VCardText>
          <VSpacer />
          <!--
            Four labelled buttons did not fit the width of a card in a four-column grid, so they
            ran into each other and "Make default" was cut off mid-word — the action most likely
            to be wanted, and the one you could not read. Everything moves behind the overflow
            menu the customers table already uses, so the card ends in one control at a known
            width no matter how long the labels are in the reader's language.
          -->
          <VCardActions class="justify-end">
            <VMenu location="bottom end">
              <template #activator="{ props: mp }">
                <VBtn icon="tabler-dots-vertical" size="small" variant="text" v-bind="mp" :title="t('acc.actions')" />
              </template>
              <VList density="compact">
                <VListItem prepend-icon="tabler-pencil" :title="t('acc.edit')" @click="openEdit(tp)" />
                <VListItem prepend-icon="tabler-file-search" :title="t('acc.tpl_preview')" @click="openPdfPreview(tp.id)" />
                <VListItem v-if="!tp.is_default" prepend-icon="tabler-star" :title="t('acc.tpl_make_default')" @click="makeDefault(tp)" />
                <VListItem v-if="!tp.is_default" prepend-icon="tabler-trash" class="text-error" :title="t('acc.delete')" @click="remove(tp)" />
              </VList>
            </VMenu>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!templates.length" cols="12">
        <VCard border rounded="lg"><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.no_templates') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="900" scrollable>
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ editingId ? t('acc.edit_template') : t('acc.new_template') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow>
            <!-- Settings -->
            <VCol cols="12" md="7">
              <div class="d-flex gap-3 mb-3 flex-wrap">
                <VTextField v-model="form.name" :label="t('acc.template_name')" variant="outlined" density="comfortable" style="flex:1 1 220px" :error-messages="(form.errors as any).name" />
                <VSelect v-model="form.doc_type" :items="docTypes" :label="t('acc.applies_to')" variant="outlined" density="comfortable" style="max-width:200px" />
              </div>

              <!-- Layout: chosen by looking at it, the way you would pick stationery -->
              <label class="text-caption text-medium-emphasis d-block mb-2">{{ t('acc.tpl_layout') }}</label>
              <VRow dense class="mb-3">
                <VCol v-for="l in layouts" :key="l.value" cols="6" sm="3">
                  <div class="text-center" style="cursor:pointer" @click="form.layout = l.value">
                    <TemplatePreview
                      :layout="l.value"
                      :accent="form.accent_color"
                      :logo-position="form.logo_position"
                      :show-logo="form.show_logo"
                      :logo="logo"
                      :selected="form.layout === l.value"
                    />
                    <span class="text-caption" :class="form.layout === l.value ? 'text-primary font-weight-medium' : 'text-medium-emphasis'">
                      {{ l.title }}
                    </span>
                  </div>
                </VCol>
              </VRow>

              <!-- Logo placement -->
              <label class="text-caption text-medium-emphasis d-block mb-1">{{ t('acc.tpl_logo_position') }}</label>
              <VBtnToggle v-model="form.logo_position" mandatory density="comfortable" color="primary" variant="outlined" class="mb-3 flex-wrap">
                <VBtn v-for="p in logoPositions" :key="p.value" :value="p.value" size="small" :prepend-icon="p.icon">{{ p.title }}</VBtn>
              </VBtnToggle>

              <div v-if="form.logo_position !== 'none' && form.show_logo" class="mb-2">
                <label class="text-caption text-medium-emphasis d-block">{{ t('acc.tpl_logo_height', { n: form.logo_height }) }}</label>
                <VSlider v-model="form.logo_height" :min="20" :max="160" :step="5" color="primary" density="compact" hide-details thumb-label />
              </div>

              <VRow dense class="mb-1">
                <VCol cols="12" sm="6"><VSelect v-model="form.font" :items="fonts" :label="t('acc.tpl_font')" variant="outlined" density="comfortable" /></VCol>
                <VCol cols="12" sm="6"><VSelect v-model="form.page_size" :items="pageSizes" :label="t('acc.tpl_page_size')" variant="outlined" density="comfortable" /></VCol>
              </VRow>

              <div class="mb-3">
                <label class="text-caption text-medium-emphasis d-block mb-1">{{ t('acc.tpl_orientation') }}</label>
                <VBtnToggle v-model="form.orientation" mandatory density="comfortable" color="primary" variant="outlined">
                  <VBtn value="portrait" size="small" prepend-icon="tabler-rectangle-vertical">{{ t('acc.tpl_portrait') }}</VBtn>
                  <VBtn value="landscape" size="small" prepend-icon="tabler-rectangle">{{ t('acc.tpl_landscape') }}</VBtn>
                </VBtnToggle>
              </div>

              <div class="d-flex align-center gap-3 mb-3">
                <!-- Off by default: the document is set in ink, and a colour is a decision. -->
                <VSwitch
                  v-model="form.use_accent" color="primary" density="compact" hide-details
                  :label="t('acc.tpl_use_accent')" :disabled="form.layout === 'minimal'"
                />
                <input v-model="form.accent_color" type="color" :disabled="accentDisabled" style="width:44px;height:32px;border:none;background:none;cursor:pointer">
                <span class="text-caption text-medium-emphasis">{{ accentDisabled ? t('acc.tpl_mono') : form.accent_color }}</span>
              </div>

              <VTextarea v-model="form.terms" :label="t('acc.terms_conditions')" :placeholder="t('acc.terms_ph')" variant="outlined" density="comfortable" rows="3" auto-grow class="mb-2" />
              <VTextField v-model="form.footer_note" :label="t('acc.footer_note')" variant="outlined" density="comfortable" class="mb-2" />

              <div class="d-flex flex-wrap gap-4">
                <VSwitch v-model="form.show_logo" :label="t('acc.tpl_show_logo')" color="primary" density="compact" hide-details />
                <VSwitch v-model="form.show_bank_details" :label="t('acc.tpl_show_bank')" color="primary" density="compact" hide-details />
                <VSwitch v-model="form.is_default" :label="t('acc.make_default')" color="success" density="compact" hide-details />
              </div>
            </VCol>

            <!-- Live preview of the current settings -->
            <VCol cols="12" md="5">
              <div class="position-sticky" style="top:0">
                <label class="text-caption text-medium-emphasis d-block mb-2">{{ t('acc.tpl_live_preview') }}</label>
                <TemplatePreview
                  :layout="form.layout"
                  :accent="form.accent_color"
                  :logo-position="form.logo_position"
                  :show-logo="form.show_logo"
                  :logo="logo"
                />
                <p class="text-caption text-medium-emphasis mt-2 mb-2">{{ t('acc.tpl_preview_hint') }}</p>
                <div class="d-flex gap-2 flex-wrap">
                  <VBtn
                    size="small" variant="tonal" prepend-icon="tabler-file-invoice"
                    :disabled="!editingId" @click="openPdfPreview(editingId!, 'invoice')"
                  >
                    {{ t('acc.tpl_preview_invoice') }}
                  </VBtn>
                  <VBtn
                    size="small" variant="tonal" prepend-icon="tabler-file-text"
                    :disabled="!editingId" @click="openPdfPreview(editingId!, 'statement')"
                  >
                    {{ t('acc.tpl_preview_statement') }}
                  </VBtn>
                </div>
                <p v-if="!editingId" class="text-caption text-disabled mt-2 mb-0">{{ t('acc.tpl_preview_after_save') }}</p>
              </div>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ editingId ? t('acc.save') : t('acc.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
