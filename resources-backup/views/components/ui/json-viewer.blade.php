{{-- resources/views/components/ui/json-viewer.blade.php --}}
@php
    /**
     * <x-ui.json-viewer>
     * Professional JSON viewer with tree structure, syntax highlighting, and interactive features.
     *
     * Props:
     * - data: JSON string or array/object to display
     * - title: Optional title for the viewer
     * - show-controls: Show expand/collapse/copy controls (default: true)
     * - max-height: Optional max height (e.g., '500px', '80vh')
     * - theme: 'blue' | 'red' (default: 'blue')
     */

    $data = $attributes->get('data');
    $title = $attributes->get('title', 'JSON Response');
    $showControls = $attributes->get('show-controls', true);
    $maxHeight = $attributes->get('max-height');
    $theme = $attributes->get('theme', 'blue');

    // Theme-based focus classes
    $focusClasses = match($theme) {
        'red' => 'focus:ring-red-400/20 focus:border-red-400/30',
        'blue' => 'focus:ring-blue-400/20 focus:border-blue-400/30',
        'green' => 'focus:ring-green-400/20 focus:border-green-400/30',
        default => 'focus:ring-blue-400/20 focus:border-blue-400/30',
    };

    // Generate unique ID for this instance
    $viewerId = 'json-viewer-' . uniqid();

    // Encode data for Alpine
    $jsonData = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT);
@endphp

<div
    x-data="jsonViewer(@js($jsonData), @js($viewerId))"
    x-init="init()"
    class="rounded-2xl border border-white/10 bg-white/[0.02] overflow-hidden"
