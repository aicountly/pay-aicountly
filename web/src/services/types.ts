/**
 * The shapes the Pay API returns.
 *
 * There is no Invoice type here, and no Customer with an address on it. Those
 * belong to the products that own them, and a type for one in this file would
 * be the first step towards a component that expects Pay to have it.
 *
 * What IS typed below is a METRIC — a figure plus everything needed to read it
 * honestly — and the panels built out of them.
 */

export type MetricStatus = 'ready' | 'loading' | 'unavailable'
export type MetricFormat = 'money' | 'count' | 'percent' | 'state'
export type MetricBasis = 'period' | 'as_of' | 'window' | 'count' | 'stated'

export interface MetricComparison {
  available: boolean
  label: string
  detail?: string
  percent?: number
  direction?: 'up' | 'down'
  /** Whether this direction is welcome. A rise in failed payments is not. */
  tone?: 'positive' | 'warning' | 'neutral'
  previous?: number
}

export interface Metric {
  id: string
  label: string
  value: number | null
  format: MetricFormat
  currency: string | null
  status: MetricStatus
  basis: MetricBasis
  definition: string
  detail: string | null
  comparison: MetricComparison | null
  /** Present only when status is 'unavailable'. Never a zero in disguise. */
  reason?: string
  /** For format 'state' — Managed status and KYC, which are not numbers. */
  state?: string
  state_label?: string
}

export interface PeriodDescription {
  key: string
  from: string
  to: string
  label: string
  previous_from: string
  previous_to: string
  previous_label: string
  timezone: string
  in_progress: boolean
  bucket: string
}

export interface MenuEntry {
  key: string
  label: string
  path: string
  permission: string
  badge?: string
}

export interface DashboardEntry {
  key: string
  label: string
  permission: string
}

export interface PaySession {
  user: { uuid: string; name: string; has_name: boolean; is_owner: boolean }
  /** `name` is what Manage called this company on this request; null when it did not say. */
  company: { cmp_id: number; bo_id: number; name: string | null }
  permissions: string[]
  menu: MenuEntry[]
  dashboards: DashboardEntry[]
  landing: string
  settings: PaySettings
  capabilities: {
    managed: { available: boolean; provider: string | null; reason: string | null }
    providers: Array<{ code: string; name: string; mode: string; managed_capable: boolean }>
    sources: Array<{ app: string; name: string; available: boolean; first_party: boolean }>
    pay_pulse: boolean
    encryption_ready: boolean
  }
}

export interface PaySettings {
  checkout: {
    display_name: string | null
    logo_url: string | null
    support_email: string | null
    support_phone: string | null
    terms_url: string | null
  }
  payment_requests: {
    default_expiry_hours: number
    allow_partial_default: boolean
    min_partial_amount: number
  }
  refunds: { requires_approval: boolean; approval_threshold: number | null }
  pay_pulse: { enabled: boolean; auto_routing: boolean; auto_routing_note: string }
  currency: string
}

export interface CompanyOption {
  cmp_id: number
  name: string
  is_owner: boolean
}

// ---------------------------------------------------------------------------
// Dashboards
// ---------------------------------------------------------------------------

export interface TrendPoint {
  date: string
  successful: number
  pending: number
  failed: number
  refunded: number
}

export interface MethodSlice {
  method: string
  label: string
  amount: number
  count: number
  share: number
}

export interface ProviderRow {
  connection_id: number
  provider: string
  mode: string
  name: string
  collected: number
  payments: number
  success_rate: number | null
  avg_latency_ms: number | null
  connection_status: string | null
}

export interface SourceRow {
  source: string
  label: string
  count: number
  amount: number
}

export interface ActivityRow {
  payment_id: string
  at: string
  payer: string
  amount: number
  currency: string
  status: string
  status_label: string
  source: string
  reference: string | null
  method: string | null
}

export interface ActionItem {
  key: string
  tone: 'danger' | 'warning' | 'info'
  label: string
  count: number
  path: string
  detail?: string
}

export interface PulseHeadline {
  insight_id: string
  capability: string
  key: string
  severity: 'INFO' | 'OPPORTUNITY' | 'WARNING' | 'RISK'
  headline: string
  detail: string | null
  evidence: Record<string, unknown>
  confidence: number
  action: { kind: string; label: string; target: string; params?: Record<string, unknown> } | null
  value: number | null
  status: string
  computed_at: string
}

export interface OverviewDashboard {
  period: PeriodDescription
  metrics: Metric[]
  panels: {
    trend: TrendPoint[]
    method_mix: MethodSlice[]
    providers: ProviderRow[]
    open_by_source: SourceRow[]
    recent_activity: ActivityRow[]
    action_centre: ActionItem[]
  }
  pulse: PulseHeadline[]
  generated_at: string
}

