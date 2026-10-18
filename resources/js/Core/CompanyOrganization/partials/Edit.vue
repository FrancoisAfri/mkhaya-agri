<script setup lang="ts">
import type { VForm } from "vuetify/components/VForm"
import { ref, watch, nextTick, computed } from "vue"
import { useForm } from "@inertiajs/vue3"
import { useI18n } from "vue-i18n"
import { useOrganisationStore } from "../Store/store"
import UserAvatar from "./UserAvatar.vue"

const { t } = useI18n()
const store = useOrganisationStore()
const refForm = ref<VForm>()
const required = (v: unknown) =>
    (v !== null && v !== undefined && v !== "") || t('organisation_structure_page.name_required')
const form = useForm({ name: "", type: "", user_id: "" as string | number })

const formattedUsers = computed(() =>
    store.users.map((u) => ({
        ...u,
        display_name: `${u.first_name} ${u.surname}`,
    })),
)

const selectedUser = computed(() =>
    form.user_id
        ? (store.users.find((u) => u.id === Number(form.user_id)) ?? null)
        : null,
)

watch(
    () => store.editNode,
    (node) => {
        if (node) {
            form.name = node.name
            form.type = node.type ?? ""
            form.user_id = node.user_id ?? ""
        }
    },
    { immediate: true },
)

watch(
    () => store.isEditOpen,
    (val) => {
        if (!val)
            nextTick(() => {
                form.clearErrors()
                refForm.value?.resetValidation()
            })
    },
)

const onSubmit = async () => {
    const { valid } = await refForm.value!.validate()
    if (!valid) return

    form.put(`/settings/organization/${store.editNode?.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            const user = selectedUser.value ?? null
            store.updateNodeInTree(store.editNode!.id, {
                name: form.name,
                type: form.type || null,
                user_id: form.user_id ? Number(form.user_id) : null,
                user: user,
            })
            store.closeEdit()
            store.showSnack(t('organisation_structure_page.unit_updated'), "success")
        },
    })
}

const capitalizeWords = (str: string) => {
    return str
        .toLowerCase()
        .replace(/_/g, " ")
        .split(" ")
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(" ")
}

const formattedUnitTypes = computed(() => {
    return store.unitTypes.map((type) => ({
        title: capitalizeWords(type),
        value: type,
    }))
})
</script>

<template>
    <VDialog
        :model-value="store.isEditOpen"
        max-width="480"
        scrollable
        @update:model-value="store.closeEdit"
    >
        <VCard rounded="lg" elevation="0">
            <!-- Header -->
            <div
                class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
                style="
                    border-bottom: 1px solid
                        rgba(var(--v-border-color), var(--v-border-opacity));
                "
            >
                <div class="d-flex align-center gap-3">
                    <div
                        class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                        style="
                            width: 40px;
                            height: 40px;
                            background: rgba(var(--v-theme-warning), 0.12);
                        "
                    >
                        <VIcon
                            icon="tabler-pencil"
                            color="warning"
                            size="20"
                        />
                    </div>
                    <div>
                        <p class="text-body-1 font-weight-semibold mb-0">
                            {{ t('organisation_structure_page.edit_unit_title') }}
                        </p>
                        <p
                            class="text-caption text-medium-emphasis mb-0 text-truncate"
                            style="max-width: 200px"
                        >
                            {{ store.editNode?.name }}
                        </p>
                    </div>
                </div>
                <VBtn
                    icon="tabler-x"
                    variant="text"
                    size="small"
                    @click="store.closeEdit"
                />
            </div>

            <!-- Body -->
            <VCardText class="pa-6" style="overflow-y: auto; max-height: 55vh">
                <VForm ref="refForm" @submit.prevent="onSubmit">
                    <div class="form-block mb-4">
                        <p class="block-label">{{ t('organisation_structure_page.unit_name_label') }}</p>
                        <VTextField
                            v-model="form.name"
                            :rules="[required]"
                            :error-messages="form.errors.name"
                            :label="t('organisation_structure_page.unit_name_label') + ' *'"
                            prepend-inner-icon="tabler-building"
                            variant="outlined"
                            density="compact"
                            autofocus
                        />
                    </div>

                    <div class="form-block mb-4">
                        <p class="block-label">{{ t('organisation_structure_page.type_label') }}</p>
                        <VSelect
                            v-model="form.type"
                            :items="formattedUnitTypes"
                            item-title="title"
                            item-value="value"
                            :label="t('organisation_structure_page.type_label')"
                            prepend-inner-icon="tabler-tag"
                            variant="outlined"
                            density="compact"
                            clearable
                        />
                    </div>

                    <div class="form-block">
                        <p class="block-label">{{ t('organisation_structure_page.manager_label') }}</p>
                        <VAutocomplete
                            v-model="form.user_id"
                            :items="formattedUsers"
                            item-title="display_name"
                            item-value="id"
                            :label="t('organisation_structure_page.select_manager_optional')"
                            prepend-inner-icon="tabler-user-pentagon"
                            variant="outlined"
                            density="compact"
                            clearable
                            :no-data-text="t('organisation_structure_page.no_users_found')"
                        >
                            <template #item="{ item, props: ip }">
                                <VListItem v-bind="ip" :title="undefined">
                                    <template #prepend>
                                        <div class="me-3">
                                            <UserAvatar
                                                :src="item.raw.profile_pic"
                                                :initial="
                                                    item.raw.initial ||
                                                    `${item.raw.first_name?.[0] ?? ''}${item.raw.surname?.[0] ?? ''}`
                                                "
                                                :size="32"
                                                rounded="full"
                                            />
                                        </div>
                                    </template>
                                    <VListItemTitle
                                        class="text-body-2 font-weight-medium"
                                    >
                                        {{ item.raw.first_name }}
                                        {{ item.raw.surname }}
                                    </VListItemTitle>
                                    <VListItemSubtitle class="text-caption">
                                        {{
                                            item.raw.job_function ||
                                            item.raw.email ||
                                            ""
                                        }}
                                    </VListItemSubtitle>
                                </VListItem>
                            </template>
                        </VAutocomplete>
                    </div>
                </VForm>
            </VCardText>

            <!-- Footer -->
            <div
                class="d-flex gap-3 px-6 py-4 flex-shrink-0"
                style="
                    border-top: 1px solid
                        rgba(var(--v-border-color), var(--v-border-opacity));
                "
            >
                <VBtn
                    variant="tonal"
                    class="flex-grow-1"
                    rounded="lg"
                    :disabled="form.processing"
                    @click="store.closeEdit"
                >
                    {{ t('organisation_structure_page.cancel') }}
                </VBtn>
                <VBtn
                    color="primary"
                    class="flex-grow-1"
                    rounded="lg"
                    :loading="form.processing"
                    @click="onSubmit"
                >
                    <template #loader>
                        <VProgressCircular
                            indeterminate
                            size="18"
                            width="2"
                            color="white"
                            class="me-2"
                        />
                        <span>{{ t('organisation_structure_page.saving') }}</span>
                    </template>
                    <VIcon
                        icon="tabler-device-floppy"
                        size="16"
                        class="me-2"
                    />
                    {{ t('organisation_structure_page.save_changes') }}
                </VBtn>
            </div>
        </VCard>
    </VDialog>
</template>

<style scoped>
.form-block {
    background: rgba(var(--v-border-color), 0.04);
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 12px;
    padding: 16px;
}
.block-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.6px;
    color: rgba(var(--v-theme-on-surface), 0.5);
    text-transform: uppercase;
    margin: 0 0 12px;
}
</style>