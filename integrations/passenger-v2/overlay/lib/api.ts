import { ref } from 'vue'
import { appOrigin, serviceUrl } from './config'
import type { Session, User } from './models'

export const user = ref<User | null>(null)
export const demo = ref<boolean>(false)
let refreshing: Promise<void> | null = null
let nativeCookie: string = ''
type ErrorDetail = { message?: string }
type ErrorBody = { error?: ErrorDetail }
type UploadResult = { url?: string; error?: ErrorDetail }
type RequestMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE'

export function message(error: any): string {
  return error instanceof Error ? error.message : String(error)
}

export function notify(error: any): void {
  uni.showToast({ title: message(error), icon: 'none', duration: 2600 })
}

export function api<T>(path: string, body: any = null, idempotencyKey: string = '',
  method: RequestMethod = body == null ? 'GET' : 'POST'): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    // #ifdef APP
    if (appOrigin.length == 0) {
      reject(new Error('请先配置 TAXILink 服务地址'))
      return
    }
    // #endif
    const headers: Record<string, string> = { 'Content-Type': 'application/json' }
    if (user.value != null) headers['X-CSRF-Token'] = user.value.csrf
    if (idempotencyKey.length > 0) headers['Idempotency-Key'] = idempotencyKey
    // #ifdef APP
    if (nativeCookie.length > 0) headers['Cookie'] = nativeCookie
    // #endif
    uni.request({
      url: serviceUrl('/api' + path),
      method,
      header: headers,
      data: body == null ? undefined : JSON.stringify(body),
      withCredentials: true,
      timeout: 25000,
      success: (result) => {
        if (result.statusCode == 401) {
          user.value = null
          nativeCookie = ''
        }
        let data: any = result.data
        if (typeof data == 'string') {
          try { data = JSON.parse(data) } catch { data = null }
        }
        if (data == null || typeof data != 'object') {
          reject(new Error('服务返回了空响应或无效数据（HTTP ' + result.statusCode + '），请稍后重试或联系管理员检查 API 反代'))
          return
        }
        if (result.statusCode < 200 || result.statusCode >= 300) {
          reject(new Error(data?.error?.message ?? '请求失败，请稍后重试'))
          return
        }
        resolve(data as T)
      },
      fail: (error) => { reject(new Error(error.errMsg.includes('timeout') ? '请求超时，请稍后刷新重试' : '无法连接 TAXILink，请稍后重试')) }
    })
  })
}

export function refreshUser(): Promise<void> {
  if (refreshing != null) return refreshing
  refreshing = api<Session>('/me').then((session): void => {
    user.value = session.user
    demo.value = session.demo
  }).finally((): void => { refreshing = null })
  return refreshing!
}

export async function adoptPortalSession(cookie: string): Promise<void> {
  if (!/^tl_session=[A-Za-z0-9_-]+$/.test(cookie)) throw new Error('未能读取 Portal 会话，请重试登录')
  if (refreshing != null) {
    try { await refreshing } catch {}
  }
  nativeCookie = cookie
  try {
    await refreshUser()
    if (user.value == null) throw new Error('Portal 会话已失效，请重试登录')
  } catch (error) {
    nativeCookie = ''
    throw error
  }
}

export async function logout(): Promise<void> {
  await api('/auth/logout', {})
  user.value = null
  nativeCookie = ''
}

export function uploadImage(filePath: string, purpose: string, companyId: string, provider: string = ''): Promise<string> {
  return new Promise<string>((resolve, reject) => {
    const headers: Record<string, string> = {}
    if (user.value != null) headers['X-CSRF-Token'] = user.value.csrf
    // #ifdef APP
    if (nativeCookie.length > 0) headers['Cookie'] = nativeCookie
    // #endif
    uni.uploadFile({
      url: serviceUrl('/api/admin/media'), filePath, name: 'file', header: headers,
      formData: provider.length > 0 ? { purpose, companyId, provider } : { purpose, companyId }, timeout: 60000,
      success: (response) => {
        try {
          const data = JSON.parse(response.data) as UploadResult
          if (response.statusCode < 200 || response.statusCode >= 300 || !data.url) {
            reject(new Error(data.error?.message ?? '上传失败')); return
          }
          resolve(data.url)
        } catch { reject(new Error('上传响应无效')) }
      },
      fail: () => { reject(new Error('图片上传失败，请检查网络')) }
    })
  })
}
