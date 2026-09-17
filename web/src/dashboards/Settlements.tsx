/**
 * Dashboard 4 — Settlements & Reconciliation.
 *
 * "Did my money arrive, and does it add up?"
 *
 * Pay answers exactly one question here: did what we collected reach the bank,
 * less what the provider said they would charge? What ledger a bank line
 * belongs to and what its accounting effect is remains Books' question — there
 * is deliberately nothing on this screen about either.
 */

import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  Banknote,
  Building2,
  CheckCircle2,
  Circle,
  CreditCard,
  FileText,
  Percent,
  RefreshCw,
  RotateCcw,
  Scale,
} from 'lucide-react'
import { date, money, moneyWhole, percent } from '../services/format'
import type { SettlementsDashboard } from '../services/types'
import { DashboardLayout } from './DashboardLayout'
import { useDashboard } from './useDashboard'
import { EmptyState, ErrorState, MetricRow, Panel, PulseStrip, SkeletonRows, StatusBadge } from './kit'

const ICONS = {
  gross: <Banknote size={18} />,
  refunds: <RotateCcw size={18} />,
  provider_fees: <Percent size={18} />,
  expected: <FileText size={18} />,
  settled: <CheckCircle2 size={18} />,
  unreconciled: <AlertTriangle size={18} />,
}

const TONES = {
  refunds: 'warning',
  provider_fees: 'warning',
  unreconciled: 'danger',
} as const

const STEP_ICONS: Record<string, typeof FileText> = {
  request: FileText,
  payment: CreditCard,
  provider: Building2,
  bank: Banknote,
  sync: RefreshCw,
}

