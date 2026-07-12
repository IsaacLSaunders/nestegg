export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly violations: Record<string, string> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type UnauthorizedHandler = () => void
let onUnauthorized: UnauthorizedHandler = () => {}

export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  onUnauthorized = handler
}

interface ViolationItem {
  propertyPath: string
  title: string
}

export async function api<T>(
  method: string,
  path: string,
  body?: unknown,
  opts?: { silentUnauthorized?: boolean },
): Promise<T> {
  const init: RequestInit & { headers: Record<string, string> } = {
    method,
    credentials: 'same-origin',
    headers: {},
  }
  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(body)
  }

  const res = await fetch(path, init)
  if (res.status === 204) return undefined as T

  const data: Record<string, unknown> = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && !opts?.silentUnauthorized) onUnauthorized()
    const violations: Record<string, string> = {}
    if (Array.isArray(data.violations)) {
      for (const v of data.violations as ViolationItem[]) {
        violations[v.propertyPath] ??= v.title
      }
    }
    const message =
      (data.error as string | undefined) ??
      (data.detail as string | undefined) ??
      (data.title as string | undefined) ??
      `Request failed (${res.status})`
    throw new ApiError(res.status, message, violations)
  }
  return data as T
}
