import { IconAlert } from '@/Components/Icons';
import { Badge, type BadgeTone } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { AssetStatus } from '@/types/assets';

const tones: Record<AssetStatus, BadgeTone> = {
    in_stock: 'blue',
    in_use: 'green',
    in_repair: 'amber',
    retired: 'slate',
    disposed: 'slate',
};

export function AssetStatusBadge({ status }: { status: AssetStatus }) {
    const { t } = useTranslations();

    return <Badge tone={tones[status]}>{t(`assets.status.${status}`)}</Badge>;
}

export function WarrantyBadge({ expired }: { expired: boolean }) {
    const { t } = useTranslations();

    if (!expired) {
        return null;
    }

    return (
        <Badge tone="red">
            <IconAlert className="h-3 w-3" />
            {t('assets.warranty.expired')}
        </Badge>
    );
}

/**
 * The type, in its own colour. A CMDB of four hundred rows is scanned, not
 * read, and a colour is what makes "all the laptops" visible at a glance.
 */
export function AssetTypeBadge({ type }: { type: { name: string; color: string } | null }) {
    if (!type) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-slate-500">
            <span
                aria-hidden="true"
                className="h-2 w-2 shrink-0 rounded-full"
                style={{ backgroundColor: type.color }}
            />
            {type.name}
        </span>
    );
}
