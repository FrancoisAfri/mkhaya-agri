<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Template = {
  id: number
  name: string
  description: string | null
  is_default: boolean
  is_active: boolean
  sections_count: number
}

const props = defineProps<{ templates: Template[] }>()

const drawer    = ref(false)
const delOpen   = ref(false)
const delItem   = ref<Template | null>(null)
const editing   = ref<Partial<Template> | null>(null)

function openDrawer(tmpl?: Template) {
  editing.value = tmpl ? { ...tmpl } : { name: '', description: null, is_default: false, is_active: true }
  drawer.value = true
}

function save() {
  if (!editing.value) return
  const tmpl = editing.value
  if (tmpl.id) {
    router.patch(route('performance.templates.update', tmpl.id), tmpl, { onSuccess: () => { drawer.value = false } })
  } else {
    router.post(route('performance.templates.store'), tmpl, { onSuccess: () => { drawer.value = false } })
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.templates.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ t('pa.templates.subtitle') }}
        </p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openDrawer()">{{ t('pa.templates.new') }}</VBtn>
    </div>

    <VRow>
      <VCol v-if="props.templates.length === 0" cols="12">
        <VCard class="text-center pa-8 text-medium-emphasis">
          {{ t('pa.templates.empty') }}
        </VCard>
      </VCol>
      <VCol v-for="tmpl in props.templates" :key="tmpl.id" cols="12" sm="6" md="4">
        <VCard class="h-100">
          <VCardTitle class="d-flex align-center">
            <span class="text-truncate">{{ tmpl.name }}</span>
            <VSpacer />
            <VChip v-if="tmpl.is_default" size="x-small" color="primary" class="ml-1">{{ t('pa.common.default_badge') }}</VChip>
          </VCardTitle>
          <VCardSubtitle v-if="tmpl.description">{{ tmpl.description }}</VCardSubtitle>
          <VCardText>
            <div class="d-flex align-center gap-2">
              <VIcon icon="ri-layout-5-line" size="16" color="medium-emphasis" />
              <span class="text-body-2">{{ tmpl.sections_count }} section{{ tmpl.sections_count !== 1 ? 's' : '' }}</span>
              <VSpacer />
              <VChip size="x-small" :color="tmpl.is_active ? 'success' : 'default'">
                {{ tmpl.is_active ? t('pa.common.active') : t('pa.common.inactive') }}
              </VChip>
            </div>
          </VCardText>
          <VCardActions>
            <VBtn size="small" color="primary" variant="tonal" @click="router.visit(route('performance.templates.show', tmpl.id))">
              {{ t('pa.templates.design_form') }}
            </VBtn>
            <VBtn size="small" variant="text" @click="openDrawer(tmpl)">{{ t('pa.common.edit') }}</VBtn>
            <VBtn size="small" variant="text" color="error" @click="delItem = tmpl; delOpen = true">{{ t('pa.common.delete') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- Create/Edit Drawer -->
    <VNavigationDrawer v-model="drawer" location="right" temporary width="420">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ editing?.id ? t('pa.templates.edit') : t('pa.templates.new') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="drawer = false" />
      </div>
      <div class="pa-4" v-if="editing">
        <VTextField v-model="editing.name" :label="t('pa.templates.name')" class="mb-3" />
        <VTextField v-model="editing.description" :label="t('pa.templates.description')" class="mb-3" />
        <VSwitch v-model="editing.is_default" :label="t('pa.templates.set_default')" inset color="primary" class="mb-1" />
        <VSwitch v-model="editing.is_active" :label="t('pa.common.active')" inset color="success" class="mb-4" />
        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="drawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="save">{{ editing?.id ? t('pa.templates.update') : t('pa.templates.create') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Delete confirm -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard v-if="delItem">
        <VCardTitle>{{ t('pa.templates.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.templates.delete_confirm', { name: delItem.name }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" @click="router.delete(route('performance.templates.destroy', delItem.id), { onSuccess: () => { delOpen = false; delItem = null } })">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
