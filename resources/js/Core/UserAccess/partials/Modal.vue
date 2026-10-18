<script setup lang="ts">
import { ref, watch, computed } from "vue";
import type { UserProperties, UserAccessData } from "../types/type";
import { useI18n } from "vue-i18n";

interface Props {
    isOpen: boolean;
    user: UserProperties | null;
}
interface Emit {
    (e: "update:isOpen", value: boolean): void;
}

const props = defineProps<Props>();
const emit = defineEmits<Emit>();

const loading = ref(false);
const accessData = ref<UserAccessData | null>(null);
const expandedCat = ref<number | null>(null);
const expandedMod = ref<number | null>(null);
const saving = ref(false);
const saved = ref(false);

const pendingChanges = ref<Record<string, number>>({});
const { t } = useI18n();

const accessLevels = [
    {
        value: 0,
        label: t("access_levels.none"),
        icon: "fa-ban",
        color: "#94a3b8",
    },
    {
        value: 1,
        label: t("access_levels.read"),
        icon: "fa-eye",
        color: "#3b82f6",
    },
    {
        value: 2,
        label: t("access_levels.write"),
        icon: "fa-pen",
        color: "#f59e0b",
    },
    {
        value: 3,
        label: t("access_levels.modify"),
        icon: "fa-pen-to-square",
        color: "#f97316",
    },
    {
        value: 4,
        label: t("access_levels.admin"),
        icon: "fa-shield",
        color: "#ef4444",
    },
    {
        value: 5,
        label: t("access_levels.superuser"),
        icon: "fa-crown",
        color: "#8b5cf6",
    },
];

const getLevel = (
    type: string,
    entityId: number,
    dbLevel: number | string | null,
): number => {
    const key = `${type}-${entityId}`;
    if (key in pendingChanges.value) return pendingChanges.value[key];
    return parseInt(String(dbLevel ?? 0));
};

const catLevel = (catId: number): number => {
    const cat = accessData.value?.categories.find(
        (c) => c.category_id === catId,
    );
    if (!cat) return 0;
    return getLevel("category", cat.category_id, cat.access_level);
};

const modLevel = (modId: number): number => {
    const mod = accessData.value?.modules.find((m) => m.module_id === modId);
    if (!mod) return 0;
    return getLevel("module", mod.module_id, mod.access_level);
};

const setLevel = (
    type: "category" | "module" | "ribbon",
    entityId: number,
    level: number,
    parentId?: number,
) => {
    const maxLevel =
        type === "module"
            ? catLevel(parentId ?? 0)
            : type === "ribbon"
                ? modLevel(parentId ?? 0)
                : 5;

    if (level > maxLevel) return;

    pendingChanges.value[`${type}-${entityId}`] = level;

    // Héritage vers le bas
    if (type === "category" && accessData.value) {
        accessData.value.modules
            .filter((m) => m.category_id === entityId)
            .forEach((m) => {
                const currentMod = getLevel(
                    "module",
                    m.module_id,
                    m.access_level,
                );
                // if (currentMod > level) {
                //     pendingChanges.value[`module-${m.module_id}`] = level;
                //     accessData
                //         .value!.ribbons.filter(
                //             (r) => r.module_id === m.module_id,
                //         )
                //         .forEach((r) => {
                //             const currentRib = getLevel(
                //                 "ribbon",
                //                 r.ribbon_id,
                //                 r.access_level,
                //             );
                //             if (currentRib > level) {
                //                 pendingChanges.value[`ribbon-${r.ribbon_id}`] =
                //                     level;
                //             }
                //         });
                // }
            });
    }

    // if (type === "module" && accessData.value) {
    //     accessData.value.ribbons
    //         .filter((r) => r.module_id === entityId)
    //         .forEach((r) => {
    //             const currentRib = getLevel(
    //                 "ribbon",
    //                 r.ribbon_id,
    //                 r.access_level,
    //             );
    //             if (currentRib > level) {
    //                 pendingChanges.value[`ribbon-${r.ribbon_id}`] = level;
    //             }
    //         });
    // }
};

const hasChanges = computed(() => Object.keys(pendingChanges.value).length > 0);

const isBlocked = (
    type: "module" | "ribbon",
    level: number,
    parentId: number,
): boolean => {
    const max = type === "module" ? catLevel(parentId) : modLevel(parentId);
    return level > max;
};

const getCsrf = () =>
    (document.querySelector("meta[name=csrf-token]") as HTMLMetaElement)
        ?.content ?? "";

