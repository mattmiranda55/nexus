// Nexus editor theme. Replaces one-dark so the code surface belongs to the same
// warm ink-on-paper palette as the rest of the app.
//
// Every colour is a `--nx-*` custom property rather than a literal: CodeMirror
// compiles these specs to real stylesheets, so the editor re-tints itself from
// the cascade the moment `.dark` flips. Only the `dark` flag — which CodeMirror
// uses for its own internal contrast decisions — differs between the two
// exported extensions.
import { EditorView } from '@codemirror/view';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';

const base = {
    '&': {
        color: 'var(--nx-ink)',
        backgroundColor: 'var(--nx-surface)',
    },

    '&.cm-focused': {
        outline: 'none',
    },

    '.cm-scroller': {
        fontFamily: 'var(--font-mono)',
        fontSize: '12.5px',
        lineHeight: '1.65',
    },

    '.cm-content': {
        caretColor: 'var(--nx-accent)',
        padding: '10px 0',
    },

    // A 2px accent caret reads as a drafting cursor rather than a hairline.
    '.cm-cursor, .cm-dropCursor': {
        borderLeftWidth: '2px',
        borderLeftColor: 'var(--nx-accent)',
    },

    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'var(--nx-select)',
    },

    '.cm-activeLine': {
        backgroundColor: 'var(--nx-active-line)',
    },

    // Gutter sits on the page colour, separated by the same hairline the rest of
    // the chrome uses, so the editor reads as one plate with a ruled margin.
    '.cm-gutters': {
        backgroundColor: 'var(--nx-paper)',
        color: 'var(--nx-ink-3)',
        border: 'none',
        borderRight: '1px solid var(--nx-rule)',
        fontSize: '11px',
    },

    '.cm-lineNumbers .cm-gutterElement': {
        padding: '0 10px 0 12px',
        minWidth: '2.25rem',
    },

    '.cm-activeLineGutter': {
        backgroundColor: 'transparent',
        color: 'var(--nx-accent)',
    },

    '.cm-foldGutter .cm-gutterElement': {
        color: 'var(--nx-ink-3)',
    },

    '.cm-foldPlaceholder': {
        color: 'var(--nx-ink-3)',
        backgroundColor: 'var(--nx-raised)',
        border: '1px solid var(--nx-rule)',
        borderRadius: '0',
        padding: '0 4px',
        margin: '0 2px',
    },

    '.cm-matchingBracket, &.cm-focused .cm-matchingBracket': {
        backgroundColor: 'transparent',
        outline: '1px solid var(--nx-rule-2)',
        color: 'inherit',
    },

    '.cm-nonmatchingBracket, &.cm-focused .cm-nonmatchingBracket': {
        backgroundColor: 'transparent',
        outline: '1px solid var(--nx-err)',
    },

    '.cm-selectionMatch': {
        backgroundColor: 'var(--nx-accent-soft)',
    },

    '.cm-searchMatch': {
        backgroundColor: 'var(--nx-accent-soft)',
        outline: '1px solid var(--nx-rule-2)',
    },

    '.cm-searchMatch.cm-searchMatch-selected': {
        backgroundColor: 'var(--nx-accent)',
        color: 'var(--nx-accent-ink)',
        outline: 'none',
    },

    // Search / goto-line panels, styled to match the app's chrome bars.
    '.cm-panels': {
        backgroundColor: 'var(--nx-raised)',
        color: 'var(--nx-ink)',
        fontFamily: 'var(--font-sans)',
        fontSize: '11px',
    },

    '.cm-panels.cm-panels-top': { borderBottom: '1px solid var(--nx-rule)' },
    '.cm-panels.cm-panels-bottom': { borderTop: '1px solid var(--nx-rule)' },

    '.cm-panel input, .cm-panel button, .cm-panel select': {
        backgroundColor: 'var(--nx-surface)',
        color: 'var(--nx-ink)',
        border: '1px solid var(--nx-rule)',
        borderRadius: '0',
        padding: '2px 6px',
    },

    '.cm-tooltip': {
        backgroundColor: 'var(--nx-raised)',
        color: 'var(--nx-ink)',
        border: '1px solid var(--nx-rule-2)',
        borderRadius: '0',
        boxShadow: 'var(--nx-plate-shadow)',
    },

    '.cm-tooltip .cm-tooltip-arrow:before': {
        borderTopColor: 'transparent',
        borderBottomColor: 'transparent',
    },

    '.cm-tooltip .cm-tooltip-arrow:after': {
        borderTopColor: 'var(--nx-raised)',
        borderBottomColor: 'var(--nx-raised)',
    },

    '.cm-tooltip-autocomplete > ul': {
        fontFamily: 'var(--font-mono)',
        fontSize: '11.5px',
    },

    '.cm-tooltip-autocomplete > ul > li': {
        padding: '2px 8px',
    },

    '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
        backgroundColor: 'var(--nx-accent)',
        color: 'var(--nx-accent-ink)',
    },

    '.cm-completionIcon': {
        color: 'var(--nx-ink-3)',
    },

    '.cm-completionDetail': {
        color: 'var(--nx-ink-3)',
        fontStyle: 'normal',
    },

    '.cm-lintRange-error': {
        backgroundImage: 'none',
        borderBottom: '1px dashed var(--nx-err)',
    },

    '.cm-placeholder': {
        color: 'var(--nx-ink-3)',
    },
};

// Syntax colours reuse the same four data hues the tree and table use, so a
// string looks the same in the editor as it does in a result cell.
const highlight = HighlightStyle.define([
    { tag: [t.keyword, t.modifier, t.controlKeyword], color: 'var(--nx-accent)' },
    { tag: [t.operator, t.operatorKeyword, t.punctuation, t.bracket], color: 'var(--nx-ink-2)' },
    { tag: [t.variableName, t.propertyName], color: 'var(--nx-ink)' },
    { tag: [t.function(t.variableName), t.function(t.propertyName)], color: 'var(--nx-key)' },
    { tag: [t.typeName, t.className, t.namespace], color: 'var(--nx-bool)' },
    { tag: [t.number, t.integer, t.float, t.bool, t.null], color: 'var(--nx-num)' },
    { tag: [t.string, t.special(t.string), t.regexp], color: 'var(--nx-str)' },
    { tag: [t.escape, t.character], color: 'var(--nx-num)' },
    { tag: [t.comment, t.blockComment, t.lineComment], color: 'var(--nx-ink-3)', fontStyle: 'italic' },
    { tag: [t.meta, t.processingInstruction], color: 'var(--nx-ink-3)' },
    { tag: [t.definition(t.variableName), t.definition(t.propertyName)], color: 'var(--nx-ink)' },
    { tag: t.tagName, color: 'var(--nx-accent)' },
    { tag: t.attributeName, color: 'var(--nx-key)' },
    { tag: t.heading, color: 'var(--nx-ink)', fontWeight: 'bold' },
    { tag: t.link, color: 'var(--nx-key)', textDecoration: 'underline' },
    { tag: t.strong, fontWeight: 'bold' },
    { tag: t.emphasis, fontStyle: 'italic' },
    { tag: t.strikethrough, textDecoration: 'line-through' },
    { tag: t.invalid, color: 'var(--nx-err)' },
]);

const themes = {
    true: [EditorView.theme(base, { dark: true }), syntaxHighlighting(highlight)],
    false: [EditorView.theme(base, { dark: false }), syntaxHighlighting(highlight)],
};

/**
 * The editor extension for the current app theme.
 *
 * @param {boolean} dark
 */
export function nexusEditorTheme(dark) {
    return themes[dark ? 'true' : 'false'];
}
