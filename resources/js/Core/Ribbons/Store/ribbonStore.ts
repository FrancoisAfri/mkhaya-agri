import { defineStore } from "pinia";
import { router } from "@inertiajs/vue3";
import type { RibbonProperties } from "../types/type";

export const useRibbonStore = defineStore("ribbon", {
    state: () => ({
        ribbons: [] as RibbonProperties[],
        snackbar: false,
        snackMsg: "",
        snackColor: "success" as "success" | "error",
        _interval: null as ReturnType<typeof setInterval> | null,
    }),

    actions: {
        setRibbons(data: RibbonProperties[] | null) {
            this.ribbons = data ?? [];
        },

        addRibbon(data: RibbonProperties) {
            this.showSnack(
                `"${data.ribbon_name}" added successfully`,
                "success",
            );
        },

        deleteRibbon(id: number, name: string) {
            router.delete(`/ribbon/delete/${id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    this.ribbons = this.ribbons.filter((r) => r.id !== id);
                    this.showSnack(`"${name}" deleted`, "success");
                },
                onError: () => {
                    this.showSnack(`Failed to delete "${name}"`, "error");
                },
            });
        },

        updateRibbon(data: RibbonProperties) {
            const index = this.ribbons.findIndex((r) => r.id === data.id);
            if (index !== -1) this.ribbons[index] = data;
            this.showSnack(
                `"${data.ribbon_name}" updated successfully`,
                "success",
            );
        },

        showSnack(msg: string, color: "success" | "error" = "success") {
            this.snackMsg = msg;
            this.snackColor = color;
            this.snackbar = true;
        },

        startPolling(seconds = 10) {
            this.stopPolling();
            this._interval = setInterval(() => {
                router.reload({ only: ["ribbons"] });
            }, seconds * 1000);
        },

        stopPolling() {
            if (this._interval) {
                clearInterval(this._interval);
                this._interval = null;
            }
        },
    },
});
