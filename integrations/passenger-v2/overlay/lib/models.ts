export type Membership = { company_id: string; company_name: string; role: string }
export type User = {
  id: string; username: string; avatar: string | null; firstLogin: number; csrf: string
  platformAdmin: boolean; memberships: Membership[]
}
export type Point = { id: string; name: string; description: string; group_id: string | null }
export type Group = { id: string; name: string }
export type PointsResponse = { items: Point[]; groups: Group[] }
export type Position = { x: number; y: number; z: number }
export type RouteLeg = { stage: string; startMeters: number; endMeters: number }
export type Geometry = { points: number[][]; progressMeters: number; totalMeters: number; legs?: RouteLeg[] }
export type Fare = { amountMinor: number; components: Record<string, number> }
export type Rules = { mode: string; payment: string }
export type Route = { distanceMeters: number; estimatedSeconds: number; pickupDistanceMeters: number; geometry: Geometry }
export type Offer = {
  id: string; companyId: string; companyName: string; vehicleName: string; capacity: number
  companyImageUrl?: string; vehicleImageUrl?: string
  fare: Fare; rules: Rules; route: Route
}
export type Telemetry = {
  assistanceRequired?: boolean
  obstruction?: { waitingSeconds: number; thresholdSeconds: number; explanation: string; blockers: { vehicleId: string; orderId?: string; returning?: boolean }[] } | null
  stage?: string; stageLabel?: string; geometry?: Geometry; position?: Position
  etaPickupSeconds?: number | null; etaDropoffSeconds?: number | null
}
export type Order = {
  vehiclePending?: boolean
  checkoutUrl?: string; demonstration?: boolean
  id: string; revision: number; state: string; pickup: string; dropoff: string
  passengers: number; companyId: string | null; amountMinor: number; paidMinor: number
  createdAt: number; billingStarted: number | null; offers: Offer[]; telemetry: Telemetry
}
export type Unavailable = { name: string; reason: string }
export type Quote = { id: string; expires: number; offers: Offer[]; unavailable: Unavailable[]; complete?: boolean; warning?: string }
export type Orders = { items: Order[]; total: number }
export type Session = { user: User | null; demo: boolean }

export const stateLabel: Record<string, string> = {
  AWAITING_PAYMENT: '待付款', QUEUED: '候补排队', DISPATCHING: '正在派车',
  ACTIVE: '行程进行中', FINALIZING: '正在核对结算', CANCEL_REQUESTED: '正在取消',
  NEEDS_ATTENTION: '等待调度协助', COMPLETED: '已完成', CANCELLED: '已取消', FAILED: '派车失败'
}

export function isTerminal(state: string): boolean {
  return ['COMPLETED', 'CANCELLED', 'FAILED'].includes(state)
}
export function formatDate(timestamp: number): string {
  const date = new Date(timestamp * 1000)
  return date.toLocaleString('zh-CN')
}

export type Announcement = { id: string; title: string; markdown?: string; updated_at: number; login_required: number }
export type AnnouncementList = { items: Announcement[]; total: number; page: number }
