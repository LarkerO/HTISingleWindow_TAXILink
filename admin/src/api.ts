import { apiRequest } from '../../shared/http';
export interface User {
  id: string;
  username: string;
  avatar: string | null;
  csrf: string;
  platformAdmin: boolean;
  memberships: { company_id: string; company_name: string; role: string }[];
}
let csrf = '';
export function setCsrf(value: string) {
  csrf = value;
}
export async function api<T = any>(
  path: string,
  body?: unknown,
  method = body === undefined ? 'GET' : 'POST',
): Promise<T> {
  return apiRequest<T>('/api' + path, {
    method,
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
}
export const money = (n: number) => `${(n / 100).toFixed(2)} 游戏币`;
export const states: Record<string, string> = {
  AWAITING_PAYMENT: '待付款',
  QUEUED: '候补排队',
  DISPATCHING: '正在派车',
  ACTIVE: '行程中',
  FINALIZING: '核对结算中',
  CANCEL_REQUESTED: '正在取消',
  NEEDS_ATTENTION: '需人工处理',
  COMPLETED: '已完成',
  CANCELLED: '已取消',
  FAILED: '失败',
};

export async function uploadImage(
  file: File,
  purpose: string,
  companyId = '',
): Promise<{ url: string }> {
  if (file.size > 5 * 1024 * 1024) throw new Error('图片不能超过 5MB');
  const body = new FormData();
  body.append('file', file);
  body.append('purpose', purpose);
  body.append('companyId', companyId);
  const response = await fetch('/api/admin/media', {
    method: 'POST',
    credentials: 'include',
    headers: { 'X-CSRF-Token': csrf },
    body,
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error?.message || '上传失败');
  return data;
}
