import { describe, expect, it } from 'vitest';

import {
    classifyDroppedFiles,
    filesFromTransfer,
} from '@/lib/document-drop-files';

function fakeFile(name: string, type: string, size = 10): File {
    // A File the tests can describe exactly, including the empty type and zero
    // size a dropped folder arrives with.
    return {
        name,
        type,
        size,
    } as File;
}

describe('classifyDroppedFiles', () => {
    it('makes a picture out of an image and a file out of the rest', () => {
        const sorted = classifyDroppedFiles([
            fakeFile('schema.png', 'image/png'),
            fakeFile('contract.pdf', 'application/pdf'),
        ]);

        expect(sorted.map((entry) => entry.kind)).toEqual(['image', 'file']);
    });

    it('keeps the order they were held in', () => {
        const sorted = classifyDroppedFiles([
            fakeFile('een.png', 'image/png'),
            fakeFile('twee.png', 'image/png'),
            fakeFile('drie.png', 'image/png'),
        ]);

        expect(sorted.map((entry) => entry.file.name)).toEqual([
            'een.png',
            'twee.png',
            'drie.png',
        ]);
    });

    it('leaves a dropped folder out', () => {
        /*
         * A folder arrives as an entry with no type and no size. Uploading it
         * would fail on the server with a message about file types, which
         * explains nothing to somebody who dropped a folder.
         */
        const sorted = classifyDroppedFiles([
            fakeFile('mijn map', '', 0),
            fakeFile('schema.png', 'image/png'),
        ]);

        expect(sorted).toHaveLength(1);
        expect(sorted[0].file.name).toBe('schema.png');
    });

    it('keeps an empty file that is genuinely a file', () => {
        const sorted = classifyDroppedFiles([
            fakeFile('leeg.txt', 'text/plain', 0),
        ]);

        expect(sorted).toHaveLength(1);
    });
});

describe('filesFromTransfer', () => {
    it('is empty for a paste that carries only text', () => {
        expect(
            filesFromTransfer({ files: [] } as unknown as DataTransfer),
        ).toHaveLength(0);
    });

    it('is empty when there is no transfer at all', () => {
        expect(filesFromTransfer(null)).toHaveLength(0);
    });
});
