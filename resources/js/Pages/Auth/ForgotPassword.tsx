import { Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout
            title={t('auth.forgot.title')}
            description={t('auth.forgot.subtitle')}
            footer={
                <Link href={route('login')} className="font-medium text-brand-600 hover:text-brand-700">
                    {t('auth.forgot.back_to_login')}
                </Link>
            }
        >
            {status ? (
                <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">{status}</p>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('auth.register.email')} error={errors.email} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="email"
                            name="email"
                            value={data.email}
                            autoComplete="username"
                            autoFocus
                            required
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    {t('auth.forgot.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
