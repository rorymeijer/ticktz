import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { BrandLockup } from '@/Components/Brand';
import { IconAlert, IconCheckCircle, IconServer } from '@/Components/Icons';
import { RequirementList, type Requirement } from '@/Components/Install/RequirementList';
import { Stepper, type WizardStep } from '@/Components/Install/Stepper';
import { LocaleSwitcher } from '@/Components/Nav/LocaleSwitcher';
import { Button, Field, Select, TextInput, Toggle } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';

/*
|--------------------------------------------------------------------------
| Shapes
|--------------------------------------------------------------------------
*/

interface Timezone {
    value: string;
    group: string;
    label: string;
}

interface TestResult {
    ok: boolean;
    reason: string;
    detail: string | null;
    server: string | null;
    writable: boolean;
    empty: boolean;
}

type StepKey = 'welcome' | 'requirements' | 'database' | 'application' | 'administrator' | 'mail' | 'finish';

/**
 * The setup wizard.
 *
 * Every step lives in this one page and nothing is sent until the last one.
 * That is not a shortcut: a fresh instance has no configured session store to
 * keep half-finished answers in, and database credentials are the last thing
 * that should be parked in a session on a half-configured server. They stay in
 * this component until the single request that uses them.
 *
 * Which also means closing the tab costs nothing but the typing.
 */