// ── Load ──
const loadAccess = async (userId: number) => {
    loading.value = true;
    pendingChanges.value = {};
    try {
        const res = await fetch(`/settings/user-access/${userId}`, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });
        accessData.value = await res.json();
    } catch {
        accessData.value = null;
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.isOpen,
    (val) => {
        if (val && props.user) {
            loadAccess(props.user.id);
            expandedCat.value = null;
            expandedMod.value = null;
        } else {
            accessData.value = null;
            pendingChanges.value = {};
        }
    },
);

// ── Save all pending ──
const saveAll = async () => {
    if (!hasChanges.value || !props.user) return;
    saving.value = true;

    const requests = Object.entries(pendingChanges.value).map(
        ([key, level]) => {
            const dashIdx = key.indexOf("-");
            const type = key.substring(0, dashIdx);
            const entityId = key.substring(dashIdx + 1);

            return fetch(`/settings/user-access/${props.user!.id}/${type}/${entityId}`, {
                method: "POST",
                credentials: "include",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": getCsrf(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ access_level: level }),
            });
        },
    );

    try {
        await Promise.all(requests);

        // Applique localement
        if (accessData.value) {
            Object.entries(pendingChanges.value).forEach(([key, level]) => {
                const dashIdx = key.indexOf("-");
                const type = key.substring(0, dashIdx);
                const entityId = parseInt(key.substring(dashIdx + 1));

                if (type === "category") {
                    const cat = accessData.value!.categories.find(
                        (c) => c.category_id === entityId,
                    );
                    if (cat) cat.access_level = String(level);
                }
                if (type === "module") {
                    const mod = accessData.value!.modules.find(
                        (m) => m.module_id === entityId,
                    );
                    if (mod) mod.access_level = level;
                }
                // if (type === "ribbon") {
                //     const rib = accessData.value!.ribbons.find(
                //         (r) => r.ribbon_id === entityId,
                //     );
                //     if (rib) rib.access_level = level;
                // }
            });
        }

        pendingChanges.value = {};
        saved.value = true;
        setTimeout(() => {
            saved.value = false;
        }, 2500);
    } finally {
        saving.value = false;
    }
};

const discardChanges = () => {
    pendingChanges.value = {};
};

const modulesForCat = (catId: number) =>
    accessData.value?.modules.filter((m) => m.category_id === catId) ?? [];

// const ribbonsForMod = (modId: number) =>
//     accessData.value?.ribbons.filter((r) => r.module_id === modId) ?? [];

const getUserName = (u: UserProperties | null) =>
    u?.person ? `${u.person.first_name} ${u.person.surname}` : (u?.email ?? "");

const getInitials = (u: UserProperties | null) =>
    u?.person
        ? `${u.person.first_name[0] ?? ""}${u.person.surname[0] ?? ""}`.toUpperCase()
        : (u?.email?.[0]?.toUpperCase() ?? "?");

const close = () => emit("update:isOpen", false);
</script>

