<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AddHowTo from './Partials/Add.vue'
import type { CategoryProperties, ModuleProperties, RibbonProperties, HowToProperties } from './Store/type'
import { useHowToStore } from './Store/Store'

const { t } = useI18n()

const props = defineProps<{ categories: CategoryProperties[]; currentModuleId?: number }>()
const store = useHowToStore()

const activeCategory  = ref<CategoryProperties | null>(null)
const activeModule    = ref<ModuleProperties | null>(null)
const isAddOpen       = ref(false)
const selectedRibbon  = ref<RibbonProperties | null>(null)

const activeModules = computed<ModuleProperties[]>(() => activeCategory.value?.modules ?? [])

const initDefaults = () => {
  if (!props.categories.length) return
  if (props.currentModuleId) {
    for (const cat of props.categories) {
      const mod = cat.modules?.find((m) => m.id === props.currentModuleId)
      if (mod) { activeCategory.value = cat; activeModule.value = mod; return }
    }
  }
  activeCategory.value = props.categories[0]
  activeModule.value = activeCategory.value.modules?.[0] ?? null
}

watch(() => activeModule.value, (mod) => { if (mod) store.fetchByModule(mod.id) }, { immediate: false })
watch(() => activeCategory.value, (cat) => { activeModule.value = cat?.modules?.[0] ?? null })

onMounted(() => { initDefaults(); if (activeModule.value) store.startPolling(activeModule.value.id, 30) })
onUnmounted(() => store.stopPolling())

const selectCategory = (cat: CategoryProperties) => { activeCategory.value = cat }
const selectModule = (mod: ModuleProperties) => { activeModule.value = mod; store.stopPolling(); store.startPolling(mod.id, 30) }
const openAdd = (ribbon: RibbonProperties) => { selectedRibbon.value = ribbon; isAddOpen.value = true }
const goToView = (ribbon: RibbonProperties) => { if (!activeModule.value) return; router.visit(`/how-to/view/${activeModule.value.id}/${ribbon.id}`) }
const onCreated = (item: HowToProperties) => { store.addHowTo(item) }
</script>

