import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';

import { Avatar } from '@/Components/UI/Avatar';
import { IconSearch, IconX } from '@/Components/Icons';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { UserSummary } from '@/types/tickets';

/**
 * Choosing a person, by searching for them.
 *
 * Every picker in Ticktz used to be a `<select>` filled from a list rendered
 * into the page — the first five hundred agents by name, or the first hundred
 * users, with the rest dropped and nothing saying so. On a desk of forty that
 * is invisible; on a desk of four hundred, half the staff simply cannot be
 * chosen and nobody can work out why.
 *
 * It also could not tell the truth about the assignable set. A ticket may only
 * be held by somebody on its team, and a list assembled by the page had no way
 * to know that — so it offered names the server then refused, which an operator
 * reads as a broken product rather than as a rule. `GET /people` answers both
 * questions at once: who matches what you typed, out of who is actually
 * allowed.
 *
 * What the control has to get right beyond that:
 *
 * - **It opens with something in it.** A desk with five agents must not make
 *   anybody type to see five names, so focusing asks with an empty term.
 * - **A failed search says so.** An empty list that means "the request was
 *   refused" reads as "there is nobody", and somebody will believe it.
 * - **It is a real combobox**, not a text box with a `<div>` under it: arrow
 *   keys, Enter, Escape, and `aria-activedescendant` so that what the keyboard
 *   is on is what a screen reader reads.
 */

export interface PeopleQuery {
    /** Which set to search. See App\Http\Controllers\PeopleController. */
    scope: 'assignee' | 'agent' | 'user';
    /** A ticket key, when the answer depends on a ticket that exists. */
    ticket?: string;
    /** The team and queue chosen so far, when it does not. */
    teamId?: number | string | null;
    queueId?: number | string | null;
}

function queryString(query: PeopleQuery, term: string): string {
    const params = new URLSearchParams({ scope: query.scope });

    if (term.trim() !== '') params.set('q', term.trim());
    if (query.ticket) params.set('ticket', query.ticket);
    if (query.teamId) params.set('team_id', String(query.teamId));
    if (query.queueId) params.set('queue_id', String(query.queueId));

    return params.toString();
}

type SearchState = { people: UserSummary[]; loading: boolean; failed: boolean };

/**
 * The search itself: debounced, cancellable, and honest about failing.
 *
 * `enabled` is the open state. A closed picker must not be polling, and the
 * abort on cleanup is what stops a slow answer from overwriting a fast one
 * typed after it — the classic result-flicker where the list briefly shows
 * matches for what you had typed two keystrokes ago.
 */