<template>
    <VDialog :model-value="props.isOpen" max-width="740" scrollable @update:model-value="emit('update:isOpen', $event)">
        <VCard rounded="lg" elevation="0">
            <!-- Header -->
            <div class="modal-header">
                <div class="d-flex align-center gap-3">
                    <div class="modal-avatar">{{ getInitials(user) }}</div>
                    <div>
                        <p class="modal-username">{{ getUserName(user) }}</p>
                        <p class="modal-email">{{ user?.email }}</p>
                    </div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="close" />
            </div>

            <!-- Pending Banner -->
            <Transition name="slide-down">
                <div v-if="hasChanges" class="pending-banner">
                    <div class="d-flex align-center gap-2">
                        <i class="fa-solid fa-circle-dot pending-dot" />
                        <span>{{ Object.keys(pendingChanges).length }}
                            {{ t("unsaved_change") }}
                            {{
                                Object.keys(pendingChanges).length > 1
                                    ? "s"
                                    : ""
                            }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn-discard" @click="discardChanges">
                            <i class="fa-solid fa-xmark" /> {{ t("discard") }}
                        </button>
                        <button class="btn-save-changes" :disabled="saving" @click="saveAll">
                            <VProgressCircular v-if="saving" indeterminate size="12" width="2" color="white"
                                class="me-1" />
                            <i v-else class="fa-solid fa-floppy-disk" />
                            {{ saving ? "Saving..." : "Save Changes" }}
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Saved Banner -->
            <Transition name="slide-down">
                <div v-if="saved" class="saved-banner">
                    <i class="fa-solid fa-circle-check me-2" />
                    All changes saved successfully
                </div>
            </Transition>

            <!-- Body -->
            <VCardText class="pa-0" style="max-height: 65vh; overflow-y: auto">
                <div v-if="loading" class="d-flex align-center justify-center py-12">
                    <VProgressCircular indeterminate color="primary" />
                </div>

                <div v-else-if="accessData" class="pa-5">
                    <!-- Legend -->
                    <div class="legend mb-4">
                        <div v-for="lv in accessLevels" :key="lv.value" class="legend-item"
                            :style="{ color: lv.color }">
                            <i :class="`fa-solid ${lv.icon}`" />
                            <span>{{ lv.label }}</span>
                        </div>
                    </div>

                    <!-- Categories -->
                    <div v-for="cat in accessData.categories" :key="cat.category_id" class="access-block mb-3">
                        <!-- Category Row -->
                        <div class="access-row access-row--cat" @click="
                            expandedCat =
                            expandedCat === cat.category_id
                                ? null
                                : cat.category_id
                            ">
                            <div class="d-flex align-center gap-3 flex-grow-1 min-w-0">
                                <i :class="`fa-solid fa-${cat.category_icon}`" class="access-icon"
                                    style="color: #6366f1" />
                                <div class="min-w-0">
                                    <p class="access-name">
                                        {{ cat.category_name }}
                                    </p>
                                    <p class="access-sub">
                                        {{ t("category") }}
                                    </p>
                                </div>
                            </div>

                            <div class="level-selector" @click.stop>
                                <button v-for="lv in accessLevels" :key="lv.value" :class="[
                                    'level-btn',
                                    getLevel(
                                        'category',
                                        cat.category_id,
                                        cat.access_level,
                                    ) === lv.value && 'level-btn--active',
                                ]" :title="lv.label" :style="getLevel(
                                        'category',
                                        cat.category_id,
                                        cat.access_level,
                                    ) === lv.value
                                            ? {
                                                background: lv.color,
                                                borderColor: lv.color,
                                                color: 'white',
                                            }
                                            : {}
                                        " @click="
                                        setLevel(
                                            'category',
                                            cat.category_id,
                                            lv.value,
                                            cat.category_id,
                                        )
                                        ">
                                    <i :class="`fa-solid ${lv.icon}`" />
                                </button>
                            </div>

                            <span v-if="
                                `category-${cat.category_id}` in
                                pendingChanges
                            " class="pending-dot-small" />

                            <i class="fa-solid fa-chevron-right expand-arrow" :style="{
                                transform:
                                    expandedCat === cat.category_id
                                        ? 'rotate(90deg)'
                                        : 'none',
                            }" />
                        </div>

                        <!-- Modules -->
                        <div v-if="expandedCat === cat.category_id" class="sub-list">
                            <div v-if="!modulesForCat(cat.category_id).length" class="empty-sub">
                                {{ t("access_levels.no_module") }}
                            </div>

                            <div v-for="mod in modulesForCat(cat.category_id)" :key="mod.module_id">
                                <div class="access-row access-row--mod" @click="
                                    expandedMod =
                                    expandedMod === mod.module_id
                                        ? null
                                        : mod.module_id
                                    ">
                                    <div class="d-flex align-center gap-3 flex-grow-1 min-w-0">
                                        <i :class="`fa-solid fa-${mod.module_icon}`" class="access-icon"
                                            style="color: #0d9488" />
                                        <div class="min-w-0">
                                            <p class="access-name">
                                                {{ mod.module_name }}
                                            </p>
                                            <p class="access-sub">
                                                {{ t("module") }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="level-selector" @click.stop>
                                        <button v-for="lv in accessLevels" :key="lv.value" :class="[
                                            'level-btn',
                                            getLevel(
                                                'module',
                                                mod.module_id,
                                                mod.access_level,
                                            ) === lv.value &&
                                            'level-btn--active',
                                            isBlocked(
                                                'module',
                                                lv.value,
                                                cat.category_id,
                                            ) && 'level-btn--blocked',
                                        ]" :title="isBlocked(
                                                'module',
                                                lv.value,
                                                cat.category_id,
                                            )
                                                    ? `Blocked — max is ${accessLevels[catLevel(cat.category_id)]?.label}`
                                                    : lv.label
                                                " :disabled="isBlocked(
                                                'module',
                                                lv.value,
                                                cat.category_id,
                                            )
                                                " :style="getLevel(
                                                'module',
                                                mod.module_id,
                                                mod.access_level,
                                            ) === lv.value &&
                                                    !isBlocked(
                                                        'module',
                                                        lv.value,
                                                        cat.category_id,
                                                    )
                                                    ? {
                                                        background: lv.color,
                                                        borderColor: lv.color,
                                                        color: 'white',
                                                    }
                                                    : {}
                                                " @click="
                                                setLevel(
                                                    'module',
                                                    mod.module_id,
                                                    lv.value,
                                                    mod.category_id,
                                                )
                                                ">
                                            <i :class="`fa-solid ${lv.icon}`" />
                                        </button>
                                    </div>

                                    <span v-if="
                                        `module-${mod.module_id}` in
                                        pendingChanges
                                    " class="pending-dot-small" />

                                    <i class="fa-solid fa-chevron-right expand-arrow" :style="{
                                        transform:
                                            expandedMod === mod.module_id
                                                ? 'rotate(90deg)'
                                                : 'none',
                                    }" />
                                </div>

                                <!-- Locked hint -->
                                <div v-if="
                                    expandedMod === mod.module_id &&
                                    catLevel(cat.category_id) === 0
                                " class="blocked-hint">
                                    <i class="fa-solid fa-lock me-2" />
                                    {{ t("access_levels.module_locked") }}
                                </div>

                                <!-- Ribbons -->
                                <div v-if="
                                    expandedMod === mod.module_id &&
                                    catLevel(cat.category_id) > 0
                                " class="sub-list">
                                    <!-- <div
                                        v-if="
                                            !ribbonsForMod(mod.module_id).length
                                        "
                                        class="empty-sub"
                                    >
                                        t{{ t("access_levels.no_ribbons") }}
                                    </div> -->

                                    <!-- <div
                                        v-for="rib in ribbonsForMod(
                                            mod.module_id,
                                        )"
                                        :key="rib.ribbon_id"
                                        class="access-row access-row--rib"
                                    >
                                        <div
                                            class="d-flex align-center gap-3 flex-grow-1 min-w-0"
                                        >
                                            <i
                                                :class="`fa-solid fa-${rib.font_awesome}`"
                                                class="access-icon"
                                                style="color: #f97316"
                                            />
                                            <div class="min-w-0">
                                                <p class="access-name">
                                                    {{ rib.ribbon_name }}
                                                </p>
                                                <p class="access-sub">
                                                    {{ t("ribbon") }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="level-selector">
                                            <button
                                                v-for="lv in accessLevels"
                                                :key="lv.value"
                                                :class="[
                                                    'level-btn',
                                                    getLevel(
                                                        'ribbon',
                                                        rib.ribbon_id,
                                                        rib.access_level,
                                                    ) === lv.value &&
                                                        'level-btn--active',
                                                    isBlocked(
                                                        'ribbon',
                                                        lv.value,
                                                        mod.module_id,
                                                    ) && 'level-btn--blocked',
                                                ]"
                                                :title="
                                                    isBlocked(
                                                        'ribbon',
                                                        lv.value,
                                                        mod.module_id,
                                                    )
                                                        ? `Blocked — max is ${accessLevels[modLevel(mod.module_id)]?.label}`
                                                        : lv.label
                                                "
                                                :disabled="
                                                    isBlocked(
                                                        'ribbon',
                                                        lv.value,
                                                        mod.module_id,
                                                    )
                                                "
                                                :style="
                                                    getLevel(
                                                        'ribbon',
                                                        rib.ribbon_id,
                                                        rib.access_level,
                                                    ) === lv.value &&
                                                    !isBlocked(
                                                        'ribbon',
                                                        lv.value,
                                                        mod.module_id,
                                                    )
                                                        ? {
                                                              background:
                                                                  lv.color,
                                                              borderColor:
                                                                  lv.color,
                                                              color: 'white',
                                                          }
                                                        : {}
                                                "
                                                @click="
                                                    setLevel(
                                                        'ribbon',
                                                        rib.ribbon_id,
                                                        lv.value,
                                                        rib.module_id,
                                                    )
                                                "
                                            >
                                                <i
                                                    :class="`fa-solid ${lv.icon}`"
                                                />
                                            </button>
                                        </div>

                                        <span
                                            v-if="
                                                `ribbon-${rib.ribbon_id}` in
                                                pendingChanges
                                            "
                                            class="pending-dot-small"
                                        />
                                        <span
                                            style="width: 20px; flex-shrink: 0"
                                        />
                                    </div> -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!accessData.categories.length"
                        class="d-flex flex-column align-center py-8 text-medium-emphasis">
                        <i class="fa-solid fa-lock mb-2" style="font-size: 36px; opacity: 0.2" />
                        <p class="text-body-2">
                            {{ t("access_levels.no_access") }}
                        </p>
                    </div>
                </div>

                <div v-else class="d-flex flex-column align-center py-12 text-medium-emphasis">
                    <i class="fa-solid fa-circle-exclamation mb-2" style="font-size: 36px; opacity: 0.2" />
                    <p class="text-body-2">
                        {{ t("access_levels.failed_access") }}
                    </p>
                </div>
            </VCardText>

            <!-- Footer -->
            <div class="modal-footer">
                <p class="footer-hint">
                    <i class="fa-solid fa-circle-info me-1" />
                    {{ t("access_levels.lowering") }}
                </p>
                <VBtn variant="tonal" rounded="lg" size="small" @click="close">{{ t("close") }}</VBtn>
            </div>
        </VCard>
    </VDialog>
