export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) { super(message); this.status = status; this.errors = errors }
}
export async function api<T>(path: string, options: {method?: string; body?: unknown; tenant?: string; signal?: AbortSignal} = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = {Accept: 'application/json'}
  if (options.tenant) headers['X-Tenant-ID'] = options.tenant
  if (method !== 'GET') {
    const csrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')
    if (csrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf)
    headers['Content-Type'] = 'application/json'
  }
  const response = await fetch(path, {method, headers, credentials: 'include', cache: 'no-store', signal: options.signal,
    body: options.body === undefined ? undefined : JSON.stringify(options.body)})
  const body = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) {
    if (response.status === 401 && path.startsWith('/api/') && path !== '/api/v1/me') window.dispatchEvent(new Event('session-expired'))
    const message = body?.errors ? Object.values(body.errors).flat().join(' ') : body?.message
    throw new ApiError(response.status, message || `Request failed (${response.status}). Please try again.`, body?.errors && typeof body.errors === 'object' ? body.errors : {})
  }
  return body as T
}
export async function csrf() { await api('/sanctum/csrf-cookie') }
export type User = {id: number; name: string; email: string; mfa_enrolled: boolean; mfa_verified: boolean}
export type Tenant = {id: string; name: string; requires_mfa: boolean}
export type Company = {id: string; name: string; code: string}
