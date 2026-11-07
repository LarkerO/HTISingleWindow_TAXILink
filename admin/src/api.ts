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
  const r = await fetch('/api' + path, {
    method,
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const d = await r.json();
  if (!r.ok) throw new Error(d.error?.message || '请求失败');
  return d;
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
