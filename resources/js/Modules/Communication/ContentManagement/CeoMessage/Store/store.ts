import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import type { CeoMessage, RawCeoMessage } from './type'

export const useCeoMessageStore = defineStore('ceoMessage', {
  state: () => ({
    ceoMessage: null as CeoMessage | null,
    snackbar: false,
    snackMsg: '',
    snackColor: 'success' as 'success' | 'error',
    _interval: null as ReturnType<typeof setInterval> | null,
    isLoading: false,
  }),

  actions: {
    transformRawData(rawData: RawCeoMessage | null): CeoMessage | null {
      if (!rawData) return null

      const imageUrl = rawData.image_url ?? null
      const videoUrl = rawData.video_url ?? null

      return {
        id: rawData.id,
        message: rawData.message,
        assets_file_id: rawData.assets_file_id,
        assetsFile: rawData.assets_file ? {
          id: rawData.assets_file.id,
          title: rawData.assets_file.title,
          image: rawData.assets_file.image,
          video: rawData.assets_file.video,
        } : null,
        image_url: imageUrl,
        video_url: videoUrl,
        created_at: rawData.created_at,
        updated_at: rawData.updated_at,
        deleted_at: rawData.deleted_at,
      }
    },

    setCeoMessage(data: RawCeoMessage | RawCeoMessage[] | null) {
      if (!data) {
        this.ceoMessage = null
        return
      }

      const rawData = Array.isArray(data) ? data[0] : data
      this.ceoMessage = this.transformRawData(rawData)

      if (import.meta.env.DEV) {
        console.log('Store updated:', {
          hasImage: !!this.ceoMessage?.image_url,
          hasVideo: !!this.ceoMessage?.video_url,
          imageUrl: this.ceoMessage?.image_url?.substring(0, 50) + '...',
          videoUrl: this.ceoMessage?.video_url,
        })
      }
    },

    store(form: FormData) {
      this.isLoading = true
      router.post('/ceo-message/store', form, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
          this.showSnack('CEO message published successfully', 'success')
          this.isLoading = false
        },
        onError: (errors) => {
          console.error('Store error:', errors)
          this.showSnack('Failed to publish CEO message', 'error')
          this.isLoading = false
        },
      })
    },

    update(id: number, form: FormData) {
      this.isLoading = true
      router.post(`/ceo-message/${id}`, form, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
          this.showSnack('CEO message updated successfully', 'success')
          this.isLoading = false
        },
        onError: (errors) => {
          console.error('Update error:', errors)
          this.showSnack('Failed to update CEO message', 'error')
          this.isLoading = false
        },
      })
    },

    submit(fields: {
      message: string
      image?: File | null
      video?: File | null
      removeMedia?: boolean
    }) {
      const form = new FormData()
      form.append('message', fields.message)

      if (fields.image instanceof File) {
        form.append('image', fields.image)
      }

      if (fields.video instanceof File) {
        form.append('video', fields.video)
      }

      if (fields.removeMedia) {
        form.append('remove_media', '1')
      }

      if (this.ceoMessage?.id) {
        form.append('_method', 'PUT')
        this.update(this.ceoMessage.id, form)
      } else {
        this.store(form)
      }
    },

    reset() {
      this.ceoMessage = null
    },

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg = msg
      this.snackColor = color
      this.snackbar = true
      setTimeout(() => { this.snackbar = false }, 3500)
    },

    startPolling(seconds = 30) {
      this.stopPolling()
      this._interval = setInterval(() => {
        router.reload({ only: ['ceo'] })
      }, seconds * 1000)
    },

    stopPolling() {
      if (this._interval) {
        clearInterval(this._interval)
        this._interval = null
      }
    },
  },

  getters: {
    hasMessage: (state) => !!state.ceoMessage?.message,
    hasImage: (state) => !!state.ceoMessage?.image_url,
    hasVideo: (state) => !!state.ceoMessage?.video_url,
    messageTitle: (state) => state.ceoMessage?.id ? 'Édition du message' : 'Nouveau message',
  },
})