export default function Wizard({
    requirements,
    database,
    defaults,
    timezones,
    locales,
    version,
}: {
    requirements: { ok: boolean; required: Requirement[]; recommended: Requirement[] };
    database: { bundledAvailable: boolean; defaults: Record<string, string> };
    defaults: { url: string; timezone: string; locale: string; name: string };
    timezones: Timezone[];
    locales: { code: string; native: string }[];
    version: string;
}) {
    const { t } = useTranslations();

    const form = useForm({
        database_choice: database.bundledAvailable ? 'bundled' : 'external',
        host: database.defaults.host ?? '127.0.0.1',
        port: database.defaults.port ?? '3306',
        database: database.defaults.database ?? 'ticktz',
        username: database.defaults.username ?? 'ticktz',
        password: '',
        app: {
            name: defaults.name || 'Ticktz',
            url: defaults.url,
            locale: defaults.locale,
            timezone: defaults.timezone,
            ticket_prefix: 'SUP',
        },
        admin: { name: '', email: '', password: '' },
        mail: { enabled: false, host: '', port: '587', username: '', password: '', scheme: 'tls', from_address: '' },
    });

    const [step, setStep] = useState(0);
    const [furthest, setFurthest] = useState(0);
    const [confirmPassword, setConfirmPassword] = useState('');
    const [test, setTest] = useState<TestResult | null>(null);
    const [testing, setTesting] = useState(false);

    const steps: (WizardStep & { key: StepKey })[] = [
        { key: 'welcome', label: t('install.steps.welcome') },
        { key: 'requirements', label: t('install.steps.requirements') },
        { key: 'database', label: t('install.steps.database') },
        { key: 'application', label: t('install.steps.application') },
        { key: 'administrator', label: t('install.steps.administrator') },
        { key: 'mail', label: t('install.steps.mail') },
        { key: 'finish', label: t('install.steps.finish') },
    ];

    const current = steps[step].key;

    const groupedTimezones = useMemo(() => {
        const groups = new Map<string, Timezone[]>();
        for (const zone of timezones) {
            const list = groups.get(zone.group) ?? [];
            list.push(zone);
            groups.set(zone.group, list);
        }
        return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
    }, [timezones]);

    const passwordsMatch = form.data.admin.password === confirmPassword;
    const installError = (form.errors as Record<string, string | undefined>).install;

    /**
     * Whether the step in view is answered well enough to move on. Kept as one
     * expression per step so the rule is visible next to the step it guards,
     * rather than spread across the handlers that read it.
     */
    const canContinue = (): boolean => {
        switch (current) {
            case 'requirements':
                return requirements.ok;
            case 'database':
                // A connection that was tested and worked. Letting somebody
                // past on untested credentials just moves the failure to the
                // end, after the point where it is cheap to fix.
                return test?.ok === true && test.writable;
            case 'application':
                return form.data.app.name.trim() !== '' && form.data.app.url.trim() !== '';
            case 'administrator':
                return (
                    form.data.admin.name.trim() !== '' &&
                    form.data.admin.email.trim() !== '' &&
                    form.data.admin.password.length >= 12 &&
                    passwordsMatch
                );
            case 'mail':
                return (
                    !form.data.mail.enabled ||
                    (form.data.mail.host.trim() !== '' && form.data.mail.from_address.trim() !== '')
                );
            default:
                return true;
        }
    };

    const goTo = (index: number) => {
        setStep(index);
        setFurthest((f) => Math.max(f, index));
    };

    const next = () => canContinue() && goTo(Math.min(step + 1, steps.length - 1));
    const back = () => setStep((s) => Math.max(s - 1, 0));

    const runTest = async () => {
        setTesting(true);
        setTest(null);

        try {
            const response = await fetch(route('install.database'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                // The bundled database sends nothing but the choice. Its host,
                // name, user and password are the compose file's, already in
                // the server's environment — and the password was never sent
                // to this page, so there is nothing here to send back.
                body: JSON.stringify(
                    form.data.database_choice === 'bundled'
                        ? { database_choice: 'bundled' }
                        : {
                              database_choice: 'external',
                              host: form.data.host,
                              port: form.data.port,
                              database: form.data.database,
                              username: form.data.username,
                              password: form.data.password,
                          },
                ),
            });

            setTest(
                response.ok
                    ? ((await response.json()) as TestResult)
                    : { ok: false, reason: 'failed', detail: null, server: null, writable: false, empty: false },
            );
        } catch {
            setTest({ ok: false, reason: 'unreachable', detail: null, server: null, writable: false, empty: false });
        } finally {
            setTesting(false);
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(route('install.store'), { preserveScroll: true });
    };

    /** Changing the database answers invalidates a test taken against the old ones. */
    const setDb = (key: 'host' | 'port' | 'database' | 'username' | 'password', value: string) => {
        form.setData(key, value);
        setTest(null);
    };

    return (
        <>
            <Head title={t('install.title')} />

            <div className="min-h-screen bg-slate-100">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <BrandLockup subtitle={t('install.version', { version })} />
                    <LocaleSwitcher />
                </header>

                <main id="main" className="mx-auto w-full max-w-5xl px-6 pb-16">
                    <h1 className="text-xl font-semibold tracking-tight text-slate-900">{t('install.title')}</h1>
                    <p className="mt-1 text-sm text-slate-600">{t('install.subtitle')}</p>

                    <div className="mt-6 flex flex-col gap-6 lg:flex-row">
                        <nav className="lg:w-56 lg:shrink-0" aria-label={t('install.title')}>
                            <Stepper steps={steps} current={step} furthest={furthest} onSelect={goTo} />
                        </nav>

                        <form onSubmit={submit} className="min-w-0 flex-1">
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-card">
                                {current === 'welcome' && <WelcomeStep form={form} locales={locales} />}

                                {current === 'requirements' && (
                                    <RequirementsStep requirements={requirements} />
                                )}

                                {current === 'database' && (
                                    <DatabaseStep
                                        form={form}
                                        available={database.bundledAvailable}
                                        bundled={database.defaults}
                                        test={test}
                                        testing={testing}
                                        onTest={runTest}
                                        onChange={setDb}
                                        onChoice={(choice) => {
                                            form.setData('database_choice', choice);
                                            setTest(null);
                                        }}
                                    />
                                )}

                                {current === 'application' && (
                                    <ApplicationStep form={form} locales={locales} groups={groupedTimezones} />
                                )}

                                {current === 'administrator' && (
                                    <AdministratorStep
                                        form={form}
                                        confirm={confirmPassword}
                                        onConfirm={setConfirmPassword}
                                        matches={passwordsMatch}
                                    />
                                )}

                                {current === 'mail' && <MailStep form={form} />}

                                {current === 'finish' && <FinishStep form={form} bundled={database.defaults} />}
                            </div>

                            {/* `install` is not a form field, so it is not in
                                the inferred error shape — the server adds it for
                                a failure that belongs to no single input. */}
                            {installError ? (
                                <p role="alert" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                                    {installError}
                                </p>
                            ) : null}

                            <div className="mt-4 flex items-center justify-between gap-3">
                                <Button type="button" variant="secondary" onClick={back} disabled={step === 0}>
                                    {t('install.actions.back')}
                                </Button>

                                {current === 'finish' ? (
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing
                                            ? t('install.actions.installing')
                                            : t('install.actions.install')}
                                    </Button>
                                ) : (
                                    <Button type="button" onClick={next} disabled={!canContinue()}>
                                        {t('install.actions.next')}
                                    </Button>
                                )}
                            </div>
                        </form>
                    </div>
                </main>
            </div>
        </>
    );
}