</template>

<style scoped>
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.modal-avatar {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.modal-username {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 2px;
}

.modal-email {
    font-size: 12px;
    color: #94a3b8;
    margin: 0;
}

.pending-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 20px;
    background: #fffbeb;
    border-bottom: 1px solid #fde68a;
    font-size: 12px;
    font-weight: 600;
    color: #92400e;
    gap: 12px;
}

.saved-banner {
    display: flex;
    align-items: center;
    padding: 10px 20px;
    background: #f0fdf4;
    border-bottom: 1px solid #bbf7d0;
    font-size: 12px;
    font-weight: 600;
    color: #166534;
}

.pending-dot {
    color: #f59e0b;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {

    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0.4;
    }
}

.btn-discard {
    padding: 5px 12px;
    border-radius: 7px;
    border: 1px solid #fcd34d;
    background: white;
    color: #92400e;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background 0.15s;
}

.btn-discard:hover {
    background: #fef3c7;
}

.btn-save-changes {
    padding: 5px 14px;
    border-radius: 7px;
    border: none;
    background: #6366f1;
    color: white;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}

.btn-save-changes:hover {
    background: #5558e8;
}

.btn-save-changes:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.slide-down-enter-active,
.slide-down-leave-active {
    transition: all 0.25s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 10px 14px;
    background: #f8fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
}

