<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Template { id: number; uuid: string; name: string; key: string | null; category: string; subject: string; body: string; active: boolean; system: boolean }

const props = defineProps<{ templates: Template[]; categories: string[]; mergeTags: string[] }>()

const drawer = ref(false); const editing = ref<Template | null>(null); const confirm = ref()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const form = useForm({ name: '', category: 'general', subject: '', body: '', active: true })

function openNew() { editing.value = null; form.reset(); form.clearErrors(); drawer.value = true }
function openEdit(tp: Template) { editing.value = tp; form.clearErrors(); form.name = tp.name; form.category = tp.category; form.subject = tp.subject; form.body = tp.body; form.active = tp.active; drawer.value = true }
function save() { const opts = { preserveScroll: true, onSuccess: () => { drawer.value = false; snack.value = { show: true, text: t('crm_email_templates_index.toast_saved'), color: 'success' } } }; if (editing.value) form.patch(route('crm.email-templates.update', editing.value.uuid), opts); else form.post(route('crm.email-templates.store'), opts) }
function remove(tp: Template) { confirm.value.open({ title: t('crm_email_templates_index.delete.title'), message: t('crm_email_templates_index.delete.message', { name: tp.name }), confirmText: t('crm_email_templates_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.email-templates.destroy', tp.uuid), { preserveScroll: true })) }
function tagToken(tag: string) { return '{{' + tag + '}}' }
function insertTag(tag: string) { form.body += ' ' + tagToken(tag) }

const catColor: Record<string, string> = { lead: 'primary', deal: 'success', complaint: 'error', campaign: 'info', general: 'blue-grey' }
const grouped = computed(() => props.categories.map(c => ({ category: c, items: props.templates.filter(tp => tp.category === c) })))
</script>

<template>
    <div class="pa-4">
        <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
            <div>
                <h5 class="text-h5 font-weight-semibold">{{ t('crm_email_templates_index.page_title') }}</h5>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_email_templates_index.description') }}</p>
            </div>
            <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{
                t('crm_email_templates_index.new_template') }}</VBtn>
        </div>

        <div v-for="g in grouped" :key="g.category" class="mb-6">
            <div v-if="g.items.length" class="text-overline text-medium-emphasis mb-2 text-capitalize">{{ g.category }}
            </div>
            <VRow>
                <VCol v-for="tp in g.items" :key="tp.id" cols="12" md="6" lg="4">
                    <VCard class="h-100">
                        <VCardTitle class="d-flex align-center ga-2 pb-1">
                            <VChip size="x-small" :color="catColor[tp.category]" label class="text-capitalize">{{
                                tp.category }}</VChip><span class="text-body-1 font-weight-bold text-truncate">{{
                                tp.name }}</span>
                            <VSpacer />
                            <VChip v-if="tp.system" size="x-small" variant="tonal" prepend-icon="tabler-bolt">{{
                                t('crm_email_templates_index.auto') }}</VChip>
                          <VChip v-if="!tp.active" size="x-small" color="warning" label>
  {{ t('crm_email_templates_index.off') }}
</VChip>
                        </VCardTitle>
                        <VCardText class="pt-1">
                            <div class="text-body-2 font-weight-medium mb-1 text-truncate">{{ tp.subject }}</div>
                            <div class="text-caption text-medium-emphasis" style="max-height: 60px; overflow: hidden"
                                v-html="tp.body" />
                        </VCardText>
                        <VDivider />
                        <VCardActions class="pa-2">
                            <VBtn size="small" variant="text" icon="tabler-edit"
                                :title="t('crm_email_templates_index.tips.edit_template')" @click="openEdit(tp)" />
                            <VBtn v-if="!tp.system" size="small" variant="text" icon="tabler-trash" color="error"
                                :title="t('crm_email_templates_index.tips.delete_template')" @click="remove(tp)" />
                            <VSpacer /><span v-if="tp.key" class="text-caption text-disabled me-2">{{
                                t('crm_email_templates_index.key') }}: {{ tp.key }}</span>
                        </VCardActions>
                    </VCard>
                </VCol>
            </VRow>
        </div>

        <VNavigationDrawer v-model="drawer" temporary location="right" width="640">
            <VForm @submit.prevent="save" class="h-100 d-flex flex-column">
                <div class="pa-5 border-b d-flex align-center ga-2">
                    <VIcon icon="tabler-mail-cog" color="primary" />
                    <h5 class="text-h5">{{ editing ? t('crm_email_templates_index.drawer.title_edit') :
                        t('crm_email_templates_index.drawer.title_create') }}</h5>
                    <VChip v-if="editing?.system" size="x-small" variant="tonal" class="ms-1">{{
                        t('crm_email_templates_index.drawer.system') }}</VChip>
                </div>
                <div class="flex-grow-1 overflow-y-auto pa-5">
                    <VTextField v-model="form.name" :label="t('crm_email_templates_index.drawer.name')" class="mb-3"
                        :error-messages="form.errors.name" />
                    <VSelect v-model="form.category" :items="categories"
                        :label="t('crm_email_templates_index.drawer.category')" class="mb-3 text-capitalize"
                        :disabled="!!editing?.system" />
                    <VTextField v-model="form.subject" :label="t('crm_email_templates_index.drawer.subject')"
                        class="mb-3" :error-messages="form.errors.subject" />
                    <div class="text-caption text-medium-emphasis mb-1">{{
                        t('crm_email_templates_index.drawer.merge_tags_hint') }}</div>
                    <div class="d-flex flex-wrap ga-1 mb-3">
                        <VChip v-for="tag in mergeTags" :key="tag" size="x-small" variant="tonal"
                            @click="insertTag(tag)">{{ tagToken(tag) }}</VChip>
                    </div>
                    <VTextarea v-model="form.body" :label="t('crm_email_templates_index.drawer.body_label')" rows="12"
                        :error-messages="form.errors.body" />
                    <VSwitch v-model="form.active" :label="t('crm_email_templates_index.drawer.active')" color="primary"
                        hide-details class="mt-2" />
                    <VDivider class="my-4" />
                    <div class="text-overline text-medium-emphasis mb-1">{{
                        t('crm_email_templates_index.drawer.preview') }}</div>
                    <VCard variant="tonal">
                        <VCardText>
                            <div class="font-weight-medium mb-2">{{ form.subject }}</div>
                            <div v-html="form.body" />
                        </VCardText>
                    </VCard>
                </div>
                <div class="pa-5 border-t d-flex ga-3 justify-end">
                    <VBtn variant="tonal" @click="drawer = false">{{ t('crm_email_templates_index.drawer.cancel') }}
                    </VBtn>
                    <VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy">
                        {{ t('crm_email_templates_index.drawer.save') }}</VBtn>
                </div>
            </VForm>
        </VNavigationDrawer>

        <ConfirmDialog ref="confirm" />
        <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}
        </VSnackbar>
    </div>
</template>
