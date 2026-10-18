<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from 'vue-i18n'
import type { EmployeeDocumentData } from "../Type/type";

const { t } = useI18n()

interface Props {
    personId: number;
    canEditDirectly: boolean;
    documents: EmployeeDocumentData[];
}

const props = defineProps<Props>();

const docs = ref<EmployeeDocumentData[]>([...props.documents]);
const modalOpen = ref(false);
const deleteDialog = ref(false);
const saving = ref(false);
const editingDoc = ref<EmployeeDocumentData | null>(null);
const deletingDoc = ref<EmployeeDocumentData | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);
const snack = ref({ show: false, msg: "", color: "success" as "success" | "error" });

const form = ref({ doc_description: "", date_from: "", expirydate: "", file: null as File | null, fileName: "" });

const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? "";
const showSnack = (msg: string, color: "success" | "error" = "success") => { snack.value = { show: true, msg, color }; };

const formatDate = (val: string | number | null) => {
    if (!val) return "—";
    const date = typeof val === "number" || /^\d+$/.test(String(val)) ? new Date(Number(val) * 1000) : new Date(val);
    return date.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
};

const isExpired = (date: string | number | null) => { if (!date) return false; return new Date(date) < new Date(); };
const expiringSoon = (date: string | null) => { if (!date) return false; const diff = new Date(date).getTime() - Date.now(); return diff > 0 && diff < 30 * 24 * 60 * 60 * 1000; };

const fileIcon = (doc: EmployeeDocumentData) => {
    try { const parsed = typeof doc.supporting_docs === "string" ? JSON.parse(doc.supporting_docs as string) : doc.supporting_docs; const mime = parsed?.mime ?? ""; if (mime.startsWith("image/")) return "fa-file-image"; if (mime === "application/pdf") return "fa-file-pdf"; if (mime.includes("word")) return "fa-file-word"; } catch {}
    return "fa-file";
};

const fileIconColor = (doc: EmployeeDocumentData) => { const icon = fileIcon(doc); if (icon === "fa-file-pdf") return "#ef4444"; if (icon === "fa-file-image") return "#6366f1"; if (icon === "fa-file-word") return "#3b82f6"; return "#94a3b8"; };

const openAdd = () => { editingDoc.value = null; form.value = { doc_description: "", date_from: "", expirydate: "", file: null, fileName: "" }; modalOpen.value = true; };
const openEdit = (doc: EmployeeDocumentData) => { editingDoc.value = doc; form.value = { doc_description: doc.doc_description ?? "", date_from: toDateInput(doc.date_from), expirydate: toDateInput(doc.expirydate), file: null, fileName: "" }; modalOpen.value = true; };
const closeModal = () => { modalOpen.value = false; editingDoc.value = null; };

const onFilePick = (e: Event) => { const file = (e.target as HTMLInputElement).files?.[0]; if (!file) return; form.value.file = file; form.value.fileName = file.name; };
const onDropFile = (e: DragEvent) => { dragOver.value = false; const file = e.dataTransfer?.files?.[0]; if (!file) return; form.value.file = file; form.value.fileName = file.name; };