<template>
  <section class="bg-[#f8f8ff] min-h-screen">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">
            <i class="fa-solid fa-circle-question me-2" style="color:rgb(var(--v-theme-primary))" />
            {{ t('howto.management.page_title') }}
          </h3>
          <p class="text-medium-emphasis mb-0 text-caption">
            <template v-if="activeCategory">
              <span class="font-weight-medium">{{ activeCategory.name }}</span>
              <template v-if="activeModule">&nbsp;<i class="fa-solid fa-chevron-right" style="font-size:9px" />&nbsp;<span>{{ activeModule.name }}</span></template>
            </template>
            <template v-else>{{ t('howto.management.subtitle_default') }}</template>
          </p>
        </div>
        <VBreadcrumbs :items="[{ title: t('howto.management.breadcrumb_home'), href: '/', disabled: false }, { title: t('howto.management.breadcrumb_howto'), disabled: true }]" density="compact" class="pa-0">
          <template #divider><i class="fa-solid fa-chevron-right" style="font-size:10px" /></template>
        </VBreadcrumbs>
      </div>
      <VDivider class="mb-5" />

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VTabs v-if="props.categories.length" show-arrows color="primary" class="px-2">
          <VTab v-for="cat in props.categories" :key="cat.id" :value="cat.id" :active="activeCategory?.id === cat.id" @click="selectCategory(cat)">
            <i v-if="cat.icon" :class="`fa-solid fa-${cat.icon} me-2`" style="font-size:13px" />{{ cat.name }}
          </VTab>
        </VTabs>
      </VCard>

      <VCard elevation="0" border rounded="lg">
        <div v-if="activeModules.length" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
          <VTabs color="secondary" class="px-2">
            <VTab v-for="mod in activeModules" :key="mod.id" :value="mod.id" :active="activeModule?.id === mod.id" @click="selectModule(mod)">
              <i v-if="mod.font_awesome" :class="`fa-solid fa-${mod.font_awesome} me-2`" style="font-size:12px" />{{ mod.name }}
            </VTab>
          </VTabs>
        </div>

        <VCardText class="pa-5">
          <div v-if="store.loading" class="d-flex justify-center align-center py-16">
            <VProgressCircular indeterminate color="primary" size="40" width="3" />
            <span class="ms-4 text-medium-emphasis">{{ t('howto.management.loading') }}</span>
          </div>
          <div v-else-if="!activeModule" class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
            <i class="fa-solid fa-layer-group mb-3" style="font-size:48px;opacity:.2" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('howto.management.no_module') }}</p>
            <p class="text-caption">{{ t('howto.management.no_module_desc') }}</p>
          </div>
          <div v-else-if="!store.ribbons.length" class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
            <i class="fa-solid fa-ribbon mb-3" style="font-size:48px;opacity:.2" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('howto.management.no_ribbons') }}</p>
            <p class="text-caption">{{ t('howto.management.no_ribbons_desc') }}</p>
          </div>
          <VRow v-else dense>
            <VCol v-for="ribbon in store.ribbons" :key="ribbon.id" cols="12" sm="6" lg="4">
              <VCard border elevation="0" rounded="lg" class="ribbon-card h-100" :class="{ 'ribbon-card--inactive': !ribbon.active }">
                <VCardText class="pa-4 d-flex flex-column h-100">
                  <div class="d-flex align-center gap-2 mb-3">
                    <div class="d-flex align-center justify-center rounded-md flex-shrink-0" style="width:34px;height:34px;background:rgba(var(--v-theme-primary),0.08)">
                      <i v-if="ribbon.font_awesome" :class="`fa-solid fa-${ribbon.font_awesome}`" style="font-size:15px;color:rgb(var(--v-theme-primary))" />
                      <i v-else class="fa-solid fa-ribbon" style="font-size:15px;color:rgb(var(--v-theme-primary))" />
                    </div>
                    <div class="flex-grow-1 min-w-0">
                      <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ ribbon.ribbon_name }}</p>
                      <p v-if="ribbon.description" class="text-caption text-medium-emphasis mb-0 text-truncate">{{ ribbon.description }}</p>
                    </div>
                    <VChip :color="ribbon.active ? 'success' : 'default'" size="x-small" variant="tonal" label class="flex-shrink-0">
                      {{ ribbon.active ? t('howto.management.active') : t('howto.management.off') }}
                    </VChip>
                  </div>
                  <div class="d-flex align-center gap-2 mb-4 flex-grow-1">
                    <VChip size="small" variant="tonal" color="primary" prepend-icon="tabler-help">
                      {{ store.getHowTosForRibbon(ribbon.id).length }} {{ t('howto.management.howto_count', { count: store.getHowTosForRibbon(ribbon.id).length }) }}
                    </VChip>
                    <p v-if="store.getHowTosForRibbon(ribbon.id).length" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width:140px">{{ store.getHowTosForRibbon(ribbon.id)[0].question }}</p>
                    <p v-else class="text-caption text-medium-emphasis mb-0 font-italic">{{ t('howto.management.no_questions') }}</p>
                  </div>
                  <div class="d-flex align-center justify-end gap-2 pt-3" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                    <VBtn variant="tonal" color="secondary" size="small" rounded="md" :disabled="!ribbon.active" @click="goToView(ribbon)"><i class="fa-solid fa-eye me-1" style="font-size:12px" />{{ t('howto.management.view') }}</VBtn>
                    <VBtn variant="tonal" color="primary" size="small" rounded="md" :disabled="!ribbon.active" @click="openAdd(ribbon)"><i class="fa-solid fa-plus me-1" style="font-size:12px" />{{ t('howto.management.add') }}</VBtn>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VContainer>

    <AddHowTo v-if="selectedRibbon && activeModule" v-model:isDrawerOpen="isAddOpen" :module-id="activeModule.id" :ribbon="selectedRibbon" @created="onCreated" />

    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2">
        <i :class="{ 'fa-solid fa-circle-check': store.snackColor === 'success', 'fa-solid fa-trash': store.snackColor === 'error', 'fa-solid fa-circle-info': store.snackColor === 'warning' }" style="font-size:16px" />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>
  </section>
</template>

<style scoped>
.ribbon-card { transition: box-shadow 0.2s ease, transform 0.15s ease; }
.ribbon-card:hover { box-shadow: 0 4px 20px rgba(var(--v-theme-primary), 0.12); transform: translateY(-2px); }
.ribbon-card--inactive { opacity: 0.6; }
:deep(.v-tab) { font-size: 0.8125rem; text-transform: none; letter-spacing: 0; }
</style>
