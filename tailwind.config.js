import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Ticktz design tokens.
 *
 * The brand palette is driven by CSS custom properties (see resources/css/app.css)
 * so a self-hoster can re-brand the portal from the admin UI without rebuilding
 * the bundle. Tailwind classes read those variables through `rgb(var(--...))`.
 */

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
        './resources/js/**/*.ts',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: [
                    'Inter var',
                    'Inter',
                    '-apple-system',
                    'BlinkMacSystemFont',
                    'Segoe UI',
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                brand: {
                    50: 'rgb(var(--brand-50) / <alpha-value>)',
                    100: 'rgb(var(--brand-100) / <alpha-value>)',
                    200: 'rgb(var(--brand-200) / <alpha-value>)',
                    300: 'rgb(var(--brand-300) / <alpha-value>)',
                    400: 'rgb(var(--brand-400) / <alpha-value>)',
                    500: 'rgb(var(--brand-500) / <alpha-value>)',
                    600: 'rgb(var(--brand-600) / <alpha-value>)',
                    700: 'rgb(var(--brand-700) / <alpha-value>)',
                    800: 'rgb(var(--brand-800) / <alpha-value>)',
                    900: 'rgb(var(--brand-900) / <alpha-value>)',
                    950: 'rgb(var(--brand-950) / <alpha-value>)',
                },
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                popover: '0 10px 30px -12px rgb(15 23 42 / 0.25)',
            },
            keyframes: {
                'fade-in': {
                    from: { opacity: '0', transform: 'translateY(2px)' },
                    to: { opacity: '1', transform: 'none' },
                },
            },
            animation: {
                'fade-in': 'fade-in 150ms ease-out',
            },
        },
    },

    plugins: [forms],
};
