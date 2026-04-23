@php
    $logText = $log ?? '';
    $maxHeight = $maxHeight ?? 'max-h-80';
    $placeholder = $placeholder ?? 'No output yet.';
    $autoScroll = $autoScroll ?? true;
    $reverse = $reverse ?? false;
@endphp

<div
    class="mt-1 w-full min-w-0 max-w-full {{ $maxHeight }} overflow-auto rounded-lg border border-slate-200/70 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/40 text-xs text-slate-600 dark:text-slate-300"
    style="scrollbar-gutter: stable;"
    x-data="{
        raw: @js($logText),
        sections: [],
        autoScroll: @js($autoScroll),
        reverse: @js($reverse),
        init() {
            this.sections = this.buildSections(this.raw);
            if (this.autoScroll && this.sections.length) {
                const openIndex = this.reverse ? 0 : (this.sections.length - 1);
                if (this.sections[openIndex]) {
                    this.sections[openIndex].open = true;
                }
            }
        },
        buildSections(raw) {
            if (!raw) {
                return [];
            }
            const lines = raw.split(/\\r?\\n/);
            const sections = [];
            let current = null;
            let general = null;
            let generalIndex = 0;
            const flushGeneral = () => {
                if (general && general.lines.length) {
                    sections.push(general);
                    general = null;
                }
            };

            for (const line of lines) {
                const startMatch = line.match(/^Process #(\\d+) started: (.*)$/);
                if (startMatch) {
                    flushGeneral();
                    if (current) {
                        sections.push(current);
                    }
                    current = {
                        key: `process-${startMatch[1]}`,
                        title: `Process #${startMatch[1]}`,
                        command: startMatch[2],
                        lines: [line],
                        exit: null,
                        open: false,
                        isGeneral: false,
                    };
                    continue;
                }

                const endMatch = line.match(/^Process #(\\d+) finished with exit code (.*)\\.$/);
                if (endMatch) {
                    if (current && current.key === `process-${endMatch[1]}`) {
                        current.lines.push(line);
                        current.exit = endMatch[2];
                        sections.push(current);
                        current = null;
                        continue;
                    }
                }

                if (current) {
                    current.lines.push(line);
                } else {
                    if (! general) {
                        general = {
                            key: `general-${generalIndex++}`,
                            title: 'Log output',
                            command: null,
                            lines: [],
                            exit: null,
                            open: true,
                            isGeneral: true,
                        };
                    }
                    general.lines.push(line);
                }
            }

            if (current) {
                sections.push(current);
            }

            flushGeneral();

            if (this.reverse) {
                for (const section of sections) {
                    section.lines = section.lines.slice().reverse();
                }
                sections.reverse();
            }

            return sections;
        }
    }"
    x-init="
        if (autoScroll) {
            const el = $el;
            const scrollToEdge = () => { el.scrollTop = reverse ? 0 : el.scrollHeight; };
            scrollToEdge();
            const observer = new MutationObserver(scrollToEdge);
            observer.observe(el, { childList: true, characterData: true, subtree: true });
            if (typeof $cleanup === 'function') {
                $cleanup(() => observer.disconnect());
            }
        }
    "
>
    <div class="inline-block min-w-full align-top p-3">
        <template x-if="sections.length === 0">
            <pre class="inline-block min-w-full whitespace-pre font-mono leading-relaxed">{{ $placeholder }}</pre>
        </template>
        <template x-for="section in sections" :key="section.key">
            <div class="mb-2 w-max min-w-full last:mb-0">
                <template x-if="section.isGeneral">
                    <pre class="inline-block min-w-full whitespace-pre font-mono leading-relaxed text-slate-600 dark:text-slate-300" x-text="section.lines.join('\n')"></pre>
                </template>
                <template x-if="!section.isGeneral">
                    <details class="w-max min-w-full rounded-md border border-slate-200/70 dark:border-slate-800 bg-white/70 dark:bg-slate-900/40 p-2" :open="section.open">
                        <summary class="cursor-pointer text-xs text-indigo-600 dark:text-indigo-300">
                            <span x-text="section.title"></span>
                            <template x-if="section.command">
                                <span class="mt-1 block break-all text-[10px] text-slate-400 sm:mt-0 sm:inline">
                                    —
                                    <span x-text="section.command"></span>
                                </span>
                            </template>
                            <template x-if="section.exit !== null">
                                <span class="text-[10px] text-slate-400"> (exit <span x-text="section.exit"></span>)</span>
                            </template>
                        </summary>
                        <pre class="mt-2 inline-block min-w-full whitespace-pre font-mono leading-relaxed text-slate-600 dark:text-slate-300" x-text="section.lines.join('\n')"></pre>
                    </details>
                </template>
            </div>
        </template>
    </div>
</div>
