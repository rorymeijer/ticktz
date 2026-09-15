import { Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Checkbox, Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function Login({
    status,
    canResetPassword,
    canRegister,
    directoryEnabled,
}: {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    directoryEnabled: boolean;
}) {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout
            title={t('auth.login.title')}
            description={t('auth.login.subtitle')}
            footer={
                canRegister ? (
                    <>
                        {t('auth.register.subtitle')}{' '}
                        <Link href={route('register')} className="font-medium text-brand-600 hover:text-brand-700">
                            {t('auth.register.title')}
                        </Link>
                    </>
                ) : null
            }
        >
            {status ? (
                <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">{status}</p>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('auth.login.email')} error={errors.email} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="text"
                            name="email"
                            value={data.email}
                            autoComplete="username"
                            autoFocus
                            required
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('auth.login.password')} error={errors.password} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            name="password"
                            value={data.password}
                            autoComplete="current-password"
                            required
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <div className="flex items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm text-slate-600">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                        />
                        {t('auth.login.remember')}
                    </label>

                    {canResetPassword ? (
                        <Link
                            href={route('password.request')}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700"
                        >
                            {t('auth.login.forgot')}
                        </Link>
                    ) : null}
                </div>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    {t('auth.login.submit')}
                </Button>

                {directoryEnabled ? (
                    <p className="text-center text-xs text-slate-500">{t('auth.login.directory_hint')}</p>
                ) : null}
            </form>
        </AuthLayout>
    );
}
