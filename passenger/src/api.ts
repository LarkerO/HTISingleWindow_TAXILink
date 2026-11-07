import { ref } from 'vue';
export interface User {
  id: string;
  username: string;
  avatar: string | null;
  firstLogin: number;
  csrf: string;
  platformAdmin: boolean;
  memberships: { company_id: string; company_name: string; role: string }[];
}
export interface Point {
  id: string;
  name: string;
  description: string;
  group_id: string | null;
}
export interface Offer {
  id: string;
  companyId: string;
  companyName: string;
  centerId: string;
  centerName: string;
  vehicleId: string;
  vehicleName: string;
  capacity: number;
  rules: { mode: string; payment: string };
  fare: { amountMinor: number; components: Record<string, number> };
  route: {
    distanceMeters: number;
    estimatedSeconds: number;
    pickupDistanceMeters: number;
    returnDistanceMeters: number;
    geometry: Geometry;
  };
}
export interface Geometry {
  points: number[][];
  progressMeters: number;
  totalMeters: number;
  legs?: { stage: string; startMeters: number; endMeters: number }[];
}
export interface Order {
  id: string;
  state: string;
  pickup: string;
  dropoff: string;
  passengers: number;
  companyId: string | null;
  amountMinor: number;
  paidMinor: number;
  createdAt: number;
  billingStarted: number | null;
  offers: Offer[];
  telemetry: {
    stage?: string;
    stageLabel?: string;
    geometry?: Geometry;
    position?: { x: number; y: number; z: number };
    etaPickupSeconds?: number | null;
    etaDropoffSeconds?: number | null;
    speedKmh?: number;
  };
  queueUntil: number;
}
export const user = ref<User | null>(null),
  demo = ref(false),
  loginVisible = ref(false),
  error = ref('');
let afterLogin: (() => void) | undefined;
let ready = false,
  refreshing: Promise<void> | null = null;
export async function api<T>(
  path: string,
  body?: unknown,
  method = body === undefined ? 'GET' : 'POST',
  extra: Record<string, string> = {},
): Promise<T> {
  const r = await fetch('/api' + path, {
    method,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(user.value ? { 'X-CSRF-Token': user.value.csrf } : {}),
      ...extra,
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const value = await r.json();
  if (!r.ok) {
    if (r.status === 401) {
      user.value = null;
      loginVisible.value = true;
    }
    throw new Error(value.error?.message || '请求失败，请稍后重试');
  }
  return value;
}
export async function refreshUser() {
  if (refreshing) return refreshing;
  refreshing = (async () => {
    const d = await api<{ user: User | null; demo: boolean }>('/me');
    user.value = d.user;
    demo.value = d.demo;
    ready = true;
  })();
  try {
    await refreshing;
  } finally {
    refreshing = null;
  }
}
export function requireLogin(fn: () => void) {
  if (!ready) {
    refreshUser()
      .then(() => requireLogin(fn))
      .catch(report);
    return;
  }
  if (user.value) fn();
  else {
    afterLogin = fn;
    loginVisible.value = true;
  }
}
export async function demoLogin() {
  await api('/auth/demo', {});
  await refreshUser();
  loginVisible.value = false;
  afterLogin?.();
  afterLogin = undefined;
}
export function report(e: unknown) {
  error.value = e instanceof Error ? e.message : String(e);
}
export function money(n: number) {
  return (n / 100).toFixed(2);
}
export const stateLabel: Record<string, string> = {
  AWAITING_PAYMENT: '待付款',
  QUEUED: '候补排队',
  DISPATCHING: '正在派车',
  ACTIVE: '行程进行中',
  FINALIZING: '正在核对结算',
  CANCEL_REQUESTED: '正在取消',
  NEEDS_ATTENTION: '等待调度协助',
  COMPLETED: '已完成',
  CANCELLED: '已取消',
  FAILED: '派车失败',
};
