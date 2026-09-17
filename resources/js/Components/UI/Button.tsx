import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ElementType, ReactNode } from 'react';
import { forwardRef } from 'react';

import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle';
type Size = 'sm' | 'md' | 'lg' | 'icon';

const base =
    'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition-colors ' +
    'disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 ' +
    'focus-visible:ring-brand-500 focus-visible:ring-offset-2';

const variants: Record<Variant, string> = {
    primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 active:bg-brand-800',
    secondary: 'border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 active:bg-slate-100',
    ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
    danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700 active:bg-red-800',
    subtle: 'bg-brand-50 text-brand-700 hover:bg-brand-100',
};

const sizes: Record<Size, string> = {
    sm: 'h-8 px-2.5 text-xs',
    md: 'h-9 px-3.5 text-sm',
    lg: 'h-11 px-5 text-sm',
    icon: 'h-9 w-9',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    icon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'primary', size = 'md', icon, className, children, type = 'button', ...props },
    ref,
) {
    return (
        <button ref={ref} type={type} className={cn(base, variants[variant], sizes[size], className)} {...props}>
            {icon}
            {children}
        </button>
    );
});

export function ButtonLink({
    href,
    variant = 'primary',
    size = 'md',
    icon,
    className,
    children,
    ...props
}: {
    href: string;
    variant?: Variant;
    size?: Size;
    icon?: ReactNode;
    className?: string;
    children?: ReactNode;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    as?: ElementType;
    preserveScroll?: boolean;
}) {
    return (
        <Link href={href} className={cn(base, variants[variant], sizes[size], className)} {...props}>
            {icon}
            {children}
        </Link>
    );
}