export interface FunnelStage {
  key: string
  label: string
  count: number
  share: number
  note?: string
}

export interface CollectionsDashboard {
  period: PeriodDescription
  metrics: Metric[]
  panels: {
    funnel: {
      stages: FunnelStage[]
      created: number
      fully_paid: number
      partially_paid: number
      conversion_rate: number | null
      asked: number
      collected: number
    }
    by_source: Array<{ source: string; label: string; requests: number; asked: number; collected: number; success_rate: number | null }>
    by_channel: Array<{ kind: string; channel: string; label: string; sent: number; views: number; paid: number; collected: number; success_rate: number | null }>
    recovery: Array<{
      payment_request_id: string
      reference: string | null
      payer: string
      outstanding: number
      currency: string
      attempts: number
      last_method: string | null
      last_failure: string | null
      failed_at: string
      expires_at: string | null
    }>
    sources: Array<{ app: string; name: string; available: boolean; first_party: boolean }>
    can_create: boolean
    can_record_external: boolean
    external_methods: Record<string, string>
  }
  generated_at: string
}

export interface GatewayProvider {
  connection_id: string
  provider: string
  name: string
  mode: string
  status: string
  status_reason: string | null
  environment: string
  is_primary: boolean
  methods: string[]
  currencies: string[]
  capabilities: Record<string, boolean>
  success_rate: number | null
  avg_latency_ms: number | null
  last_error_code: string | null
  settlement_cycle: string | null
  credentials: Record<string, { configured: boolean; hint: string | null; rotated_at: string | null }>
  webhook_verified: boolean
  usable: boolean
}

export interface RoutingRuleRow {
  rule_id: number
  name: string
  priority: number
  is_active: boolean
  method: string | null
  currency: string | null
  scope: string | null
  source_app: string | null
  primary: string | null
  fallback: string | null
  allow_failover: boolean
  suggested_by_ai: boolean
  accepted_by: string | null
}

export interface GatewaysDashboard {
  period: PeriodDescription
  metrics: Metric[]
  panels: {
    providers: GatewayProvider[]
    routing_rules: RoutingRuleRow[]
    health_matrix: {
      rows: Array<{
        method: string
        label: string
        providers: Array<{ connection_id: number; name: string; success_rate: number | null; avg_latency_ms: number | null; calls: number }>
      }>
      window_hours: number
    }
    managed: {
      state: string
      label: string
      detail: string | null
      kyc_status: string
      kyc_label: string
      kyc_detail: string | null
      provider: string | null
      can_apply: boolean
      required_actions: unknown[]
      bank_last4?: string | null
      merchant_id?: string | null
    }
    technical: {
      webhook_success_rate: number | null
      webhooks_received: number
      signature_failures: number
      duplicate_callbacks: number
      webhook_failures: number
      avg_webhook_ms: number | null
      callbacks_pending: number
      callbacks_exhausted: number
      provider_calls: number
      provider_error_rate: number | null
      avg_latency_ms: number | null
    }
    traffic: ProviderRow[]
    available_providers: Array<{ code: string; name: string; mode: string; managed_capable: boolean }>
    can_manage: boolean
    can_route: boolean
  }
  pulse: PulseHeadline[]
  generated_at: string
}

export interface SettlementRow {
  settlement_id: string
  provider: string
  provider_name: string
  provider_mode: string
  status: string
  status_label: string
  settlement_date: string | null
  currency: string
  gross: number | null
  refunds: number | null
  provider_fee: number | null
  provider_tax: number | null
  adjustment: number | null
  expected_net: number | null
  settled_net: number | null
  difference: number | null
  bank_reference: string | null
  bank_credited_at: string | null
  transaction_count: number
}

export interface SettlementsDashboard {
  period: PeriodDescription
  metrics: Metric[]
  panels: {
    timeline: {
      available: boolean
      reason?: string
      settlement_id?: string
      steps: Array<{ key: string; label: string; detail: string; at: string | null; status: string }>
    }
    by_provider: Array<{ provider: string; name: string; mode: string; gross: number; refunds: number; fees: number; expected: number; settled: number; status: string }>
    flow: {
      steps: Array<{ key: string; label: string; detail: string; amount: number; count: number | null }>
      match_rate: number | null
      matched: number
      exceptions: number
    }
    exceptions: Array<{ kind: string; label: string; count: number; amount: number; severity: string }>
    recent: SettlementRow[]
    can_resolve: boolean
  }
  pulse: PulseHeadline[]
  generated_at: string
}

