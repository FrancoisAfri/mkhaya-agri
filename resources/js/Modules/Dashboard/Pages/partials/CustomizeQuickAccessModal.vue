<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { Ribbon } from '../Store/type'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props { modelValue: boolean; accessibleRibbons: Ribbon[]; userRibbonIds: number[]; userQuickAccess: Ribbon[] }
interface Emit { (e: 'update:modelValue', value: boolean): void; (e: 'updated'): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const loading = ref(false)
const errorMsg = ref('')
const searchQuery = ref('')
const selectedMap = ref<Record<number, boolean>>({})
const nameMap = ref<Record<number, string>>({})
const toggling = ref<Record<number, boolean>>({})

const isModalOpen = computed({ get: () => props.modelValue, set: (val) => emit('update:modelValue', val) })

watch(() => props.modelValue, (open) => {
    if (open) {
        searchQuery.value = ''; errorMsg.value = '';
        selectedMap.value = Object.fromEntries(props.accessibleRibbons.map(r => [r.id, props.userRibbonIds.includes(r.id)]));
        nameMap.value = Object.fromEntries(props.accessibleRibbons.map(r => [r.id, ((props.userQuickAccess ?? []).find(u => u.id === r.id) as any)?.pivot?.name ?? r.ribbon_name ?? '']));
    }
}, { immediate: true })

watch(() => props.userRibbonIds, (ids) => { props.accessibleRibbons.forEach(r => { selectedMap.value[r.id] = ids.includes(r.id) }) })
watch(() => props.userQuickAccess, () => { props.accessibleRibbons.forEach(r => { nameMap.value[r.id] = ((props.userQuickAccess ?? []).find(u => u.id === r.id) as any)?.pivot?.name ?? r.ribbon_name ?? '' }) })

const filteredRibbons = computed(() => { if (!searchQuery.value.trim()) return props.accessibleRibbons; const q = searchQuery.value.toLowerCase(); return props.accessibleRibbons.filter(r => r.ribbon_name?.toLowerCase().includes(q) || r.module?.name?.toLowerCase().includes(q)) })
const selectedCount = computed(() => Object.values(selectedMap.value).filter(Boolean).length)
const isSelected = (ribbonId: number) => !!selectedMap.value[ribbonId]

const toggleRibbon = async (ribbon: Ribbon) => {
    if (toggling.value[ribbon.id]) return; toggling.value[ribbon.id] = true;
    const wasSelected = selectedMap.value[ribbon.id]; selectedMap.value[ribbon.id] = !wasSelected;
    if (!wasSelected && !nameMap.value[ribbon.id]) { nameMap.value[ribbon.id] = ribbon.ribbon_name ?? '' }
    try {
        if (!wasSelected) { await axios.post('/quick-access/user/add', { ribbon_ids: [ribbon.id], names: { [ribbon.id]: nameMap.value[ribbon.id] || ribbon.ribbon_name || '' } }) }
        else { await axios.delete('/quick-access/user/remove', { data: { ribbon_ids: [ribbon.id] } }) }
        emit('updated')
    } catch { selectedMap.value[ribbon.id] = wasSelected; errorMsg.value = t('home_page.quick_links_modal.error') }
    finally { toggling.value[ribbon.id] = false }
}

const saveRibbonName = async (ribbon: Ribbon) => { if (!isSelected(ribbon.id) || toggling.value[ribbon.id]) return; toggling.value[ribbon.id] = true; try { await axios.post('/quick-access/user/add', { ribbon_ids: [ribbon.id], names: { [ribbon.id]: nameMap.value[ribbon.id] || ribbon.ribbon_name || '' } }); emit('updated') } catch { errorMsg.value = t('home_page.quick_links_modal.error_save') } finally { toggling.value[ribbon.id] = false } }

const handleClose = () => emit('update:modelValue', false)
</script>

<template>
    <VDialog v-model="isModalOpen" max-width="480" persistent scrollable>
        <VCard rounded="lg" elevation="0">
            <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                <div class="d-flex align-center gap-3">
                    <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:38px;height:38px;background:#ede9fe"><i class="fa-solid fa-wand-magic-sparkles" style="color:#7c3aed" /></div>
                    <div><p class="text-body-1 font-weight-semibold mb-0">{{ t('home_page.quick_links_modal.title') }}</p><p class="text-caption text-medium-emphasis mb-0"><span class="font-weight-medium" style="color:#7c3aed">{{ selectedCount }}</span> / {{ props.accessibleRibbons.length }} {{ t('home_page.quick_links_modal.selected_count', { count: selectedCount }) }}</p></div>
                </div>
                <VBtn icon="mdi-close" variant="text" size="small" @click="handleClose" />
            </div>
            <div class="px-6 py-4"><VTextField v-model="searchQuery" :placeholder="t('home_page.quick_links_modal.search_placeholder')" prepend-inner-icon="mdi-magnify" variant="outlined" density="compact" hide-details clearable /></div>
            <VAlert v-if="errorMsg" type="error" variant="tonal" density="compact" class="mx-6 mb-3" closable @click:close="errorMsg = ''">{{ errorMsg }}</VAlert>
            <VCardText class="pa-0" style="max-height:420px;overflow-y:auto">
                <div v-if="filteredRibbons.length === 0" class="pa-8 text-center text-medium-emphasis"><i class="fa-regular fa-folder-open fa-2x mb-3 d-block" /><p class="text-caption">{{ t('home_page.quick_links_modal.no_shortcuts') }}</p></div>
                <VList v-else lines="two" class="py-1">
                    <VListItem v-for="ribbon in filteredRibbons" :key="ribbon.id" class="px-5 py-2" rounded="0">
                        <template #prepend><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:34px;height:34px;background:rgba(var(--v-theme-primary),0.08)"><i :class="`fa-solid fa-${ribbon.font_awesome || 'link'}`" style="font-size:15px;color:rgb(var(--v-theme-primary))" /></div></template>
                        <VListItemTitle class="text-body-2 font-weight-medium d-flex align-center gap-2">{{ ribbon.ribbon_name || 'Untitled' }}<span v-if="isSelected(ribbon.id)" class="text-xs font-weight-semibold px-2 py-0.5 rounded text-white" style="background-color:#7c3aed"><i class="fa-solid fa-check me-1" />{{ t('home_page.quick_links_modal.pinned') }}</span></VListItemTitle>
                        <VListItemSubtitle class="text-caption">{{ ribbon.module?.name || 'Module' }}<span v-if="ribbon.description" class="ms-2 text-xs opacity-70">{{ ribbon.description }}</span></VListItemSubtitle>
                        <div v-if="isSelected(ribbon.id)" class="mt-2 px-2"><VTextField v-model="nameMap[ribbon.id]" :label="t('home_page.quick_links_modal.custom_label')" :placeholder="t('home_page.quick_links_modal.custom_label')" dense hide-details variant="outlined" :disabled="toggling[ribbon.id]" @blur="saveRibbonName(ribbon)" /></div>
                        <template #append><VBtn :icon="isSelected(ribbon.id) ? 'mdi-check-circle' : 'mdi-plus-circle-outline'" :color="isSelected(ribbon.id) ? 'success' : 'default'" variant="text" size="small" :loading="toggling[ribbon.id]" @click="toggleRibbon(ribbon)" /></template>
                    </VListItem>
                </VList>
            </VCardText>
            <div class="px-6 py-4 d-flex justify-end" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"><VBtn variant="tonal" rounded="md" @click="handleClose">{{ t('home_page.quick_links_modal.done') }}</VBtn></div>
        </VCard>
    </VDialog>
</template>

<style scoped>
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(var(--v-border-color), 0.4); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(var(--v-border-color), 0.6); }
</style>
