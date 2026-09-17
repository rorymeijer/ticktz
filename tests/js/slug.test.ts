import { describe, expect, it } from 'vitest';

import { slugify } from '@/lib/slug';

describe('slugify', () => {
    it('lower-cases and hyphenates', () => {
        expect(slugify('IT Servicedesk')).toBe('it-servicedesk');
    });

    it('strips diacritics so Dutch and French names stay readable', () => {
        expect(slugify('Gemeente Súdwest-Fryslân')).toBe('gemeente-sudwest-fryslan');
    });

    it('collapses runs of punctuation and trims the edges', () => {
        expect(slugify('  --Hello,   World!!  ')).toBe('hello-world');
    });

    it('returns an empty string for input with nothing usable', () => {
        expect(slugify('***')).toBe('');
    });
});
