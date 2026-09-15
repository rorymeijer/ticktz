import { cn } from '@/lib/cn';

export function Avatar({
    name,
    initials,
    color,
    size = 'md',
    className,
}: {
    name: string;
    initials: string;
    color: string;
    size?: 'xs' | 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const sizes = {
        xs: 'h-6 w-6 text-[10px]',
        sm: 'h-7 w-7 text-[11px]',
        md: 'h-9 w-9 text-xs',
        lg: 'h-12 w-12 text-sm',
    };

    return (
        <span
            title={name}
            aria-hidden="true"
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white',
                sizes[size],
                className,
            )}
            style={{ backgroundColor: color }}
        >
            {initials}
        </span>
    );
}
