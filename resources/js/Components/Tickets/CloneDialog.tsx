import { useForm } from '@inertiajs/react';

import { Button, Field, HelpText, Modal, Select, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { TicketDetail, TicketOptions } from '@/types/tickets';

/**
 * Filing the same request again.
 *
 * Two fields, both optional, and the order is the order people think in: what
 * makes this one different, and then — having realised it is not quite the same
 * request — where it actually belongs.
 *
 * The help text says what does not come across, because that is the part
 * somebody discovers too late otherwise. A clone that silently left the
 * attachments behind reads as a bug the first time; said out loud before the
 * button is pressed, it reads as a decision.
 */
export function CloneDialog({
    ticket,
    options,
    onClose,
}: {
    ticket: TicketDetail;
    options: TicketOptions;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        subject: ticket.subject,
        request_type_id: '',
    });

    // Only the ticket page carries these, so the type is optional; narrowing
    // once here keeps the markup below from repeating the check.
    const requestTypes = options.request_types ?? [];

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={t('tickets.actions.clone_title')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={() => form.post(`/agent/tickets/${ticket.key}/clone`)}>
                        {t('tickets.actions.clone_submit')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('tickets.fields.subject')} error={form.errors.subject}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.subject}
                            autoFocus
                            onChange={(event) => form.setData('subject', event.target.value)}
                        />
                    )}
                </Field>

                {requestTypes.length > 0 ? (
                    <Field label={t('tickets.actions.clone_type')} error={form.errors.request_type_id}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.request_type_id}
                                onChange={(event) => form.setData('request_type_id', event.target.value)}
                            >
                                <option value="">{t('tickets.actions.clone_same_type')}</option>
                                {requestTypes.map((type) => (
                                    <option key={type.id} value={type.id}>
                                        {type.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                ) : null}

                <HelpText>{t('tickets.actions.clone_help')}</HelpText>
            </div>
        </Modal>
    );
}