.access-block {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.access-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 14px;
    cursor: pointer;
    transition: background 0.12s;
}

.access-row--cat {
    background: #fafafa;
}

.access-row--cat:hover {
    background: #f1f5f9;
}

.access-row--mod {
    background: white;
    border-top: 1px solid #f1f5f9;
    padding-left: 26px;
}

.access-row--mod:hover {
    background: #f8fafc;
}

.access-row--rib {
    background: #fdfdfd;
    border-top: 1px solid #f8fafc;
    padding-left: 42px;
    cursor: default;
}

.access-icon {
    width: 18px;
    text-align: center;
    font-size: 13px;
    flex-shrink: 0;
}

.access-name {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 1px;
}

.access-sub {
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 0;
}

.expand-arrow {
    font-size: 10px;
    color: #94a3b8;
    transition: transform 0.2s;
    flex-shrink: 0;
}

.level-selector {
    display: flex;
    gap: 3px;
    flex-shrink: 0;
}

.level-btn {
    width: 26px;
    height: 26px;
    border-radius: 7px;
    border: 1.5px solid #e2e8f0;
    background: white;
    font-size: 10px;
    color: #94a3b8;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.12s;
}

.level-btn:hover:not(:disabled):not(.level-btn--blocked) {
    border-color: #6366f1;
    color: #6366f1;
    background: #eef2ff;
}

.level-btn--active {
    font-weight: 700;
}

.level-btn--blocked {
    opacity: 0.2;
    cursor: not-allowed;
    background: #f1f5f9;
}

.pending-dot-small {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #f59e0b;
    flex-shrink: 0;
    animation: pulse 1.5s infinite;
}

.blocked-hint {
    padding: 8px 26px;
    font-size: 11px;
    color: #ef4444;
    background: #fff5f5;
    border-top: 1px solid #fee2e2;
    display: flex;
    align-items: center;
}

.sub-list {
    border-top: 1px solid #f1f5f9;
}

.empty-sub {
    padding: 9px 26px;
    font-size: 12px;
    color: #94a3b8;
    font-style: italic;
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: #fafafa;
}

.footer-hint {
    font-size: 11px;
    color: #94a3b8;
    margin: 0;
}
</style>
