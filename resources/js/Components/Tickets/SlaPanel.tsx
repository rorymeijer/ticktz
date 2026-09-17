import { SlaBadge, SlaProgress, formatDuration } from '@/Components/Tickets/SlaBadge';
import { Card, CardBody, CardHeader } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import type { SlaEventItem, SlaTimerSummary, TicketDetail } from '@/types/tickets';

/**
 * What the desk promised on this ticket, and how the clocks are doing.
 *
 * The event list underneath is the reason this panel exists at all: when a
 * requester asks why their ticket is marked as missed, the answer is here —
 * when the clock started, every time it stopped and why, and the moment it
 * ran out.
 */
export function SlaPanel({ ticket }: { ticket: TicketDetail }) {
    const { t, locale } = useTranslations();

    if (ticket.sla_timers.length === 0) {
        return (
            <Card>
                <CardHeader title={t('sla.panel.title')} />
                <CardBody>
                    <p className="text-sm text-slate-500">{t('sla.panel.empty')}</p>
                </CardBody>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader title={t('sla.panel.title')} />
            <CardBody className="space-y-4">
                {ticket.sla_timers.map((timer) => (
                    <SlaTimerRow key={timer.id} timer={timer} locale={locale} t={t} />
                ))}

                {ticket.sla_policy ? (
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 border-t border-slate-100 pt-3 text-xs">
                        <dt className="text-slate-500">{t('sla.panel.policy')}</dt>
                        <dd className="truncate text-slate-700">{ticket.sla_policy.name}</dd>
                        {ticket.sla_policy.calendar ? (
                            <>
                                <dt className="text-slate-500">{t('sla.panel.calendar')}</dt>
                                <dd className="truncate text-slate-700">{ticket.sla_policy.calendar}</dd>
                            </>
                        ) : null}
                    </dl>
                ) : null}

                {ticket.sla_events.length > 0 ? (
                    <details className="border-t border-slate-100 pt-3">
                        <summary className="cursor-pointer text-xs font-medium text-slate-600 hover:text-slate-900">
                            {t('sla.panel.history')}
                        </summary>
                        <ol className="mt-2 space-y-1.5">
                            {ticket.sla_events.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <span className="text-slate-700">{describeEvent(event, t)}</span>
                                    <time className="text-slate-500" dateTime={event.occurred_at}>
                                        {formatDateTime(event.occurred_at, locale)}
                                    </time>
                                </li>
                            ))}
                        </ol>
                    </details>
                ) : null}
            </CardBody>
        </Card>
    );
}

function SlaTimerRow({
    timer,
    locale,
    t,
}: {
    timer: SlaTimerSummary;
    locale: string;
    t: (key: string, replacements?: Record<string, string | number>) => string;
}) {
    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-slate-700">{t(`sla.metrics.${timer.metric}`)}</span>
                <SlaBadge timer={timer} />
            </div>

            <SlaProgress timer={timer} />

            <dl className="flex flex-wrap gap-x-4 text-xs text-slate-500">
                <div className="flex gap-1">
                    <dt>{t('sla.panel.target')}</dt>
                    <dd className="text-slate-700">{formatDuration(timer.target_minutes, t)}</dd>
                </div>
                <div className="flex gap-1">
                    <dt>{t('sla.panel.due')}</dt>
                    <dd className="text-slate-700">
                        <time dateTime={timer.due_at}>{formatDateTime(timer.due_at, locale)}</time>
                    </dd>
                </div>
            </dl>
        </div>
    );
}

/**
 * An event in words. The context each one carries differs, so this is a
 * lookup rather than one template with optional holes.
 */
function describeEvent(
    event: SlaEventItem,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    const metric = event.metric ? t(`sla.metrics.${event.metric}`) : '';
    const context = event.context ?? {};

    let text: string;

    if (event.event === 'escalated') {
        text = t('sla.events.escalated', { threshold: Number(context.threshold ?? 0) });
    } else if (event.event === 'recalculated') {
        text = t('sla.events.recalculated', {
            from: formatDuration(Number(context.from_minutes ?? 0), t),
            to: formatDuration(Number(context.to_minutes ?? 0), t),
        });
    } else {
        text = t(`sla.events.${event.event}`);
    }

    return metric ? `${metric} — ${text}` : text;
}