>
    {{-- Header --}}
    <div class="border-b border-white/10 bg-white/[0.02] px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-white flex items-center gap-2">
            <x-feathericon-code class="h-4 w-4 text-white/60" />
            {{ $title }}
        </h3>

        @if($showControls)
        <div class="flex items-center gap-2 w-full sm:w-auto">
            {{-- Search --}}
            <div class="relative flex-1 sm:flex-initial">
                <input
                    type="text"
                    x-model="searchQuery"
                    @input="performSearch"
                    placeholder="Search..."
                    class="w-full sm:w-48 h-8 pl-8 pr-3 rounded-lg bg-black/40 border border-white/10 text-white text-xs placeholder-white/40 focus:ring-2 {{ $focusClasses }} outline-none transition"
                />
                <x-feathericon-search class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-white/40" />
            </div>

            <button
                @click="expandAll()"
                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-white/10 text-white/60 hover:text-white/80 hover:bg-white/5 transition-colors whitespace-nowrap"
            >
                Expand All
            </button>
            <button
                @click="collapseAll()"
                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-white/10 text-white/60 hover:text-white/80 hover:bg-white/5 transition-colors whitespace-nowrap"
            >
                Collapse All
            </button>
            <button
                @click="copyToClipboard()"
                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-white/10 text-white/60 hover:text-white/80 hover:bg-white/5 transition-colors"
                :class="{ 'text-green-400 border-green-400/30': copied }"
            >
                <x-feathericon-copy class="h-3.5 w-3.5" x-show="!copied" />
                <x-feathericon-check class="h-3.5 w-3.5" x-show="copied" />
            </button>
        </div>
        @endif
    </div>

    {{-- JSON Tree View --}}
    <div class="p-4 sm:p-6 overflow-x-auto {{ $maxHeight ? 'overflow-y-auto' : '' }}"
         @if($maxHeight) style="max-height: {{ $maxHeight }}" @endif>
        <div class="bg-black/40 rounded-lg p-4 font-mono text-xs leading-relaxed" x-html="renderNode(parsedData, '', 0)"></div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('jsonViewer', (jsonString, viewerId) => ({
        parsedData: null,
        expandedPaths: new Set(),
        searchQuery: '',
        matchedPaths: new Set(),
        copied: false,

        init() {
            try {
                this.parsedData = typeof jsonString === 'string'
                    ? JSON.parse(jsonString)
                    : jsonString;
            } catch (e) {
                this.parsedData = { error: 'Invalid JSON', message: e.message };
            }
        },

        togglePath(path) {
            if (this.expandedPaths.has(path)) {
                this.expandedPaths.delete(path);
            } else {
                this.expandedPaths.add(path);
            }
        },

        expandAll() {
            this.expandedPaths.clear();
            this.addAllPaths(this.parsedData, '');
        },

        collapseAll() {
            this.expandedPaths.clear();
        },

        addAllPaths(data, path) {
            if (Array.isArray(data)) {
                this.expandedPaths.add(path);
                data.forEach((item, index) => {
                    this.addAllPaths(item, `${path}[${index}]`);
                });
            } else if (typeof data === 'object' && data !== null) {
                this.expandedPaths.add(path);
                Object.keys(data).forEach(key => {
                    this.addAllPaths(data[key], path ? `${path}.${key}` : key);
                });
            }
        },

        performSearch() {
            this.matchedPaths.clear();
            if (!this.searchQuery.trim()) return;

            const query = this.searchQuery.toLowerCase();
            this.searchInData(this.parsedData, '', query);
        },

        searchInData(data, path, query) {
            if (data === null || data === undefined) return;

            const valueStr = String(data).toLowerCase();
            if (valueStr.includes(query)) {
                this.matchedPaths.add(path);
                this.expandPathAndParents(path);
            }

            if (Array.isArray(data)) {
                data.forEach((item, index) => {
                    this.searchInData(item, `${path}[${index}]`, query);
                });
            } else if (typeof data === 'object') {
                Object.keys(data).forEach(key => {
                    if (key.toLowerCase().includes(query)) {
                        this.matchedPaths.add(path ? `${path}.${key}` : key);
                        this.expandPathAndParents(path);
                    }
                    this.searchInData(data[key], path ? `${path}.${key}` : key, query);
                });
            }
        },

        expandPathAndParents(path) {
            const parts = path.split(/[\.\[]/).filter(p => p && p !== ']');
            let current = '';
            parts.forEach(part => {
                current = current ? `${current}.${part}` : part;
                this.expandedPaths.add(current.replace(/\]$/, ''));
            });
        },

        async copyToClipboard() {
            try {
                await navigator.clipboard.writeText(JSON.stringify(this.parsedData, null, 2));
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            } catch (e) {
                console.error('Failed to copy:', e);
            }
        },

        renderNode(data, path, level) {
            const indent = level * 16;
            const isExpanded = this.expandedPaths.has(path);
            const isMatched = this.matchedPaths.has(path);
            const highlightClass = isMatched ? 'bg-yellow-500/20 rounded px-1' : '';

            // Null
            if (data === null) {
                return `<span class="${highlightClass} text-gray-500">null</span>`;
            }

            // Boolean
            if (typeof data === 'boolean') {
                return `<span class="${highlightClass} text-yellow-400">${data}</span>`;
            }

            // Number
            if (typeof data === 'number') {
                return `<span class="${highlightClass} text-blue-400">${data}</span>`;
            }

            // String
            if (typeof data === 'string') {
                const escaped = data.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return `<span class="${highlightClass} text-green-400">"${escaped}"</span>`;
            }

            // Array
            if (Array.isArray(data)) {
                if (data.length === 0) {
                    return `<span class="text-white/60">[]</span>`;
                }

                const toggleBtn = `
                    <button
                        @click="togglePath('${path}')"
                        class="inline-flex items-center gap-1.5 text-white/60 hover:text-white/90 transition-colors"
                    >
                        <span class="transition-transform duration-200" style="display: inline-block; transform: rotate(${isExpanded ? '90deg' : '0deg'})">▶</span>
                        <span class="text-blue-300/60 text-[10px] uppercase tracking-wider font-semibold">Array[${data.length}]</span>
                    </button>
                `;

                if (!isExpanded) {
                    return `<span class="text-white/60">[</span> ${toggleBtn} <span class="text-white/60">]</span>`;
                }

                let html = `<span class="text-white/60">[</span> ${toggleBtn}<div class="ml-4 border-l border-white/5 pl-4 mt-1">`;
                data.forEach((item, index) => {
                    const itemPath = `${path}[${index}]`;
                    const itemMatched = this.matchedPaths.has(itemPath);
                    const itemHighlight = itemMatched ? 'bg-yellow-500/10 -ml-1 pl-1 rounded' : '';
                    html += `<div class="${itemHighlight} py-0.5">`;
                    html += `<span class="text-white/40 mr-2">${index}:</span>`;
                    html += this.renderNode(item, itemPath, level + 1);
                    if (index < data.length - 1) html += '<span class="text-white/40">,</span>';
                    html += '</div>';
                });
                html += '</div><span class="text-white/60">]</span>';
                return html;
            }

            // Object
            if (typeof data === 'object') {
                const keys = Object.keys(data);
                if (keys.length === 0) {
                    return `<span class="text-white/60">{}</span>`;
                }

                const toggleBtn = `
                    <button
                        @click="togglePath('${path}')"
                        class="inline-flex items-center gap-1.5 text-white/60 hover:text-white/90 transition-colors"
                    >
                        <span class="transition-transform duration-200" style="display: inline-block; transform: rotate(${isExpanded ? '90deg' : '0deg'})">▶</span>
                        <span class="text-purple-300/60 text-[10px] uppercase tracking-wider font-semibold">Object{${keys.length}}</span>
                    </button>
                `;

                if (!isExpanded) {
                    return `<span class="text-white/60">{</span> ${toggleBtn} <span class="text-white/60">}</span>`;
                }

                let html = `<span class="text-white/60">{</span> ${toggleBtn}<div class="ml-4 border-l border-white/5 pl-4 mt-1">`;
                keys.forEach((key, index) => {
                    const keyPath = path ? `${path}.${key}` : key;
                    const keyMatched = this.matchedPaths.has(keyPath);
                    const keyHighlight = keyMatched ? 'bg-yellow-500/10 -ml-1 pl-1 rounded' : '';
                    html += `<div class="${keyHighlight} py-0.5">`;
                    html += `<span class="text-cyan-300">"${key}"</span><span class="text-white/60">: </span>`;
                    html += this.renderNode(data[key], keyPath, level + 1);
                    if (index < keys.length - 1) html += '<span class="text-white/40">,</span>';
                    html += '</div>';
                });
                html += '</div><span class="text-white/60">}</span>';
                return html;
            }

            return String(data);
        }
    }));
});
</script>