function usePeopleSearch(query: PeopleQuery, term: string, enabled: boolean): SearchState {
    const [state, setState] = useState<SearchState>({ people: [], loading: false, failed: false });
    const search = queryString(query, term);

    useEffect(() => {
        if (!enabled) return;

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true, failed: false }));

        // No debounce on the opening request: the list should be there when
        // the box is, and that one carries no term to have been typed.
        const delay = term.trim() === '' ? 0 : 200;

        const timer = setTimeout(() => {
            fetch(`/people?${search}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) throw new Error(String(response.status));

                    return response.json();
                })
                .then((data: { people: UserSummary[] }) =>
                    setState({ people: data.people ?? [], loading: false, failed: false }),
                )
                .catch((error: unknown) => {
                    // An abort is this effect being cleaned up, not a failure.
                    if (error instanceof DOMException && error.name === 'AbortError') return;

                    setState({ people: [], loading: false, failed: true });
                });
        }, delay);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search, term, enabled]);

    return state;
}

interface CoreProps {
    query: PeopleQuery;
    /** Ids already chosen, which the list offers but marks as taken. */
    exclude?: number[];
    onPick: (person: UserSummary) => void;
    disabled?: boolean;
    placeholder?: string;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
    'aria-labelledby'?: string;
}

/**
 * The text box and the list under it. Shared by both pickers, which differ
 * only in what they do with what comes back.
 */
function PersonSearchBox({
    query,
    exclude = [],
    onPick,
    disabled,
    placeholder,
    id,
    'aria-invalid': ariaInvalid,
    'aria-describedby': describedBy,
    'aria-labelledby': labelledBy,
}: CoreProps) {
    const { t } = useTranslations();
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const listId = `${inputId}-listbox`;
    const statusId = `${inputId}-status`;

    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const container = useRef<HTMLDivElement>(null);

    const { people, loading, failed } = usePeopleSearch(query, term, open);
    const offered = useMemo(() => people.filter((person) => !exclude.includes(person.id)), [people, exclude]);

    // A list that changed under the cursor must not leave it pointing past the
    // end, which would make Enter do nothing for no visible reason.
    useEffect(() => setActive(0), [offered.length]);

    const close = useCallback(() => {
        setOpen(false);
        setTerm('');
    }, []);

    // Clicking anywhere else closes it. `mousedown` rather than `click` so a
    // click that lands on another control does not first re-open this one.
    useEffect(() => {
        if (!open) return;

        const onDown = (event: MouseEvent) => {
            if (!container.current?.contains(event.target as Node)) close();
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open, close]);

    const choose = (person: UserSummary) => {
        onPick(person);
        close();
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape') {
            close();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!open) {
                setOpen(true);

                return;
            }

            if (offered.length === 0) return;

            const step = event.key === 'ArrowDown' ? 1 : -1;

            setActive((current) => (current + step + offered.length) % offered.length);

            return;
        }

        if (event.key === 'Enter' && open && offered[active]) {
            event.preventDefault();
            choose(offered[active]);
        }
    };

    return (
        <div ref={container} className="relative">
            <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            <input
                id={inputId}
                type="text"
                role="combobox"
                autoComplete="off"
                disabled={disabled}
                value={term}
                placeholder={placeholder ?? t('common.people.search')}
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-invalid={ariaInvalid}
                aria-labelledby={labelledBy}
                aria-describedby={[describedBy, statusId].filter(Boolean).join(' ') || undefined}
                aria-activedescendant={open && offered[active] ? `${listId}-${offered[active].id}` : undefined}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setTerm(event.target.value);
                    setOpen(true);
                }}
                onKeyDown={onKeyDown}
                className={cn(
                    'block w-full rounded-lg border-slate-300 bg-white pl-9 text-sm text-slate-900 shadow-sm',
                    'placeholder:text-slate-500 focus:border-brand-500 focus:ring-brand-500',
                    'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
                )}
            />

            {/* Announced rather than only drawn: somebody who cannot see the
                list still has to learn that the search failed or found nobody.
                This is the only copy that assistive technology reads — the two
                messages inside the list below are the same sentences drawn for
                somebody who can see them, and are hidden from it so that a
                failed search is not read out twice. */}
            <p id={statusId} className="sr-only" role="status">
                {failed
                    ? t('common.people.failed')
                    : loading
                      ? t('common.people.searching')
                      : offered.length === 0
                        ? t('common.people.no_matches', { term: term.trim() || '—' })
                        : t('common.people.result_count', { count: String(offered.length) })}
            </p>

            <ul
                id={listId}
                role="listbox"
                className={cn(
                    'absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg',
                    !open && 'hidden',
                )}
            >
                {failed ? (
                    <li aria-hidden="true" className="px-3 py-2 text-sm text-red-600">
                        {t('common.people.failed')}
                    </li>
                ) : offered.length === 0 && !loading ? (
                    <li aria-hidden="true" className="px-3 py-2 text-sm text-slate-500">
                        {t('common.people.no_matches', { term: term.trim() || '—' })}
                    </li>
                ) : (
                    offered.map((person, index) => (
                        <li
                            key={person.id}
                            id={`${listId}-${person.id}`}
                            role="option"
                            aria-selected={index === active}
                        >
                            <button
                                type="button"
                                // `mousedown`, because `click` fires after the
                                // input has already lost focus and closed the
                                // list out from under the pointer.
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(person);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn(
                                    'flex w-full items-center gap-2.5 px-3 py-2 text-left',
                                    index === active ? 'bg-brand-50' : 'hover:bg-slate-50',
                                )}
                            >
                                <Avatar
                                    name={person.name}
                                    initials={person.initials}
                                    color={person.avatar_color}
                                    size="sm"
                                />
                                <span className="min-w-0">
                                    <span className="block truncate text-sm text-slate-800">{person.name}</span>
                                    <span className="block truncate text-xs text-slate-500">{person.email}</span>
                                </span>
                            </button>
                        </li>
                    ))
                )}
            </ul>
        </div>
    );
}

/** One person, or nobody. */
export function PersonPicker({
    value,
    onChange,
    query,
    exclude,
    allowNobody = false,
    disabled,
    placeholder,
    ...aria
}: {
    value: UserSummary | null;
    onChange: (person: UserSummary | null) => void;
    query: PeopleQuery;
    /**
     * Ids the list must not offer, for a rule the server also enforces —
     * nobody is their own manager. Offering a name that will come back as a
     * validation error is worse than leaving it out.
     */
    exclude?: number[];
    /** Whether clearing the field is a legitimate answer, e.g. unassigned. */
    allowNobody?: boolean;
    disabled?: boolean;
    placeholder?: string;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}) {
    const { t } = useTranslations();

    if (value) {
        return (
            <div className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-sm">
                <Avatar name={value.name} initials={value.initials} color={value.avatar_color} size="sm" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-slate-800">{value.name}</span>
                    <span className="block truncate text-xs text-slate-500">{value.email}</span>
                </span>
                {disabled ? null : (
                    <button
                        type="button"
                        aria-label={allowNobody ? t('common.people.clear') : t('common.actions.edit')}
                        onClick={() => onChange(null)}
                        className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    >
                        <IconX className="h-3.5 w-3.5" />
                    </button>
                )}
            </div>
        );
    }

    return (
        <PersonSearchBox
            query={query}
            exclude={exclude}
            disabled={disabled}
            placeholder={placeholder ?? (allowNobody ? t('common.people.nobody') : undefined)}
            onPick={onChange}
            {...aria}
        />
    );
}

/** Several people. */
export function PersonMultiPicker({
    value,
    onChange,
    query,
    disabled,
    placeholder,
    emptyLabel,
    ...aria
}: {
    value: UserSummary[];
    onChange: (people: UserSummary[]) => void;
    query: PeopleQuery;
    disabled?: boolean;
    placeholder?: string;
    emptyLabel?: string;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}) {
    const { t } = useTranslations();
    const chosen = useMemo(() => value.map((person) => person.id), [value]);

    return (
        <div className="space-y-2">
            {value.length > 0 ? (
                <ul className="flex flex-wrap gap-1.5">
                    {value.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-1.5 rounded-full bg-slate-100 py-1 pl-1 pr-2 text-sm text-slate-700"
                        >
                            <Avatar
                                name={person.name}
                                initials={person.initials}
                                color={person.avatar_color}
                                size="xs"
                            />
                            <span className="max-w-[12rem] truncate">{person.name}</span>
                            {disabled ? null : (
                                <button
                                    type="button"
                                    aria-label={t('common.people.remove', { name: person.name })}
                                    onClick={() => onChange(value.filter((other) => other.id !== person.id))}
                                    className="text-slate-400 hover:text-slate-700"
                                >
                                    <IconX className="h-3 w-3" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : emptyLabel ? (
                <p className="text-sm text-slate-500">{emptyLabel}</p>
            ) : null}

            {disabled ? null : (
                <PersonSearchBox
                    query={query}
                    exclude={chosen}
                    placeholder={placeholder}
                    onPick={(person) => onChange([...value, person])}
                    {...aria}
                />
            )}
        </div>
    );
}