/*
|--------------------------------------------------------------------------
| Steps
|--------------------------------------------------------------------------
|
| Each one takes the form object rather than individual props. They are all
| children of a single form and none of them is reused anywhere else, so
| threading twenty setters through would be ceremony without a payoff.
|
*/

/* eslint-disable @typescript-eslint/no-explicit-any */
type WizardForm = any;

function StepHeading({ title, body }: { title: string; body: string }) {
    return (
        <header className="mb-5">
            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            <p className="mt-1 text-sm text-slate-600">{body}</p>
        </header>
    );
}

function WelcomeStep({ form, locales }: { form: WizardForm; locales: { code: string; native: string }[] }) {
    const { t } = useTranslations();

    return (
        <>
            <StepHeading title={t('install.welcome.heading')} body={t('install.welcome.body')} />

            <p className="text-sm font-medium text-slate-700">{t('install.welcome.what')}</p>
            <ul className="mt-2 space-y-1.5">
                {(['check', 'database', 'admin', 'mail'] as const).map((key) => (
                    <li key={key} className="flex items-start gap-2 text-sm text-slate-600">
                        <IconCheckCircle className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true" />
                        {t(`install.welcome.list.${key}`)}
                    </li>
                ))}
            </ul>

            <div className="mt-6 max-w-xs">
                <Field label={t('install.welcome.language')} help={t('install.welcome.language_help')}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.app.locale}
                            onChange={(event) => form.setData('app', { ...form.data.app, locale: event.target.value })}
                        >
                            {locales.map((locale) => (
                                <option key={locale.code} value={locale.code}>
                                    {locale.native}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>

            <p className="mt-6 text-xs text-slate-600">{t('install.welcome.time')}</p>
        </>
    );
}

function RequirementsStep({
    requirements,
}: {
    requirements: { ok: boolean; required: Requirement[]; recommended: Requirement[] };
}) {
    const { t } = useTranslations();

    return (
        <>
            <StepHeading title={t('install.requirements.heading')} body={t('install.requirements.body')} />

            <div
                className={cn(
                    'mb-5 flex items-start gap-2 rounded-lg px-3 py-2 text-sm',
                    requirements.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800',
                )}
                role={requirements.ok ? undefined : 'alert'}
            >
                {requirements.ok ? (
                    <IconCheckCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                ) : (
                    <IconAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                )}
                {requirements.ok ? t('install.requirements.ok') : t('install.requirements.failed')}
            </div>

            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                {t('install.requirements.required')}
            </h3>
            <RequirementList items={requirements.required} />

            <h3 className="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                {t('install.requirements.recommended')}
            </h3>
            <p className="mb-2 text-xs text-slate-600">{t('install.requirements.recommended_help')}</p>
            <RequirementList items={requirements.recommended} tone="recommended" />

            {!requirements.ok ? (
                <Button
                    type="button"
                    variant="secondary"
                    className="mt-4"
                    onClick={() => window.location.reload()}
                >
                    {t('install.actions.retry')}
                </Button>
            ) : null}
        </>
    );
}

function DatabaseStep({
    form,
    available,
    bundled,
    test,
    testing,
    onTest,
    onChange,
    onChoice,
}: {
    form: WizardForm;
    available: boolean;
    // The bundled database as the server described it, which is not the same
    // thing as what is in the form: editing the external fields and switching
    // back would otherwise have this summary name a database that is about to
    // be ignored.
    bundled: Record<string, string>;
    test: TestResult | null;
    testing: boolean;
    onTest: () => void;
    onChange: (key: 'host' | 'port' | 'database' | 'username' | 'password', value: string) => void;
    onChoice: (choice: 'bundled' | 'external') => void;
}) {
    const { t } = useTranslations();
    const choice = form.data.database_choice as 'bundled' | 'external';

    return (
        <>
            <StepHeading title={t('install.database.heading')} body={t('install.database.body')} />

            <div className="grid gap-3 sm:grid-cols-2">
                <ChoiceCard
                    selected={choice === 'bundled'}
                    disabled={!available}
                    title={t('install.database.bundled')}
                    body={available ? t('install.database.bundled_help') : t('install.database.bundled_unavailable')}
                    onSelect={() => onChoice('bundled')}
                />
                <ChoiceCard
                    selected={choice === 'external'}
                    title={t('install.database.external')}
                    body={t('install.database.external_help')}
                    onSelect={() => onChoice('external')}
                />
            </div>

            {choice === 'bundled' ? (
                // Nothing to fill in. Every value belongs to the container the
                // compose file started, and the one that authenticates was
                // never sent to this page. Shown as a sentence rather than as
                // five disabled boxes: a field you cannot edit still reads as
                // a field you were supposed to check.
                <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    <p>
                        {t('install.database.bundled_summary', {
                            database: bundled.database,
                            host: bundled.host,
                            username: bundled.username,
                        })}
                    </p>
                    <p className="mt-2 text-xs text-slate-600">{t('install.database.bundled_password')}</p>
                </div>
            ) : (
                <>
                    <p className="mt-4 text-xs text-slate-600">{t('install.database.prepare')}</p>

                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Field label={t('install.database.fields.host')} error={form.errors.host}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.host}
                                    onChange={(event) => onChange('host', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label={t('install.database.fields.port')} error={form.errors.port}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.port}
                                    onChange={(event) => onChange('port', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label={t('install.database.fields.database')} error={form.errors.database}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.database}
                                    onChange={(event) => onChange('database', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label={t('install.database.fields.username')} error={form.errors.username}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.username}
                                    onChange={(event) => onChange('username', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('install.database.fields.password')}
                            error={form.errors.password}
                            className="sm:col-span-2"
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(event) => onChange('password', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>
                </>
            )}

            <div className="mt-4 flex items-center gap-3">
                <Button type="button" variant="secondary" onClick={onTest} disabled={testing}>
                    {testing ? t('install.actions.testing') : t('install.actions.test')}
                </Button>
            </div>

            {test ? <TestOutcome test={test} /> : null}
        </>
    );
}

/** The result of a connection test, in words rather than a driver message. */
function TestOutcome({ test }: { test: TestResult }) {
    const { t } = useTranslations();

    if (!test.ok) {
        return (
            <p role="alert" className="mt-3 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                <IconAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>
                    {t(`install.database.errors.${test.reason}`)}
                    {test.detail ? <span className="mt-1 block font-mono text-xs opacity-80">{test.detail}</span> : null}
                </span>
            </p>
        );
    }

    return (
        <div className="mt-3 space-y-1.5">
            <p className="flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                <IconCheckCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                {t('install.database.result.connected', { server: test.server ?? '' })}
            </p>

            <p
                className={cn(
                    'flex items-start gap-2 rounded-lg px-3 py-2 text-sm',
                    test.writable ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800',
                )}
                role={test.writable ? undefined : 'alert'}
            >
                {test.writable ? (
                    <IconCheckCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                ) : (
                    <IconAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                )}
                {test.writable ? t('install.database.result.writable') : t('install.database.result.not_writable')}
            </p>

            {!test.empty ? (
                <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <IconAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    {t('install.database.result.not_empty')}
                </p>
            ) : null}
        </div>
    );
}

function ChoiceCard({
    selected,
    disabled = false,
    title,
    body,
    onSelect,
}: {
    selected: boolean;
    disabled?: boolean;
    title: string;
    body: string;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'flex h-full flex-col items-start gap-1.5 rounded-xl border p-4 text-left transition-colors',
                selected && !disabled && 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500',
                !selected && !disabled && 'border-slate-200 bg-white hover:border-slate-300',
                disabled && 'cursor-not-allowed border-slate-200 bg-slate-50',
            )}
        >
            <span className="flex items-center gap-2">
                <IconServer
                    className={cn('h-4 w-4', selected && !disabled ? 'text-brand-600' : 'text-slate-500')}
                    aria-hidden="true"
                />
                <span className={cn('text-sm font-medium', disabled ? 'text-slate-600' : 'text-slate-900')}>
                    {title}
                </span>
            </span>
            <span className="text-xs text-slate-600">{body}</span>
        </button>
    );
}

function ApplicationStep({
    form,
    locales,
    groups,
}: {
    form: WizardForm;
    locales: { code: string; native: string }[];
    groups: [string, Timezone[]][];
}) {
    const { t } = useTranslations();
    const set = (key: string, value: string) => form.setData('app', { ...form.data.app, [key]: value });

    return (
        <>
            <StepHeading title={t('install.application.heading')} body={t('install.application.body')} />

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={t('install.application.name')}
                    help={t('install.application.name_help')}
                    error={form.errors['app.name']}
                >
                    {(props) => (
                        <TextInput {...props} value={form.data.app.name} onChange={(e) => set('name', e.target.value)} />
                    )}
                </Field>

                <Field
                    label={t('install.application.prefix')}
                    help={t('install.application.prefix_help')}
                    error={form.errors['app.ticket_prefix']}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.app.ticket_prefix}
                            onChange={(e) => set('ticket_prefix', e.target.value.toUpperCase())}
                        />
                    )}
                </Field>

                <Field
                    label={t('install.application.url')}
                    help={t('install.application.url_help')}
                    error={form.errors['app.url']}
                    className="sm:col-span-2"
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            type="url"
                            value={form.data.app.url}
                            onChange={(e) => set('url', e.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label={t('install.application.timezone')}
                    help={t('install.application.timezone_help')}
                    error={form.errors['app.timezone']}
                >
                    {(props) => (
                        <Select {...props} value={form.data.app.timezone} onChange={(e) => set('timezone', e.target.value)}>
                            {groups.map(([group, zones]) => (
                                <optgroup key={group} label={group}>
                                    {zones.map((zone) => (
                                        <option key={zone.value} value={zone.value}>
                                            {zone.label}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('install.application.locale')} error={form.errors['app.locale']}>
                    {(props) => (
                        <Select {...props} value={form.data.app.locale} onChange={(e) => set('locale', e.target.value)}>
                            {locales.map((locale) => (
                                <option key={locale.code} value={locale.code}>
                                    {locale.native}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>
        </>
    );
}

function AdministratorStep({
    form,
    confirm,
    onConfirm,
    matches,
}: {
    form: WizardForm;
    confirm: string;
    onConfirm: (value: string) => void;
    matches: boolean;
}) {
    const { t } = useTranslations();
    const set = (key: string, value: string) => form.setData('admin', { ...form.data.admin, [key]: value });

    return (
        <>
            <StepHeading title={t('install.administrator.heading')} body={t('install.administrator.body')} />

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('install.administrator.name')} error={form.errors['admin.name']}>
                    {(props) => (
                        <TextInput
                            {...props}
                            autoComplete="name"
                            value={form.data.admin.name}
                            onChange={(e) => set('name', e.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('install.administrator.email')} error={form.errors['admin.email']}>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="email"
                            autoComplete="username"
                            value={form.data.admin.email}
                            onChange={(e) => set('email', e.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label={t('install.administrator.password')}
                    help={t('install.administrator.password_help')}
                    error={form.errors['admin.password']}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            autoComplete="new-password"
                            value={form.data.admin.password}
                            onChange={(e) => set('password', e.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label={t('install.administrator.confirm')}
                    error={confirm !== '' && !matches ? t('install.administrator.mismatch') : undefined}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            autoComplete="new-password"
                            value={confirm}
                            onChange={(e) => onConfirm(e.target.value)}
                        />
                    )}
                </Field>
            </div>
        </>
    );
}

function MailStep({ form }: { form: WizardForm }) {
    const { t } = useTranslations();
    const set = (key: string, value: string | boolean) => form.setData('mail', { ...form.data.mail, [key]: value });

    return (
        <>
            <StepHeading title={t('install.mail.heading')} body={t('install.mail.body')} />

            <Toggle
                checked={form.data.mail.enabled}
                onChange={(value) => set('enabled', value)}
                label={t('install.mail.enable')}
            />

            {!form.data.mail.enabled ? (
                <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    {t('install.mail.later')}
                </p>
            ) : (
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <Field label={t('install.mail.host')} error={form.errors['mail.host']}>
                        {(props) => (
                            <TextInput {...props} value={form.data.mail.host} onChange={(e) => set('host', e.target.value)} />
                        )}
                    </Field>

                    <Field label={t('install.mail.port')} error={form.errors['mail.port']}>
                        {(props) => (
                            <TextInput {...props} value={form.data.mail.port} onChange={(e) => set('port', e.target.value)} />
                        )}
                    </Field>

                    <Field label={t('install.mail.username')}>
                        {(props) => (
                            <TextInput
                                {...props}
                                autoComplete="off"
                                value={form.data.mail.username}
                                onChange={(e) => set('username', e.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('install.mail.password')}>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="password"
                                autoComplete="new-password"
                                value={form.data.mail.password}
                                onChange={(e) => set('password', e.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('install.mail.scheme')}>
                        {(props) => (
                            <Select {...props} value={form.data.mail.scheme} onChange={(e) => set('scheme', e.target.value)}>
                                <option value="tls">STARTTLS</option>
                                <option value="ssl">SSL/TLS</option>
                                <option value="">{t('install.mail.scheme_none')}</option>
                            </Select>
                        )}
                    </Field>

                    <Field
                        label={t('install.mail.from')}
                        help={t('install.mail.from_help')}
                        error={form.errors['mail.from_address']}
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                type="email"
                                value={form.data.mail.from_address}
                                onChange={(e) => set('from_address', e.target.value)}
                            />
                        )}
                    </Field>
                </div>
            )}
        </>
    );
}

function FinishStep({ form, bundled }: { form: WizardForm; bundled: Record<string, string> }) {
    const { t } = useTranslations();
    const isBundled = form.data.database_choice === 'bundled';

    // Read from the server's values for the bundled database, for the same
    // reason the step above does: the form still holds whatever was typed into
    // the external fields, and none of it is going to be used.
    const db = isBundled ? bundled : form.data;

    const rows: [string, string][] = [
        [
            t('install.finish.database'),
            `${isBundled ? t('install.finish.bundled') : t('install.finish.external')} — ${db.database}@${db.host}:${db.port}`,
        ],
        [t('install.finish.application'), `${form.data.app.name} — ${form.data.app.url}`],
        [t('install.finish.administrator'), `${form.data.admin.name} <${form.data.admin.email}>`],
        [
            t('install.finish.mail'),
            form.data.mail.enabled
                ? `${form.data.mail.from_address} via ${form.data.mail.host}:${form.data.mail.port}`
                : t('install.finish.mail_off'),
        ],
    ];

    return (
        <>
            <StepHeading title={t('install.finish.heading')} body={t('install.finish.body')} />

            <dl className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex flex-col gap-0.5 px-3 py-2.5 sm:flex-row sm:gap-4">
                        <dt className="text-xs font-medium uppercase tracking-wide text-slate-600 sm:w-40 sm:shrink-0">
                            {label}
                        </dt>
                        <dd className="min-w-0 break-words text-sm text-slate-900">{value}</dd>
                    </div>
                ))}
            </dl>

            <p className="mt-4 text-xs text-slate-600">{t('install.finish.warning')}</p>
        </>
    );
}