const onSubmit = async () => {
    if (!editingDoc.value && !form.value.file) { showSnack(t('profile_tabs.snack_select_file'), "error"); return; }
    if (!form.value.doc_description.trim()) { showSnack(t('profile_tabs.snack_description_required'), "error"); return; }
    saving.value = true;
    try {
        const fd = new FormData();
        fd.append("person_id", String(props.personId)); fd.append("doc_description", form.value.doc_description);
        fd.append("date_from", form.value.date_from || ""); fd.append("expirydate", form.value.expirydate || "");
        if (form.value.file) fd.append("file", form.value.file);
        if (editingDoc.value) fd.append("_method", "PUT");
        const url = editingDoc.value ? `/settings/documents/${editingDoc.value.id}` : `/settings/documents`;
        const res = await fetch(url, { method: "POST", body: fd, headers: { "X-CSRF-TOKEN": csrf() } });
        if (!res.ok) { const err = await res.json().catch(() => ({})); throw new Error(err?.message ?? t('profile_tabs.snack_failed')); }
        const saved: EmployeeDocumentData = await res.json();
        if (editingDoc.value) { const idx = docs.value.findIndex((d) => d.id === saved.id); if (idx !== -1) docs.value[idx] = saved; }
        else { docs.value.unshift(saved); }
        showSnack(editingDoc.value ? t('profile_tabs.snack_updated') : t('profile_tabs.snack_uploaded'));
        closeModal();
    } catch (e: any) { showSnack(e?.message ?? t('profile_tabs.snack_failed'), "error"); }
    finally { saving.value = false; }
};

const confirmDelete = (doc: EmployeeDocumentData) => { deletingDoc.value = doc; deleteDialog.value = true; };
const doDelete = async () => {
    if (!deletingDoc.value) return;
    try { const res = await fetch(`/settings/documents/${deletingDoc.value.id}`, { method: "DELETE", headers: { "X-CSRF-TOKEN": csrf(), "Content-Type": "application/json" } }); if (!res.ok) throw new Error("Delete failed"); docs.value = docs.value.filter((d) => d.id !== deletingDoc.value!.id); showSnack(t('profile_tabs.snack_deleted'), "error"); }
    catch { showSnack(t('profile_tabs.snack_failed'), "error"); }
    finally { deleteDialog.value = false; deletingDoc.value = null; }
};

const downloadDoc = (doc: EmployeeDocumentData) => { window.open(`/settings/documents/${doc.id}/download`, "_blank"); };
const toDateInput = (val: string | number | null): string => { if (!val) return ""; const date = typeof val === "number" || /^\d+$/.test(String(val)) ? new Date(Number(val) * 1000) : new Date(val); if (isNaN(date.getTime())) return ""; const year = date.getFullYear(); const month = String(date.getMonth() + 1).padStart(2, "0"); const day = String(date.getDate()).padStart(2, "0"); return `${year}-${month}-${day}`; };
</script>

