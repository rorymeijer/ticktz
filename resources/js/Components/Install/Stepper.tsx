import { IconCheckCircle } from '@/Components/Icons';
import { cn } from '@/lib/cn';

export interface WizardStep {
    key: string;
    label: string;
}

/**
 * The step rail down the left of the wizard.
 *
 * It shows every step from the start, including the ones not reached yet,
 * because the first question anybody installing something asks is how long
 * this is going to take. A progress bar that only reveals the next step
 * answers that with "unknown".
 *
 * Completed steps are clickable so an answer can be corrected without starting
 * again; steps ahead are not, because they may depend on answers not given.
 */
export function Stepper({
    steps,
    current,
    furthest,
    onSelect,
}: {
    steps: WizardStep[];
    current: number;
    furthest: number;
    onSelect: (index: number) => void;
}) {
    return (
        <ol className="flex gap-1 overflow-x-auto pb-2 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:pb-0">
            {steps.map((step, index) => {
                const done = index < furthest;
                const active = index === current;
                const reachable = index <= furthest;

                return (
                    <li key={step.key} className="shrink-0 lg:shrink">
                        <button
                            type="button"
                            disabled={!reachable}
                            onClick={() => reachable && onSelect(index)}
                            aria-current={active ? 'step' : undefined}
                            className={cn(
                                'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition-colors',
                                active && 'bg-white font-medium text-slate-900 shadow-card',
                                !active && reachable && 'text-slate-600 hover:bg-white/60',
                                !reachable && 'cursor-default text-slate-500',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                                    done && 'bg-emerald-600 text-white',
                                    active && !done && 'bg-brand-600 text-white',
                                    !done && !active && 'bg-slate-200 text-slate-600',
                                )}
                            >
                                {done ? <IconCheckCircle className="h-3.5 w-3.5" /> : index + 1}
                            </span>
                            <span className="whitespace-nowrap lg:whitespace-normal">{step.label}</span>
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}
