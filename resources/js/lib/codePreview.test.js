import { describe, expect, it } from 'vitest';
import { codePreview, stripComments } from './codePreview.js';

describe('stripComments', () => {
    it('removes line, hash and block comments', () => {
        expect(stripComments("a(); // note\nb(); # other\n/* x\ny */c();")).toBe('a(); \nb(); \n c();');
    });

    it('leaves comment markers inside strings alone', () => {
        const code = `$u = 'http://example.com'; $h = "#1 /* not */"; $e = 'it\\'s // fine';`;
        expect(stripComments(code)).toBe(code);
    });

    it('keeps PHP 8 attributes', () => {
        expect(stripComments('#[Pure] fn () => 1;')).toBe('#[Pure] fn () => 1;');
    });

    it('drops an unterminated block comment to the end', () => {
        expect(stripComments('a(); /* never closed').trim()).toBe('a();');
    });
});

describe('codePreview', () => {
    it('skips the leading comment to show the first line of code', () => {
        expect(codePreview("// Explore your app — Cmd/Ctrl+Enter to run\nUser::count();")).toBe('User::count();');
    });

    it('drops a trailing comment from the shown line', () => {
        expect(codePreview('User::first(); // who signed up first')).toBe('User::first();');
    });

    it('is empty for code that is only comments', () => {
        expect(codePreview('// todo\n/* later */')).toBe('');
    });

    it('caps long lines', () => {
        expect(codePreview('x'.repeat(100), 10)).toBe('xxxxxxxxxx…');
    });
});
