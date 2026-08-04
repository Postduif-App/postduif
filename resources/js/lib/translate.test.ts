import { describe, expect, it } from 'vitest';

import { choose, translate } from '@/lib/translate';

const lines = {
    'chat.not_a_member': 'Je bent geen lid van deze workspace.',
    'mail.invitation.intro': ':inviter nodigt je uit voor :workspace.',
    'notifications.activity.messages':
        '{1}:count bericht|[2,*]:count berichten',
    'notifications.activity.subject_unread':
        '{1}Eén nieuw bericht in :workspace|[2,*]:count nieuwe berichten in :workspace',
    'console.partial': '{1}één|{2}twee',
};

describe('translate', () => {
    it('hands back the line', () => {
        expect(translate(lines, 'chat.not_a_member')).toBe(
            'Je bent geen lid van deze workspace.',
        );
    });

    it('fills every placeholder', () => {
        expect(
            translate(lines, 'mail.invitation.intro', {
                inviter: 'Fenna',
                workspace: 'Postduif',
            }),
        ).toBe('Fenna nodigt je uit voor Postduif.');
    });

    it('shows the key when there is no line', () => {
        // Deliberately ugly rather than empty: a hole in the page is invisible
        // in review, where a key on screen is impossible to miss.
        expect(translate(lines, 'chat.verzonnen')).toBe('chat.verzonnen');
    });

    it('leaves a placeholder nobody passed alone', () => {
        expect(
            translate(lines, 'mail.invitation.intro', { inviter: 'Fenna' }),
        ).toBe('Fenna nodigt je uit voor :workspace.');
    });
});

describe('choose', () => {
    it('takes the branch that matches exactly', () => {
        expect(choose(lines, 'notifications.activity.messages', 1)).toBe(
            '1 bericht',
        );
    });

    it('takes the branch whose range contains the count', () => {
        expect(choose(lines, 'notifications.activity.messages', 7)).toBe(
            '7 berichten',
        );
    });

    it('lets a branch spell the number out', () => {
        // The whole reason for the explicit {1} form: "Eén" is not "1".
        expect(
            choose(lines, 'notifications.activity.subject_unread', 1, {
                workspace: 'Postduif',
            }),
        ).toBe('Eén nieuw bericht in Postduif');
    });

    it('counts zero as its own case rather than as one', () => {
        expect(choose(lines, 'notifications.activity.messages', 0)).toBe(
            '0 berichten',
        );
    });

    it('falls back to the last branch when none claims the count', () => {
        // A line missing its [2,*] is a wording bug; showing the last branch is
        // a far better wrong answer than showing the key.
        expect(choose(lines, 'console.partial', 9)).toBe('twee');
    });

    it('shows the key when there is no line', () => {
        expect(choose(lines, 'console.verzonnen', 2)).toBe('console.verzonnen');
    });
});
