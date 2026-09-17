import { describe, expect, it } from 'vitest';

import { cn } from '@/lib/cn';

describe('cn', () => {
    it('joins truthy values', () => {
        expect(cn('a', false, 'b', undefined, 'c')).toBe('a b c');
    });

    it('supports object and array syntax', () => {
        expect(cn(['a', { b: true, c: false }], { d: true })).toBe('a b d');
    });

    it('returns an empty string when nothing applies', () => {
        expect(cn(false, null, undefined)).toBe('');
    });
});
