<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useOrganisationStore, type UnitNode } from './Store/store'
import AddUnit from './partials/Add.vue'
import EditUnit from './partials/Edit.vue'
import TreeNode from './partials/TreeNode.vue'
import TreeGraph from './partials/TreeGraph.vue'
import OrganigramTab from './partials/OrganigramTab.vue'

const { t } = useI18n()

const props = defineProps<{
    tree: UnitNode[]
    companyId: number
    unitTypes: string[]
    users: any[]
}>()

const store = useOrganisationStore()
const viewMode = ref<'tree' | 'graph' | 'organigram'>('tree')

watch(() => props.tree, val => store.setTree(val, props.companyId, props.unitTypes, props.users), { immediate: true })

const typeColor: Record<string, string> = {
    division: '#6366f1',
    department: '#0d9488',
    team: '#f59e0b',
    section: '#ec4899',
    unit: '#64748b',
}
const getColor = (type: string | null) => typeColor[type ?? ''] ?? '#94a3b8'

let dragId: number | null = null
const onDragStart = (id: number) => { dragId = id }

const onDrop = (targetId: number | null, e: DragEvent) => {
    e.preventDefault()
    if (dragId === null || dragId === targetId) return
    router.post(`/settings/organization/${dragId}/move`, { parent_id: targetId, sort_order: 0 }, {
        preserveScroll: true,
        onSuccess: () => { dragId = null; router.reload({ only: ['tree'] }) },
    })
}
const onDragOver = (e: DragEvent) => e.preventDefault()

const confirmDelete = (node: UnitNode) => store.openDelete(node)
const doDelete = () => {
    if (!store.deleteNode) return
    const id = store.deleteNode.id
    store.closeDelete()
    router.delete(`/settings/organization/delete/${id}`, {
        preserveScroll: true,
        onSuccess: () => { store.removeNodeFromTree(id); store.showSnack(t('organisation_structure_page.unit_deleted'), 'error') },
    })
}
</script>

<template>
    <VContainer fluid class="pa-4 pa-sm-6">

        <!-- ── Page Header ── -->
        <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
            <div>
                <h3 class="text-h5 font-weight-bold mb-1">{{ t('organisation_structure_page.title') }}</h3>
                <p class="text-body-2 text-medium-emphasis mb-0">
                    {{ t('organisation_structure_page.subtitle') }}
                </p>
            </div>

            <div class="d-flex align-center gap-3">
                <!-- View toggle -->
                <VBtnToggle v-model="viewMode" mandatory density="compact" rounded="lg" variant="outlined"
                    color="primary">
                    <VBtn value="tree" size="small">
                        <VIcon icon="tabler-sitemap" size="15" class="me-1" />
                        {{ t('organisation_structure_page.view_tree') }}
                    </VBtn>
                    <VBtn value="graph" size="small">
                        <VIcon icon="tabler-users" size="15" class="me-1" />
                        {{ t('organisation_structure_page.view_members') }}
                    </VBtn>

                    <VBtn value="organigram" size="small">
                        <VIcon icon="tabler-hierarchy" size="15" class="me-1" />
                        {{ t('organisation_structure_page.view_organigram') }}
                    </VBtn>
                </VBtnToggle>

                <VBtn v-if="viewMode === 'tree'" color="primary" rounded="lg" prepend-icon="tabler-plus"
                    @click="store.openAdd(null, '')">
                    {{ t('organisation_structure_page.add_root_unit') }}
                </VBtn>
            </div>
        </div>

        <!-- ── Main Panel ── -->
        <VCard elevation="0" border rounded="lg">

            <!-- Type legend (graph view only) -->
            <div v-if="viewMode === 'graph'" class="d-flex flex-wrap align-center gap-4 px-5 py-3"
                style="border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                <span class="text-caption font-weight-bold text-medium-emphasis text-uppercase"
                    style="letter-spacing: 1px;">{{ t('organisation_structure_page.legend') }}</span>
                <div v-for="(color, type) in typeColor" :key="type" class="d-flex align-center gap-1">
                    <div class="rounded-circle" :style="`width: 10px; height: 10px; background: ${color};`" />
                    <span class="text-caption text-capitalize text-medium-emphasis">{{ type }}</span>
                </div>
            </div>

            <VCardText class="pa-4 pa-sm-5">

      <!-- ── TREE VIEW ── -->
