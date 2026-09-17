import { cn } from '@/lib/cn';

export function BrandMark({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 32 32" className={cn('h-8 w-8', className)} role="img" aria-label="Ticktz">
            <rect width="32" height="32" rx="8" className="fill-brand-600" />
            <path
                d="M9 16.8l4.2 4.2L23 11.2"
                fill="none"
                stroke="white"
                strokeWidth="3.2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function BrandLockup({
    name = 'Ticktz',
    logoUrl,
    className,
    subtitle,
}: {
    name?: string;
    logoUrl?: string | null;
    className?: string;
    subtitle?: string;
}) {
    return (
        <span className={cn('flex items-center gap-2.5', className)}>
            {logoUrl ? (
                <img src={logoUrl} alt="" className="h-8 w-8 rounded-lg object-contain" />
            ) : (
                <BrandMark />
            )}
            <span className="flex flex-col leading-tight">
                <span className="text-sm font-semibold tracking-tight text-slate-900">{name}</span>
                {subtitle ? <span className="text-[11px] text-slate-600">{subtitle}</span> : null}
            </span>
        </span>
    );
}
