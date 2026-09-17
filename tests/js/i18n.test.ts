import { describe, expect, it } from 'vitest';

import { translate } from '@/lib/i18n';

const dictionary = {
    'dashboard.greeting': 'Hello :name',
    'common.time.minutes_ago': ':count minute ago|:count minutes ago',
    'common.actions.save': 'Save',
};

describe('translate', () => {
    it('returns a plain line unchanged', () => {
        expect(translate(dictionary, 'common.actions.save')).toBe('Save');
    });

    it('replaces named placeholders', () => {
        expect(translate(dictionary, 'dashboard.greeting', { name: 'Rory' })).toBe('Hello Rory');
    });

    it('picks the singular form for a count of one', () => {
        expect(translate(dictionary, 'common.time.minutes_ago', { count: 1 })).toBe('1 minute ago');
    });

    it('picks the plural form for any other count', () => {
        expect(translate(dictionary, 'common.time.minutes_ago', { count: 7 })).toBe('7 minutes ago');
    });

    it('falls back to the key when a translation is missing', () => {
        expect(translate(dictionary, 'nope.missing')).toBe('nope.missing');
    });
});