<template>
    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm px-6 py-4 flex items-center justify-between">
            <div><h2 class="text-base font-bold text-slate-800">{{ t('profile_tabs.documents') }}</h2><p class="text-xs text-slate-400 mt-0.5">{{ t('profile_tabs.documents_on_file', { count: docs.length }) }}</p></div>
            <VBtn color="primary" rounded="lg" prepend-icon="tabler-plus" @click="openAdd">{{ t('profile_tabs.upload_document') }}</VBtn>
        </div>

        <div v-if="!canEditDirectly" class="flex items-start gap-3 px-5 py-4 bg-amber-50 border border-amber-200 rounded-2xl">
            <i class="fa-solid fa-circle-info text-amber-500 mt-0.5 flex-shrink-0" />
            <p class="text-sm text-amber-700">{{ t('profile_tabs.pending_approval_notice') }}</p>
        </div>

        <div v-if="!docs.length" class="bg-white rounded-2xl border border-slate-100 shadow-sm flex flex-col items-center justify-center py-20 text-slate-400">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mb-4"><i class="fa-solid fa-folder-open text-2xl opacity-40" /></div>
            <p class="font-semibold text-sm">{{ t('profile_tabs.no_documents') }}</p>
            <p class="text-xs mt-1">{{ t('profile_tabs.upload_first') }}</p>
        </div>

        <div v-else class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
            <div v-for="(doc, idx) in docs" :key="doc.id" :class="['flex items-center gap-4 px-6 py-4 transition-colors hover:bg-slate-50', idx !== 0 && 'border-t border-slate-100']">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :style="{ background: fileIconColor(doc) + '15' }">
                    <i :class="`fa-solid ${fileIcon(doc)}`" :style="{ color: fileIconColor(doc) }" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800 truncate">{{ doc.doc_description || "Untitled" }}</p>
                    <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                        <span v-if="doc.date_from" class="text-xs text-slate-400"><i class="fa-regular fa-calendar me-1" />{{ t('profile_tabs.from') }}: {{ formatDate(doc.date_from) }}</span>
                        <span v-if="doc.expirydate" :class="['text-xs font-medium flex items-center gap-1', isExpired(doc.expirydate) ? 'text-red-500' : expiringSoon(doc.expirydate) ? 'text-amber-500' : 'text-slate-400']">
                            <i class="fa-regular fa-clock" />{{ t('profile_tabs.exp') }}: {{ formatDate(doc.expirydate) }}
                            <span v-if="isExpired(doc.expirydate)" class="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">{{ t('profile_tabs.expired') }}</span>
                            <span v-else-if="expiringSoon(doc.expirydate)" class="text-[10px] bg-amber-100 text-amber-600 px-1.5 py-0.5 rounded-full font-bold">{{ t('profile_tabs.soon') }}</span>
                        </span>
                    </div>
                </div>
                <span v-if="doc.hr_change_id && !doc.hr_person_id" class="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-2 py-1 rounded-full flex-shrink-0">{{ t('profile_tabs.pending') }}</span>
                <div class="flex items-center gap-1 flex-shrink-0">
                    <button v-if="doc.supporting_docs" class="w-8 h-8 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-500 flex items-center justify-center transition-colors" title="Download" @click="downloadDoc(doc)"><i class="fa-solid fa-download text-xs" /></button>
                    <button class="w-8 h-8 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-500 flex items-center justify-center transition-colors" title="Edit" @click="openEdit(doc)"><i class="fa-solid fa-pen text-xs" /></button>
                    <button class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 text-red-500 flex items-center justify-center transition-colors" title="Delete" @click="confirmDelete(doc)"><i class="fa-solid fa-trash text-xs" /></button>
                </div>
            </div>
        </div>
    </div>

    <VDialog v-model="modalOpen" max-width="500" persistent scrollable>
        <VCard rounded="lg" elevation="0">
            <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                <div class="d-flex align-center gap-3">
                    <div style="width:40px;height:40px;background:#eef2ff;border-radius:10px" class="d-flex align-center justify-center flex-shrink-0"><i class="fa-solid fa-file-arrow-up" style="color:#6366f1;font-size:16px" /></div>
                    <div><p class="text-body-1 font-weight-semibold mb-0">{{ editingDoc ? t('profile_tabs.edit_document') : t('profile_tabs.upload_document') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ canEditDirectly ? t('profile_tabs.saved_directly') : t('profile_tabs.submitted_for_approval') }}</p></div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="closeModal" />
            </div>
            <VCardText class="pa-6" style="overflow-y:auto;max-height:60vh">
                <div class="space-y-4">
                    <div class="form-block"><p class="block-label">{{ t('profile_tabs.description_label') }}</p><VTextField v-model="form.doc_description" :label="t('profile_tabs.description_label')" :placeholder="t('profile_tabs.description_placeholder')" prepend-inner-icon="tabler-text-size" variant="outlined" density="compact" autofocus /></div>
                    <div class="form-block"><p class="block-label">{{ t('profile_tabs.dates') }}</p><div class="two-col"><VTextField v-model="form.date_from" :label="t('profile_tabs.date_from')" type="date" prepend-inner-icon="tabler-calendar" variant="outlined" density="compact" /><VTextField v-model="form.expirydate" :label="t('profile_tabs.expiry_date')" type="date" prepend-inner-icon="tabler-calendar-clock" variant="outlined" density="compact" /></div></div>
                    <div class="form-block">
                        <p class="block-label">{{ t('profile_tabs.file_label') }} {{ editingDoc ? t('profile_tabs.file_keep_current') : t('profile_tabs.file_required') }}</p>
                        <div class="drop-zone" :class="{ 'drag-active': dragOver, 'has-file': !!form.fileName }" @click="fileInput?.click()" @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDropFile">
                            <input ref="fileInput" type="file" class="hidden" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" @change="onFilePick" />
                            <div v-if="form.fileName" class="file-selected"><i class="fa-solid fa-file-check text-indigo-500 text-xl" /><div><p class="text-sm font-semibold text-slate-700">{{ form.fileName }}</p><p class="text-xs text-slate-400">{{ t('profile_tabs.click_to_change') }}</p></div></div>
                            <div v-else class="drop-empty"><i class="fa-solid fa-cloud-arrow-up text-3xl opacity-40" /><p class="text-sm font-medium">{{ t('profile_tabs.drop_file_here') }} <span class="text-indigo-500">{{ t('profile_tabs.browse') }}</span></p><p class="text-xs text-slate-400">{{ t('profile_tabs.file_formats') }}</p></div>
                        </div>
                    </div>
                </div>
            </VCardText>
            <div class="d-flex gap-3 px-6 py-4 flex-shrink-0" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
                <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="lg" :disabled="saving" @click="closeModal"><i class="fa-regular fa-x me-2" />{{ t('profile_tabs.cancel') }}</VBtn>
                <VBtn :color="canEditDirectly ? 'success' : 'warning'" class="flex-grow-1" rounded="lg" :loading="saving" @click="onSubmit">
                    <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('profile_tabs.saving') }}</span></template>
                    <i :class="`fa-solid ${canEditDirectly ? 'fa-floppy-disk' : 'fa-paper-plane'} me-2`" />{{ canEditDirectly ? t('profile_tabs.save') : t('profile_tabs.submit_for_approval') }}
                </VBtn>
            </div>
        </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400" persistent>
        <VCard rounded="lg" elevation="0">
            <div class="px-6 py-5">
                <div class="d-flex align-center gap-3 mb-3"><div style="width:44px;height:44px;background:#fef2f2;border-radius:10px" class="d-flex align-center justify-center flex-shrink-0"><i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:18px" /></div><div><p class="text-body-1 font-weight-semibold mb-0">{{ t('profile_tabs.delete_document') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('profile_tabs.delete_warning') }}</p></div></div>
                <p class="text-body-2 text-medium-emphasis">{{ t('profile_tabs.delete_confirm', { name: deletingDoc?.doc_description || 'this document' }) }}</p>
            </div>
            <div class="d-flex gap-3 px-6 pb-5"><VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="deleteDialog = false">{{ t('profile_tabs.cancel') }}</VBtn><VBtn color="error" class="flex-grow-1" rounded="lg" @click="doDelete"><i class="fa-solid fa-trash me-2" />{{ t('profile_tabs.delete') }}</VBtn></div>
        </VCard>
    </VDialog>

    <VSnackbar v-model="snack.show" :timeout="3000" :color="snack.color" location="bottom right" rounded="lg">
        <div class="d-flex align-center gap-2"><i :class="snack.color === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" style="font-size:16px" />{{ snack.msg }}</div>
    </VSnackbar>
</template>

<style scoped>
.space-y-4 > * + * { margin-top: 16px; }
.form-block { background: rgba(var(--v-border-color), 0.04); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 16px; }
.block-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: uppercase; margin: 0 0 12px; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.drop-zone { border: 2px dashed #e2e8f0; border-radius: 12px; padding: 20px; cursor: pointer; transition: all 0.15s; text-align: center; }
.drop-zone:hover, .drop-zone.drag-active { border-color: #6366f1; background: #eef2ff; }
.drop-zone.has-file { border-color: #6366f1; background: #f5f3ff; text-align: left; }
.file-selected { display: flex; align-items: center; gap: 12px; }
.drop-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; color: #94a3b8; padding: 8px 0; }
</style>
