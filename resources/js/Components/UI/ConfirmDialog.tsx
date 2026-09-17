import { Button } from './Button';
import { Modal } from './Modal';
import { useTranslations } from '@/hooks/useTranslations';

export function ConfirmDialog({
    open,
    onCancel,
    onConfirm,
    title,
    message,
    confirmLabel,
    destructive = true,
    processing = false,
}: {
    open: boolean;
    onCancel: () => void;
    onConfirm: () => void;
    title?: string;
    message: string;
    confirmLabel?: string;
    destructive?: boolean;
    processing?: boolean;
}) {
    const { t } = useTranslations();

    return (
        <Modal
            open={open}
            onClose={onCancel}
            size="sm"
            title={title ?? t('common.confirm.title')}
            footer={
                <>
                    <Button variant="secondary" onClick={onCancel} disabled={processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button variant={destructive ? 'danger' : 'primary'} onClick={onConfirm} disabled={processing}>
                        {confirmLabel ?? t('common.actions.confirm')}
                    </Button>
                </>
            }
        >
            <p className="text-sm text-slate-600">{message}</p>
        </Modal>
    );
}
