import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function ConfirmPassword() {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title={t('auth.confirm.title')} description={t('auth.confirm.subtitle')}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('auth.login.password')} error={errors.password} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            name="password"
                            value={data.password}
                            autoComplete="current-password"
                            autoFocus
                            required
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    {t('auth.confirm.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
