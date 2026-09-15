import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { IconPlus, IconX } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    CheckboxGroup,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TextInput,
} from '@/Components/UI';
import AppLayout from '@/Layouts/AppLayout';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime, relativeTime } from '@/lib/datetime';

/*
|--------------------------------------------------------------------------
| Shapes
|--------------------------------------------------------------------------
*/

interface ScopeOption {
    key: string;
    label: string;
    permissions: string[];
}

interface Token {
    id: number;
    name: string;
    description: string | null;
    scopes: string[];
    rate_limit: number | null;
    last_used_at: string | null;
    expires_at: string | null;
    expired: boolean;
    created_at: string | null;
}

interface CreatedToken {
    id: number;
    name: string;
    plain: string;
}

/**
 * A person's own API tokens.
 *
 * The screen is built around the one moment that matters: the plaintext is
 * visible exactly once, immediately after creation. So it does not appear in a
 * row that scrolls away — it takes over the dialog, with a copy button and a
 * sentence saying plainly that this is the only time.
 */
export default function ApiTokens({
    tokens,
    scopes,
    defaultRateLimit,
    newToken,
}: {
    tokens: Token[];
    scopes: ScopeOption[];
    defaultRateLimit: number;
    newToken: CreatedToken | null;
}) {
    const { t, locale } = useTranslations();
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<Token | null>(null);
    // Seeded from the page prop so the plaintext survives the redirect that
    // follows creation, and is gone on the next navigation.
    const [created, setCreated] = useState<CreatedToken | null>(newToken);
    const [copied, setCopied] = useState(false);

    const form = useForm<{
        name: string;
        description: string;
        scopes: string[];
        rate_limit: string;
        expires_in_days: string;
    }>({
        name: '',
        description: '',
        scopes: [],
        rate_limit: '',
        expires_in_days: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(route('settings.tokens.store'), {
            preserveScroll: true,
            onSuccess: (page) => {
                form.reset();
                setCreating(false);
                setCopied(false);
                setCreated((page.props.newToken as CreatedToken | null) ?? null);
            },
        });
    };

    const copy = async (value: string) => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            // Clipboard access is denied outside a secure context. The token is
            // selectable in the box either way, so there is nothing to recover
            // from — just do not claim it was copied.
            setCopied(false);
        }
    };

    return (
        <AppLayout title={t('api.tokens.title')}>
            <PageHeader
                title={t('api.tokens.title')}
                description={t('api.tokens.subtitle')}
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <IconPlus className="h-4 w-4" />
                        {t('api.tokens.create')}
                    </Button>
                }
            />

            <Card>
                <CardBody className="p-0">
                    {tokens.length === 0 ? (
                        <EmptyState title={t('api.tokens.empty')} description={t('api.tokens.subtitle')} />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('api.tokens.name')}</TH>
                                    <TH>{t('api.tokens.scopes')}</TH>
                                    <TH>{t('api.tokens.last_used')}</TH>
                                    <TH>{t('api.tokens.expires')}</TH>
                                    <TH className="w-px" />
                                </TR>
                            </THead>
                            <TBody>
                                {tokens.map((token) => (
                                    <TR key={token.id}>
                                        <TD>
                                            <span className="font-medium text-slate-900">{token.name}</span>
                                            {token.description ? (
                                                <span className="block text-xs text-slate-500">
                                                    {token.description}
                                                </span>
                                            ) : null}
                                        </TD>
                                        <TD>
                                            {token.scopes.length === 0 ? (
                                                <span className="text-xs text-slate-500">
                                                    {t('api.tokens.scope_none')}
                                                </span>
                                            ) : (
                                                <span className="flex flex-wrap gap-1">
                                                    {token.scopes.map((scope) => (
                                                        <Badge key={scope} tone="slate">
                                                            {scope}
                                                        </Badge>
                                                    ))}
                                                </span>
                                            )}
                                        </TD>
                                        <TD className="text-sm text-slate-600">
                                            {token.last_used_at
                                                ? relativeTime(token.last_used_at, t)
                                                : t('api.tokens.never_used')}
                                        </TD>
                                        <TD className="text-sm text-slate-600">
                                            {token.expired ? (
                                                <Badge tone="red">{t('api.tokens.expired')}</Badge>
                                            ) : token.expires_at ? (
                                                formatDateTime(token.expires_at, locale)
                                            ) : (
                                                t('api.tokens.never')
                                            )}
                                        </TD>
                                        <TD>
                                            <Button variant="ghost" onClick={() => setRevoking(token)}>
                                                <IconX className="h-4 w-4" />
                                                <span className="sr-only">{t('api.tokens.revoke')}</span>
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardBody>
            </Card>

            {/* Creation */}
            <Modal open={creating} onClose={() => setCreating(false)} title={t('api.tokens.create')}>
                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('api.tokens.name')} help={t('api.tokens.name_help')} error={form.errors.name}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                autoFocus
                            />
                        )}
                    </Field>

                    <Field label={t('api.tokens.description')} error={form.errors.description}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label={t('api.tokens.scopes')}
                        help={t('api.tokens.scopes_help')}
                        error={form.errors.scopes ?? form.errors['scopes.0']}
                    >
                        {() => (
                            <CheckboxGroup
                                options={scopes.map((scope) => ({
                                    value: scope.key,
                                    label: scope.key,
                                    description: scope.label,
                                }))}
                                selected={form.data.scopes}
                                onChange={(values) => form.setData('scopes', values)}
                                emptyLabel={t('api.tokens.scope_none')}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('api.tokens.rate_limit')}
                            help={t('api.tokens.rate_limit_help', { default: String(defaultRateLimit) })}
                            error={form.errors.rate_limit}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="number"
                                    min={1}
                                    value={form.data.rate_limit}
                                    onChange={(event) => form.setData('rate_limit', event.target.value)}
                                    placeholder={String(defaultRateLimit)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('api.tokens.expires')}
                            help={t('api.tokens.expires_help')}
                            error={form.errors.expires_in_days}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="number"
                                    min={1}
                                    value={form.data.expires_in_days}
                                    onChange={(event) => form.setData('expires_in_days', event.target.value)}
                                    placeholder={t('api.tokens.never')}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCreating(false)}>
                            {t('common.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('api.tokens.create')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* The one showing of the plaintext. */}
            <Modal open={created !== null} onClose={() => setCreated(null)} title={t('api.tokens.created')}>
                <div className="space-y-4">
                    <p className="text-sm text-slate-600">{t('api.tokens.created_once')}</p>

                    <div className="flex items-center gap-2">
                        <code className="flex-1 select-all break-all rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-slate-100">
                            {created?.plain}
                        </code>
                        <Button type="button" variant="secondary" onClick={() => copy(created?.plain ?? '')}>
                            {copied ? t('api.tokens.copied') : t('api.tokens.copy')}
                        </Button>
                    </div>

                    <div className="flex justify-end">
                        <Button type="button" onClick={() => setCreated(null)}>
                            {t('common.actions.close')}
                        </Button>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={revoking !== null}
                onCancel={() => setRevoking(null)}
                title={t('api.tokens.revoke')}
                message={t('api.tokens.revoke_confirm')}
                confirmLabel={t('api.tokens.revoke')}
                onConfirm={() => {
                    if (revoking) {
                        form.delete(route('settings.tokens.destroy', revoking.id), {
                            preserveScroll: true,
                            onFinish: () => setRevoking(null),
                        });
                    }
                }}
            />
        </AppLayout>
    );
}
