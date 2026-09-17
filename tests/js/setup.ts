import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Ziggy's global `route()` helper is injected by a Blade directive at runtime;
// in unit tests we stub it with something predictable.
declare global {
    // eslint-disable-next-line no-var
    var route: (name: string, params?: unknown) => string;
}

globalThis.route = ((name: string) => `/${name.replaceAll('.', '/')}`) as typeof globalThis.route;

vi.stubGlobal('matchMedia', (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
}));

afterEach(() => cleanup());