export interface PulseDashboard {
  enabled: boolean
  warming_up?: boolean
  reason?: string
  period: PeriodDescription
  metrics: Metric[]
  panels: {
    forecast?: {
      points: Array<{ date: string; actual: number | null; forecast: number | null; low: number | null; high: number | null }>
      expected_minor: number
      high_confidence_minor: number
      at_risk_minor: number
      confidence: number
      basis: string
      median_days_to_pay: number
    }
    behaviour?: Array<{ key: string; label: string; share: number; detail: string; count: number }>
    actions?: PulseHeadline[]
    insights?: PulseHeadline[]
    sample?: { payments: number; payers: number; days: number }
    can_act?: boolean
    auto_routing?: { enabled: boolean; detail: string }
  }
  generated_at?: string
}

// ---------------------------------------------------------------------------
// Lists
// ---------------------------------------------------------------------------

export interface PaymentRow {
  payment_id: string
  status: string
  status_label: string
  amount: number
  refunded: number
  currency: string
  method: string | null
  method_detail: string | null
  provider: string | null
  provider_mode: string
  channel: string
  attempt_no: number
  settlement_status: string
  source_sync_status: string
  paid_at: string | null
  created_at: string
  failure_reason: string | null
  payer?: string | null
  source?: string
  reference?: string | null
  payment_request_id?: string | null
  technical?: {
    provider_payment_id: string | null
    provider_order_id: string | null
    failure_code: string | null
    connection_id: number | null
    initiated_at: string | null
    authorized_at: string | null
    captured_at: string | null
  }
}

export interface PaymentRequestRow {
  payment_request_id: string
  status: string
  status_label: string
  amount: number
  amount_minor: number
  paid: number
  refunded: number
  outstanding: number
  currency: string
  source_app: string
  source_type: string | null
  source_id: string | null
  reference: string | null
  description: string | null
  allow_partial_payment: boolean
  min_partial_amount: number | null
  allowed_methods: string[]
  expires_at: string | null
  created_at: string
  paid_at: string | null
  cancelled_reason: string | null
  callback_reference: string | null
  views: number
  checkout_started: boolean
  payer?: { name: string | null; email: string | null; mobile: string | null }
  payments?: PaymentRow[]
  links?: LinkRow[]
  source?: { available: boolean; app?: string; reason?: string; document?: Record<string, unknown> } | null
}

export interface LinkRow {
  link_id: string
  kind: string
  channel: string
  status: string
  url: string
  qr_payload: string | null
  reusable: boolean
  expires_at: string | null
  views: number
  checkout_starts: number
  sent_to: string | null
  sent_at: string | null
  created_at: string
  provider: string | null
  payment_request_id?: string
  reference?: string | null
  payer?: string | null
  amount?: number
  paid?: number
  outstanding?: number
  request_status?: string
}

export interface RefundRow {
  refund_id: string
  payment_id: string | null
  status: string
  status_label: string
  amount: number
  currency: string
  kind: string
  reason: string
  reason_code: string | null
  requested_by: string
  requested_at: string
  approved_by: string | null
  approved_at: string | null
  refunded_at: string | null
  rejected_reason: string | null
  failure_reason: string | null
  provider: string | null
  settlement_status: string
  source_sync_status: string
  payer?: string | null
}

export interface CustomerRow {
  payer_id: string
  name: string
  email: string | null
  mobile: string | null
  kind: string
  source_app: string | null
  customer_ref: string | null
  payment_count: number
  total_paid: number
  last_paid_at: string | null
  segment: string
  preferred_method: string | null
}

export interface ReconciliationCase {
  case_id: string
  kind: string
  label: string
  severity: string
  status: string
  summary: string
  expected: number | null
  actual: number | null
  difference: number | null
  currency: string
  detail: Record<string, unknown>
  assigned_to: string | null
  resolution_note: string | null
  opened_at: string
  resolved_at: string | null
}

export interface MandateRow {
  mandate_id: string
  status: string
  status_label: string
  type: string
  max_amount: number | null
  currency: string
  frequency: string | null
  valid_from: string | null
  valid_until: string | null
  provider: string | null
  source_app: string | null
  source_reference: string | null
  last_debit_at: string | null
  last_debit_status: string | null
  consecutive_failures: number
  created_at: string
  payer?: string | null
}

export interface DisputeRow {
  dispute_id: string
  status: string
  status_label: string
  kind: string
  amount: number
  currency: string
  reason_code: string | null
  reason: string | null
  provider: string | null
  evidence_due_at: string | null
  days_to_respond: number | null
  evidence_submitted: boolean
  evidence: unknown[]
  notes: string | null
  opened_at: string
  closed_at: string | null
  source: string
  payment_id?: string | null
}
