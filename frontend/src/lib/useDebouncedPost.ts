import { ref, watch, type Ref } from 'vue'
import { api, ApiError } from '@/api/client'

export function useDebouncedPost<TReq, TRes>(path: string, payload: Ref<TReq | null>, delay = 250) {
  const data = ref<TRes | null>(null) as Ref<TRes | null>
  const pending = ref(false)
  const error = ref('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let seq = 0

  watch(
    payload,
    (p) => {
      if (p === null) return
      clearTimeout(timer)
      timer = setTimeout(async () => {
        const mine = ++seq
        pending.value = true
        try {
          const res = await api<TRes>('POST', path, p)
          if (mine === seq) {
            data.value = res
            error.value = ''
          }
        } catch (e) {
          if (mine === seq) error.value = e instanceof ApiError ? e.message : 'Request failed.'
        } finally {
          if (mine === seq) pending.value = false
        }
      }, delay)
    },
    { deep: true, immediate: true },
  )

  return { data, pending, error }
}
