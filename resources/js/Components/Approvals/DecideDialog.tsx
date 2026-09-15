import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button, Field, Modal, Textarea } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { DecisionOutcome } from '@/types/approvals';

/**
 * Approve or reject, with room to say why.
 *
 * The comment is optional on a yes and strongly encouraged on a no — the
 * requester reads it, and "not approved" with no reason attached generates a
 * second ticket asking why.
 */
export function DecideDialog({
    decisionId,
    outcome,
    subject,
    onClose,
}: {
    decisionId: number;
    outcome: Exclude<DecisionOutcome, 'pending' | 'skipped'>;
    subject: string;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({ decision: outcome, comment: '' });

    return (
        <Modal
            open
            onClose={onClose}
            title={t(`approvals.actions.${outcome === 'approved' ? 'approve' : 'reject'}`)}
            description={subject}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        variant={outcome === 'approved' ? 'primary' : 'danger'}
                        disabled={form.processing}
                        onClick={() =>
                            form.post(`/approvals/decisions/${decisionId}`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {t(`approvals.actions.${outcome === 'approved' ? 'approve' : 'reject'}`)}
                    </Button>
                </>
            }
        >
            <Field
                label={t('approvals.fields.comment')}
                error={form.errors.comment}
                help={t('approvals.fields.comment_help')}
            >
                {(props) => (
                    <Textarea
                        {...props}
                        rows={3}
                        autoFocus
                        maxLength={2000}
                        value={form.data.comment}
                        onChange={(event) => form.setData('comment', event.target.value)}
                    />
                )}
            </Field>
        </Modal>
    );
}

/**
 * The pair of buttons, and the dialog they open. Rendered only when the server
 * said this reader has a decision to answer — the browser never works that out
 * for itself.
 */
export function DecideButtons({
    decisionId,
    subject,
    size = 'sm',
}: {
    decisionId: number;
    subject: string;
    size?: 'sm' | 'md';
}) {
    const { t } = useTranslations();
    const [pending, setPending] = useState<'approved' | 'rejected' | null>(null);

    return (
        <>
            <div className="flex flex-wrap items-center gap-1.5">
                <Button size={size} onClick={() => setPending('approved')}>
                    {t('approvals.actions.approve')}
                </Button>
                <Button size={size} variant="secondary" onClick={() => setPending('rejected')}>
                    {t('approvals.actions.reject')}
                </Button>
            </div>

            {pending ? (
                <DecideDialog
                    decisionId={decisionId}
                    outcome={pending}
                    subject={subject}
                    onClose={() => setPending(null)}
                />
            ) : null}
        </>
    );
}