export default function Settlements() {
  const navigate = useNavigate()
  const { data, loading, error, retryable, period, setPeriod, reload } =
    useDashboard<SettlementsDashboard>('v1/dashboards/settlements', 'last_30')

  const timeline = data?.panels.timeline
  const flow = data?.panels.flow

  return (
    <DashboardLayout
      title={<>Settlements & <span>Reconciliation</span></>}
      subtitle="Track settlements, reconcile transactions and make sure every rupee adds up"
      banner={{
        title: 'Faster settlements. Greater transparency.',
        detail: 'Every fee is the provider’s own figure — Pay never calculates a rate of its own.',
      }}
      period={period}
      onPeriodChange={setPeriod}
      description={data?.period ?? null}
    >
      {error && <ErrorState message={error} onRetry={reload} retryable={retryable} />}

      <MetricRow metrics={data?.metrics ?? []} loading={loading} icons={ICONS} tones={TONES} />

      <div className="pay-grid">
        <Panel
          title="Settlement timeline"
          description="The journey from a payment request to a bank credit"
          span={3}
        >
          {loading && !data ? (
            <SkeletonRows rows={3} />
          ) : !timeline?.available ? (
            <EmptyState title="No settlements yet">
              {timeline?.reason ?? 'Settlements appear here after your first provider settlement.'}
            </EmptyState>
          ) : (
            <div className="pay-timeline">
              {timeline.steps.map((step, index) => {
                const Icon = STEP_ICONS[step.key] ?? Circle
                const done = step.status === 'COMPLETED'

                return (
                  <div key={step.key} className={`pay-timeline__step ${done ? 'is-done' : ''}`.trim()}>
                    <span className="pay-timeline__mark" aria-hidden>
                      <Icon size={17} />
                    </span>
                    <span className="pay-timeline__label">
                      {index + 1}. {step.label}
                    </span>
                    <span className="pay-timeline__detail">{step.detail}</span>
                    <span style={{ display: 'block', marginTop: 6 }}>
                      <StatusBadge status={done ? 'SETTLED' : 'PENDING'} label={done ? 'Completed' : 'Pending'} />
                    </span>
                  </div>
                )
              })}
            </div>
          )}
        </Panel>

        <Panel title="Quick actions">
          <div style={{ display: 'grid', gap: 9 }}>
            <button type="button" className="pay-button pay-button--soft pay-button--block" onClick={() => navigate('/settlements')}>
              <Building2 size={16} aria-hidden /> See all settlements
            </button>
            <button type="button" className="pay-button pay-button--soft pay-button--block" onClick={() => navigate('/reconciliation')}>
              <Scale size={16} aria-hidden /> Open the Exception Centre
            </button>
            <button
              type="button"
              className="pay-button pay-button--ghost pay-button--block"
              disabled={!data?.panels.can_resolve}
              onClick={() => navigate('/settlements?import=1')}
            >
              <RefreshCw size={16} aria-hidden /> Pull settlements from providers
            </button>
          </div>

          <p style={{ margin: '14px 0 0', color: 'var(--pay-muted)', fontSize: 11.5 }}>
            Pay tracks whether a provider&rsquo;s settlement reached your bank. How those credits post to your ledgers stays in Smart Books.
          </p>
        </Panel>

        <Panel title="Provider settlement summary" description="Gross, fees and net, as each provider reported them" span={2}>
          {loading && !data ? (
            <SkeletonRows rows={4} />
          ) : (data?.panels.by_provider ?? []).length === 0 ? (
            <EmptyState title="No settlement batches in this period">
              Batches appear once your providers settle.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr>
                    <th>Provider</th>
                    <th className="num">Gross</th>
                    <th className="num">Refunds</th>
                    <th className="num">Fees</th>
                    <th className="num">Expected</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.panels.by_provider.map((row) => (
                    <tr key={`${row.provider}-${row.mode}`}>
                      <td><strong style={{ fontSize: 13 }}>{row.name}</strong></td>
                      <td className="num">{moneyWhole(row.gross)}</td>
                      <td className="num">{moneyWhole(row.refunds)}</td>
                      <td className="num">{moneyWhole(row.fees)}</td>
                      <td className="num">{moneyWhole(row.expected)}</td>
                      <td><StatusBadge status={row.status === 'Settled' ? 'SETTLED' : 'PENDING'} label={row.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <Panel
          title="Reconciliation flow"
          description="What each layer says the money was"
          action={
            flow?.match_rate !== null && flow?.match_rate !== undefined ? (
              <div style={{ textAlign: 'right' }}>
                <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>Matched</span>
                <strong style={{ fontSize: 19, color: 'var(--pay-action)' }}>{percent(flow.match_rate)}</strong>
              </div>
            ) : undefined
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={3} />
          ) : !flow ? null : (
            <div>
              {flow.steps.map((step) => (
                <div key={step.key} className="pay-action-row" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{step.label}</span>
                    <span className="pay-action-row__detail">{step.detail}</span>
                  </span>
                  <span style={{ textAlign: 'right', flexShrink: 0 }}>
                    <strong className="num" style={{ fontSize: 14 }}>{moneyWhole(step.amount)}</strong>
                    {step.count !== null && (
                      <span style={{ display: 'block', color: 'var(--pay-muted)', fontSize: 11.5 }}>
                        {step.count} transactions
                      </span>
                    )}
                  </span>
                </div>
              ))}

              {flow.exceptions > 0 && (
                <p style={{ margin: '12px 0 0', color: 'var(--pay-warning)', fontSize: 12.5, fontWeight: 600 }}>
                  {flow.exceptions} settlement line{flow.exceptions === 1 ? '' : 's'} did not match.
                </p>
              )}
            </div>
          )}
        </Panel>

        <Panel
          title="Exception centre"
          description="Differences with somebody's name on them"
          action={
            (data?.panels.exceptions ?? []).length > 0 ? (
              <span className="pay-count">
                {data?.panels.exceptions.reduce((sum, row) => sum + row.count, 0)} items
              </span>
            ) : undefined
          }
        >
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.exceptions ?? []).length === 0 ? (
            <EmptyState title="Everything reconciles">
              No settlement difference, missing credit or unclaimed payment is outstanding.
            </EmptyState>
          ) : (
            data?.panels.exceptions.map((exception) => (
              <button
                key={exception.kind}
                type="button"
                className="pay-action-row"
                onClick={() => navigate(`/reconciliation?kind=${exception.kind}`)}
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                  <span
                    className={`pay-action-row__mark ${exception.severity === 'HIGH' ? 'pay-action-row__mark--danger' : 'pay-action-row__mark--warning'}`}
                    aria-hidden
                  >
                    <AlertTriangle size={15} />
                  </span>
                  <span style={{ minWidth: 0 }}>
                    <span className="pay-action-row__label">{exception.label}</span>
                    {exception.amount > 0 && (
                      <span className="pay-action-row__detail">{moneyWhole(exception.amount)} involved</span>
                    )}
                  </span>
                </span>
                <strong className="num">{exception.count}</strong>
              </button>
            ))
          )}
        </Panel>

        <Panel title="Recent settlements" span={2}>
          {loading && !data ? (
            <SkeletonRows rows={5} />
          ) : (data?.panels.recent ?? []).length === 0 ? (
            <EmptyState title="No settlements recorded yet">
              Pull them from your providers, or they arrive automatically as the worker runs.
            </EmptyState>
          ) : (
            <div className="pay-table-wrap">
              <table className="pay-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Provider</th>
                    <th className="num">Expected</th>
                    <th className="num">Credited</th>
                    <th className="num">Difference</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.panels.recent.map((row) => (
                    <tr key={row.settlement_id}>
                      <td style={{ whiteSpace: 'nowrap' }}>{date(row.settlement_date)}</td>
                      <td>
                        <button type="button" className="pay-table__link" onClick={() => navigate(`/settlements/${row.settlement_id}`)}>
                          {row.provider_name}
                        </button>
                      </td>
                      <td className="num">{money(row.expected_net, row.currency)}</td>
                      <td className="num">{row.settled_net === null ? '—' : money(row.settled_net, row.currency)}</td>
                      <td className="num" style={{ color: (row.difference ?? 0) !== 0 ? 'var(--pay-danger)' : undefined }}>
                        {row.difference === null || row.difference === 0 ? '—' : money(row.difference, row.currency)}
                      </td>
                      <td><StatusBadge status={row.status} label={row.status_label} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <PulseStrip insights={data?.pulse ?? []} />
    </DashboardLayout>
  )
}
