export class ApiError extends Error {
  constructor(
    message: string,
    public status = 0,
  ) {
    super(message);
  }
}

/** Bound headers and body reads, including reverse-proxy failures. */
export async function apiRequest<T>(url: string, init: RequestInit): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 25000);
  try {
    const response = await fetch(url, { ...init, signal: controller.signal });
    const raw = await response.text();
    let value;
    try {
      value = JSON.parse(raw);
    } catch {
      throw new ApiError(
        `服务返回了空响应或无效数据（HTTP ${response.status}），请稍后重试；如持续出现，请联系管理员检查 API 反代及后端日志。`,
        response.status,
      );
    }
    if (!response.ok)
      throw new ApiError(
        value?.error?.message || `请求失败（HTTP ${response.status}），请稍后重试`,
        response.status,
      );
    if (value === null || typeof value !== 'object')
      throw new ApiError('服务响应格式无效，请稍后重试', response.status);
    return value as T;
  } catch (e) {
    if (controller.signal.aborted) throw new ApiError('请求超时，请稍后重试');
    if (e instanceof ApiError) throw e;
    throw new ApiError('无法连接服务，请检查网络后重试');
  } finally {
    clearTimeout(timer);
  }
}
