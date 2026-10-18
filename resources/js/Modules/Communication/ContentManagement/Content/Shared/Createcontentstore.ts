/**
 * Store générique partagé par News, Event, Announcement, InternalNotice.
 * Usage : defineStore(`content_${type}`, useContentStoreDefinition())
 */
import { defineStore } from "pinia";
import { router } from "@inertiajs/vue3";
import type { Content, ContentType } from "../utils/type";

export function createContentStore(storeId: string) {
    return defineStore(storeId, {
        state: () => ({
            list: [] as Content[],
            selected: null as Content | null,
            editing: null as Content | null,
            isLoading: false,
            snackbar: false,
            snackMsg: "",
            snackColor: "success" as "success" | "error",
        }),

        actions: {
            setList(data: Content[]) {
                this.list = data ?? [];
                if (this.list.length > 0 && !this.selected) {
                    this.selected = this.list[0];
                }
            },

            select(item: Content) {
                this.selected = item;
            },

            startEdit(item: Content) {
                this.editing = { ...item };
            },

            store(form: FormData, onSuccessCallback?: () => void) {
                if (this.isLoading) return;

                this.isLoading = true;

                router.post("/content/store", form, {
                    forceFormData: true,
                    preserveScroll: true,

                    onSuccess: () => {
                        this.showSnack("Created successfully", "success");
                        this.isLoading = false;

                        // Appeler le callback pour fermer le modal
                        onSuccessCallback?.();

                        // Recharger la page pour afficher les nouvelles données
                        router.reload({ only: ["contents"] });
                    },

                    onError: () => {
                        this.showSnack("Failed to create", "error");
                        this.isLoading = false;
                    },
                });
            },

            update(id: number, form: FormData, onSuccessCallback?: () => void) {
                if (this.isLoading) return;

                this.isLoading = true;

                router.post(`/content/${id}`, form, {
                    forceFormData: true,
                    preserveScroll: true,

                    onSuccess: () => {
                        this.showSnack("Updated successfully", "success");
                        this.editing = null;
                        this.isLoading = false;

                        // Appeler le callback pour fermer le modal
                        onSuccessCallback?.();

                        // Recharger la page pour afficher les données mises à jour
                        router.reload({ only: ["contents"] });
                    },

                    onError: () => {
                        this.showSnack("Failed to update", "error");
                        this.isLoading = false;
                    },
                });
            },

            remove(id: number) {
                router.delete(`/content/delete/${id}`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        this.list = this.list.filter((n) => n.id !== id);
                        if (this.selected?.id === id)
                            this.selected = this.list[0] ?? null;
                        this.showSnack("Deleted successfully", "success");
                    },
                    onError: () => this.showSnack("Failed to delete", "error"),
                });
            },

            showSnack(msg: string, color: "success" | "error" = "success") {
                this.snackMsg = msg;
                this.snackColor = color;
                this.snackbar = true;
                setTimeout(() => {
                    this.snackbar = false;
                }, 3500);
            },
        },
    });
}
