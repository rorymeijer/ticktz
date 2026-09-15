import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    Field,
    PageHeader,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

type SettingsPayload = Record<string, string | number | boolean | null>;

export default function SettingsIndex({ settings }: { settings: SettingsPayload }) {
    const { t } = useTranslations();

    const form = useForm({
        'allow_self_registration': Boolean(settings['allow_self_registration']),
        'require_email_verification': Boolean(settings['require_email_verification']),
        'portal_title': String(settings['portal_title'] ?? ''),
        'welcome_message': String(settings['welcome_message'] ?? ''),
        'primary_color': String(settings['primary_color'] ?? '#4f46e5'),
        'support_email': String(settings['support_email'] ?? ''),
        'key_prefix': String(settings['key_prefix'] ?? 'SUP'),
        'reopen_window_days': Number(settings['reopen_window_days'] ?? 14),
        'auto_close_days': Number(settings['auto_close_days'] ?? 7),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put('/admin/settings', { preserveScroll: true });
    };

    return (
        <AdminLayout title={t('admin.settings.title')}>
            <PageHeader title={t('admin.settings.title')} description={t('admin.settings.subtitle')} />

            <form onSubmit={submit} className="max-w-3xl space-y-5">
                <Card>
                    <CardHeader title={t('admin.settings.groups.branding')} />
                    <CardBody className="space-y-4">
                        <Field label={t('admin.settings.fields.portal_title')} error={form.errors['portal_title']} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data['portal_title']}
                                    required
                                    onChange={(event) => form.setData('portal_title', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.settings.fields.welcome_message')}
                            error={form.errors['welcome_message']}
                        >
                            {(props) => (
                                <Textarea
                                    {...props}
                                    rows={3}
                                    value={form.data['welcome_message']}
                                    onChange={(event) => form.setData('welcome_message', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.settings.fields.primary_color')}
                            error={form.errors['primary_color']}
                            required
                        >
                            {(props) => (
                                <div className="flex items-center gap-2">
                                    <input
                                        type="color"
                                        aria-label={t('admin.settings.fields.primary_color')}
                                        value={form.data['primary_color']}
                                        onChange={(event) => form.setData('primary_color', event.target.value)}
                                        className="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-white p-1"
                                    />
                                    <TextInput
                                        {...props}
                                        value={form.data['primary_color']}
                                        required
                                        className="font-mono"
                                        onChange={(event) => form.setData('primary_color', event.target.value)}
                                    />
                                </div>
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('admin.settings.groups.portal')} />
                    <CardBody className="space-y-4">
                        <Toggle
                            checked={form.data['allow_self_registration']}
                            onChange={(value) => form.setData('allow_self_registration', value)}
                            label={t('admin.settings.fields.allow_self_registration')}
                            description={t('admin.settings.fields.allow_self_registration_help')}
                        />

                        <Toggle
                            checked={form.data['require_email_verification']}
                            onChange={(value) => form.setData('require_email_verification', value)}
                            label={t('admin.settings.fields.require_email_verification')}
                        />

                        <Field
                            label={t('admin.settings.fields.support_email')}
                            error={form.errors['support_email']}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="email"
                                    value={form.data['support_email']}
                                    onChange={(event) => form.setData('support_email', event.target.value)}
                                />
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('admin.settings.groups.tickets')} />
                    <CardBody className="space-y-4">
                        <Field
                            label={t('admin.settings.fields.key_prefix')}
                            help={t('admin.settings.fields.key_prefix_help')}
                            error={form.errors['key_prefix']}
                            required
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data['key_prefix']}
                                    required
                                    className="max-w-32 font-mono uppercase"
                                    onChange={(event) =>
                                        form.setData('key_prefix', event.target.value.toUpperCase())
                                    }
                                />
                            )}
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('admin.settings.fields.reopen_window_days')}
                                help={t('admin.settings.fields.reopen_window_help')}
                                error={form.errors['reopen_window_days']}
                                required
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="number"
                                        min={0}
                                        max={365}
                                        value={form.data['reopen_window_days']}
                                        required
                                        onChange={(event) =>
                                            form.setData('reopen_window_days', Number(event.target.value))
                                        }
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('admin.settings.fields.auto_close_days')}
                                help={t('admin.settings.fields.auto_close_help')}
                                error={form.errors['auto_close_days']}
                                required
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="number"
                                        min={0}
                                        max={365}
                                        value={form.data['auto_close_days']}
                                        required
                                        onChange={(event) =>
                                            form.setData(
                                                'auto_close_days',
                                                Number(event.target.value),
                                            )
                                        }
                                    />
                                )}
                            </Field>
                        </div>
                    </CardBody>
                    <CardFooter>
                        {form.recentlySuccessful ? (
                            <span className="mr-auto text-xs font-medium text-emerald-600">
                                {t('admin.settings.saved')}
                            </span>
                        ) : null}
                        <Button type="submit" disabled={form.processing}>
                            {t('common.actions.save')}
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
