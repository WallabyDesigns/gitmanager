@props([
    'placeholder' => 'Write a message...',
    'minHeight' => '18rem',
])

@once
    <style>
        .gwm-richtext-shell {
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.82);
            overflow: hidden;
        }

        .dark .gwm-richtext-shell {
            border-color: rgb(51 65 85 / 0.9);
            background: rgb(2 6 23 / 0.88);
        }

        .gwm-richtext-toolbar button {
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.4);
            color: inherit;
            border-radius: 0.5rem;
            padding: 0.45rem 0.65rem;
            font-size: 0.8rem;
            line-height: 1;
            transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
        }

        .gwm-richtext-toolbar button:hover {
            border-color: rgba(99, 102, 241, 0.55);
            background: rgba(99, 102, 241, 0.12);
        }

        .gwm-richtext-editor {
            outline: none;
            height: var(--gwm-richtext-height, 18rem);
            min-height: var(--gwm-richtext-height, 18rem);
            overflow-y: auto;
            resize: vertical;
            max-height: 32rem;
            scrollbar-gutter: stable;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .gwm-richtext-editor:empty::before {
            content: attr(data-placeholder);
            color: rgb(100 116 139);
            pointer-events: none;
        }

        .gwm-richtext-editor p,
        .gwm-support-richtext p {
            margin: 0 0 0.75rem;
        }

        .gwm-richtext-editor p:last-child,
        .gwm-support-richtext p:last-child {
            margin-bottom: 0;
        }

        .gwm-richtext-editor ul,
        .gwm-richtext-editor ol,
        .gwm-support-richtext ul,
        .gwm-support-richtext ol {
            margin: 0.5rem 0 0.75rem 1.25rem;
        }

        .gwm-richtext-editor ul,
        .gwm-support-richtext ul {
            list-style: disc;
        }

        .gwm-richtext-editor ol,
        .gwm-support-richtext ol {
            list-style: decimal;
        }

        .gwm-richtext-editor blockquote,
        .gwm-support-richtext blockquote {
            margin: 0.75rem 0;
            border-left: 3px solid rgba(99, 102, 241, 0.45);
            padding-left: 0.9rem;
            color: rgb(148 163 184);
        }

        .gwm-richtext-editor pre,
        .gwm-support-richtext pre {
            margin: 0.75rem 0;
            padding: 0.75rem 0.9rem;
            border-radius: 0.75rem;
            background: rgba(2, 6, 23, 0.78);
            overflow-x: auto;
        }

        .gwm-richtext-editor code,
        .gwm-support-richtext code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.9em;
        }

        .gwm-richtext-editor a,
        .gwm-support-richtext a {
            color: rgb(129 140 248);
            text-decoration: underline;
        }

        .gwm-richtext-editor::-webkit-scrollbar {
            width: 10px;
        }

        .gwm-richtext-editor::-webkit-scrollbar-track {
            background: transparent;
        }

        .gwm-richtext-editor::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.28);
        }

        .gwm-richtext-editor::-webkit-scrollbar-thumb:hover {
            background: rgba(99, 102, 241, 0.42);
        }
    </style>
@endonce

<div
    x-data="{
        value: @entangle($attributes->wire('model')),
        placeholder: @js($placeholder),
        minHeight: @js($minHeight),
        syncingFromEditor: false,
        init() {
            this.$nextTick(() => {
                this.renderFromValue(this.value);
            });

            this.$watch('value', (next) => {
                if (this.syncingFromEditor) {
                    return;
                }

                this.renderFromValue(next);
            });
        },
        focusEditor() {
            this.$refs.editor?.focus();
        },
        exec(command, commandValue = null) {
            this.focusEditor();
            document.execCommand(command, false, commandValue);
            this.syncFromEditor();
        },
        toggleLink() {
            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0 || selection.toString().trim() === '') {
                return;
            }

            const raw = window.prompt('Enter a URL', 'https://');
            if (!raw) {
                return;
            }

            const url = /^[a-z]+:/i.test(raw) ? raw : `https://${raw}`;
            this.exec('createLink', url);
        },
        clearFormatting() {
            this.focusEditor();
            document.execCommand('removeFormat', false, null);
            this.syncFromEditor();
        },
        syncFromEditor() {
            const editor = this.$refs.editor;
            if (!editor) {
                return;
            }

            if ((editor.textContent || '').trim() === '') {
                editor.innerHTML = '';
                this.syncingFromEditor = true;
                this.value = '';
                this.syncingFromEditor = false;
                return;
            }

            const html = this.normalizeHtml(editor.innerHTML);
            this.syncingFromEditor = true;
            this.value = html;
            this.syncingFromEditor = false;
        },
        renderFromValue(next) {
            const editor = this.$refs.editor;
            if (!editor) {
                return;
            }

            const normalized = this.normalizeHtml(next || '');
            if (this.normalizeHtml(editor.innerHTML) === normalized) {
                return;
            }

            editor.innerHTML = normalized;
            if (normalized === '') {
                editor.innerHTML = '';
            }
        },
        normalizeHtml(value) {
            const html = String(value || '').trim();
            if (html === '' || html === '<br>' || html === '<p><br></p>' || html === '<div><br></div>') {
                return '';
            }

            return html;
        },
    }"
    x-init="init()"
    class="space-y-3"
>
    <div class="gwm-richtext-toolbar flex flex-wrap gap-2 text-slate-600 dark:text-slate-200">
        <button type="button" @click.prevent="exec('bold')" title="Bold"><strong>B</strong></button>
        <button type="button" @click.prevent="exec('italic')" title="Italic"><em>I</em></button>
        <button type="button" @click.prevent="exec('underline')" title="Underline"><span style="text-decoration: underline;">U</span></button>
        <button type="button" @click.prevent="exec('insertUnorderedList')" title="Bullet list">• List</button>
        <button type="button" @click.prevent="exec('insertOrderedList')" title="Numbered list">1. List</button>
        <button type="button" @click.prevent="exec('formatBlock', 'blockquote')" title="Quote">Quote</button>
        <button type="button" @click.prevent="toggleLink()" title="Insert link">Link</button>
        <button type="button" @click.prevent="clearFormatting()" title="Clear formatting">Clear</button>
    </div>

    <div class="gwm-richtext-shell" :style="`--gwm-richtext-height: ${minHeight}`">
        <div
            x-ref="editor"
            contenteditable="true"
            :data-placeholder="placeholder"
            @input="syncFromEditor()"
            @blur="syncFromEditor()"
            class="gwm-richtext-editor w-full px-3 py-3 text-sm text-slate-900 dark:text-slate-100"
        ></div>
    </div>
</div>