<div v-if="viewMode === 'tree'" @dragover="onDragOver" @drop="onDrop(null, $event)">
    <div v-if="!store.tree.length"
        class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
        <VIcon icon="tabler-sitemap" size="60" class="mb-4" style="opacity: 0.18;" />
        <p class="text-body-1 font-weight-semibold mb-1">{{ t('organisation_structure_page.no_units') }}</p>
        <p class="text-caption mb-5 text-center">
            {{ t('organisation_structure_page.no_units_sub') }}
        </p>
        <VBtn color="primary" rounded="lg" prepend-icon="tabler-plus" @click="store.openAdd(null, '')">
            {{ t('organisation_structure_page.add_first_unit') }}
        </VBtn>
    </div>
    <div v-else>
        <TreeNode v-for="node in store.tree" :key="node.id" :node="node" :depth="0"
            :get-color="getColor" @drag-start="onDragStart" @drop-on="onDrop" @drag-over="onDragOver"
            @add-child="(id, name) => store.openAdd(id, name)" @edit="store.openEdit"
            @delete="confirmDelete" />
    </div>
</div>

<!-- ── GRAPH / MEMBERS VIEW ── -->
<div v-else-if="viewMode === 'graph'">
    <div v-if="!store.tree.length"
        class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
        <VIcon icon="tabler-users" size="60" class="mb-4" style="opacity: 0.18;" />
        <p class="text-body-1 font-weight-semibold mb-1">{{ t('organisation_structure_page.no_structure') }}</p>
        <p class="text-caption mb-0">{{ t('organisation_structure_page.no_structure_sub') }}</p>
    </div>
    <TreeGraph v-else :nodes="store.tree as any" :get-color="getColor" />
</div>

<!-- ── ORGANIGRAM VIEW ── -->
<div v-else-if="viewMode === 'organigram'">
    <div v-if="!store.tree.length"
        class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
        <VIcon icon="tabler-hierarchy" size="60" class="mb-4" style="opacity: 0.18;" />
        <p class="text-body-1 font-weight-semibold mb-1">{{ t('organisation_structure_page.no_structure') }}</p>
        <p class="text-caption mb-0">{{ t('organisation_structure_page.no_structure_sub') }}</p>
    </div>
    <OrganigramTab v-else :nodes="store.tree as any" :get-color="getColor" />
</div>

            </VCardText>
        </VCard>

        <!-- ── Dialogs ── -->
        <AddUnit />
        <EditUnit />

        <!-- Delete confirmation -->
        <VDialog v-model="store.isDeleteOpen" max-width="420">
            <VCard rounded="lg" elevation="0">
                <div class="px-6 py-5">
                    <div class="d-flex align-center gap-3 mb-3">
                        <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                            style="width: 44px; height: 44px; background: #fef2f2;">
                            <VIcon icon="tabler-alert-triangle" color="error" size="22" />
                        </div>
                        <div>
                            <p class="text-body-1 font-weight-semibold mb-0">{{ t('organisation_structure_page.delete_unit_title') }}</p>
                            <p class="text-caption text-medium-emphasis mb-0">{{ t('organisation_structure_page.delete_unit_sub') }}</p>
                        </div>
                    </div>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                        {{ t('organisation_structure_page.delete_confirm', { name: store.deleteNode?.name }) }}
                    </p>
                </div>
                <div class="d-flex gap-3 px-6 pb-5">
                    <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="store.closeDelete">
                        {{ t('organisation_structure_page.cancel') }}
                    </VBtn>
                    <VBtn color="error" class="flex-grow-1" rounded="lg" @click="doDelete">
                        <VIcon icon="tabler-trash" size="16" class="me-2" />
                        {{ t('organisation_structure_page.delete') }}
                    </VBtn>
                </div>
            </VCard>
        </VDialog>

        <!-- Snackbar -->
        <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right"
            rounded="lg">
            <div class="d-flex align-center gap-2">
                <VIcon :icon="store.snackColor === 'success'
                    ? 'tabler-circle-check'
                    : store.snackColor === 'error'
                        ? 'tabler-trash'
                        : 'tabler-pencil'" size="16" />
                {{ store.snackMsg }}
            </div>
        </VSnackbar>

    </VContainer>
</template>