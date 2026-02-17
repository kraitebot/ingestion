<x-layouts.app
    :title="'Admin Panel — ' . config('app.name')"
    meta-description="Admin panel for {{ config('app.name') }}."
>
    {{-- HEAD: page-only assets --}}
    <x-slot:head>
        {{-- Using Blade Feather Icons package - no CDN needed --}}
        @vite(['resources/js/admin-console.js'])

        {{-- JSON Viewer Alpine Component --}}
        <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('jsonViewer', (jsonString, viewerId) => ({
                parsedData: null,
                expandedPaths: new Set(),
                searchQuery: '',
                matchedPaths: new Set(),
                copied: false,
                searchTimeout: null,

                init() {
                    try {
                        this.parsedData = typeof jsonString === 'string'
                            ? JSON.parse(jsonString)
                            : jsonString;
                        // Expand all by default
                        this.expandAll();
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
                    // Clear existing timeout
                    if (this.searchTimeout) {
                        clearTimeout(this.searchTimeout);
                    }

                    // Debounce search by 300ms
                    this.searchTimeout = setTimeout(() => {
                        this.matchedPaths.clear();
                        if (!this.searchQuery.trim()) return;

                        const query = this.searchQuery.toLowerCase();
                        this.searchInData(this.parsedData, '', query);
                    }, 300);
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
                                class="inline-flex items-center gap-1.5 text-white/60 hover:text-white/90 transition-colors ml-1"
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
                                class="inline-flex items-center gap-1.5 text-white/60 hover:text-white/90 transition-colors ml-1"
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
    </x-slot:head>

    {{-- BODY TOP: dotted background --}}
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.18)_1px,transparent_0)] [background-size:24px_24px]">
        </div>
    </x-slot:bodyTop>

    {{-- Navbar with Logout --}}
    <x-slot:navbar>
        <x-landing.layout.navbar
            :show-login="false"
            :show-subscribe="false"
            :show-logout="true"
        />
    </x-slot:navbar>

    <section class="px-4">
        <div class="mx-auto max-w-7xl py-8">
            {{-- Page Header --}}
            <div class="mb-6">
                <h1 class="text-2xl md:text-3xl font-bold text-white flex items-center gap-3">
                    <x-feathericon-shield class="h-7 w-7 md:h-8 md:w-8 text-blue-400" aria-hidden="true"/>
                    Admin Panel
                </h1>
                <p class="text-sm text-white/60 mt-1">Low-level exchange API console</p>
            </div>

            {{-- Main Admin Container --}}
            <div class="rounded-2xl border border-white/20 bg-[#0c0a0b] overflow-hidden">
                {{-- Tabs Navigation --}}
                <div class="border-b border-white/20 bg-white/[0.02] relative">
                    <div class="px-2 py-2">
                        <div class="tab-button active flex items-center justify-center gap-2 w-40 py-2.5 rounded-lg text-sm font-medium">
                            <x-feathericon-terminal class="h-4 w-4 shrink-0" aria-hidden="true"/>
                            <span>Console</span>
                        </div>
                    </div>
                </div>

                {{-- Console Content --}}
                <div class="p-4 sm:p-6 md:p-8" x-data="consoleApp" @clear-response.window="response = null">
                    {{-- Header --}}
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 mb-6">
                        <span class="inline-grid h-9 w-9 sm:h-10 sm:w-10 place-items-center rounded-full bg-blue-500/10 border border-blue-400/30 shrink-0">
                            <x-feathericon-terminal class="h-4 w-4 sm:h-5 sm:w-5 text-blue-300" aria-hidden="true"/>
                        </span>
                        <div>
                            <h2 class="text-base sm:text-lg font-semibold text-white">API Console</h2>
                            <p class="text-xs sm:text-sm text-white/60">Execute low-level API calls on exchange accounts</p>
                        </div>
                    </div>

                    {{-- Configuration Panel --}}
                    <div class="flex flex-col lg:flex-row gap-4 mb-8">
                        {{-- User Selection --}}
                        <div class="flex-1">
                            <x-ui.select
                                label="Select User"
                                theme="blue"
                                x-model="selectedUserId"
                                @change="loadAccounts"
                            >
                                <option value="">Choose a user...</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        {{-- Account Selection --}}
                        <div class="flex-1">
                            <x-ui.select
                                label="Select Account"
                                theme="blue"
                                x-model="selectedAccountId"
                                @change="loadApiMethods"
                                x-bind:disabled="!selectedUserId || accounts.length === 0"
                            >
                                <option value="">Choose an account...</option>
                                <template x-for="account in accounts" :key="account.id">
                                    <option :value="account.id" x-text="`${account.name} (${account.api_system.name})`"></option>
                                </template>
                            </x-ui.select>
                        </div>

                        {{-- API Method Selection --}}
                        <div class="flex-1">
                            <x-ui.select
                                label="API Method"
                                theme="blue"
                                x-model="selectedMethod"
                                @change="loadMethodParameters"
                                x-bind:disabled="!selectedAccountId || Object.keys(apiMethods).length === 0"
                            >
                                <option value="">Choose API method...</option>
                                <template x-for="(method, key) in apiMethods" :key="key">
                                    <option :value="key" x-text="method.label"></option>
                                </template>
                            </x-ui.select>
                        </div>

                        {{-- Execute Button --}}
                        <div class="flex-none">
                            <label class="block text-sm font-medium text-white/80 mb-2 invisible">
                                Action
                            </label>
                            <x-ui.button
                                type="button"
                                @click="executeApiCall"
                                x-bind:status="!selectedMethod || loading ? 'disabled' : 'active'"
                                theme="blue"
                                icon="play"
                            >
                                <span x-text="loading ? 'Executing...' : 'Execute'"></span>
                            </x-ui.button>
                        </div>
                    </div>

                    {{-- Dynamic Parameters --}}
                    <div x-show="methodParameters.length > 0" class="mb-8">
                        <div class="rounded-2xl border border-white/10 bg-white/[0.02] p-6">
                            <h3 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                                <x-feathericon-sliders class="h-4 w-4 text-white/60" aria-hidden="true"/>
                                Parameters
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <template x-for="(param, key) in methodParameters" :key="key">
                                    <div>
                                        <label class="block text-xs font-medium text-white/60 mb-2">
                                            <span x-text="param.label"></span>
                                            <span x-show="param.required" class="text-red-400">*</span>
                                        </label>

                                        {{-- Exchange Symbol Dropdown --}}
                                        <template x-if="param.type === 'exchange_symbol'">
                                            <select
                                                x-model="params[key]"
                                                class="w-full rounded-lg bg-[#0a0a0a] border border-white/10 focus:ring-2 focus:ring-blue-400/20 focus:border-blue-400/30 outline-none transition h-10 px-3 text-sm text-white"
                                            >
                                                <option value="">Select symbol...</option>
                                                <template x-for="symbol in exchangeSymbols" :key="symbol.id">
                                                    <option :value="symbol.symbol" x-text="symbol.symbol"></option>
                                                </template>
                                            </select>
                                        </template>

                                        {{-- Text Input --}}
                                        <template x-if="param.type === 'text'">
                                            <input
                                                type="text"
                                                x-model="params[key]"
                                                :placeholder="param.placeholder || ''"
                                                class="w-full rounded-lg bg-white/5 text-white placeholder-white/40 border border-white/10 focus:ring-2 focus:ring-blue-400/20 focus:border-blue-400/30 outline-none transition h-10 px-3 text-sm"
                                            />
                                        </template>

                                        {{-- Number Input --}}
                                        <template x-if="param.type === 'number'">
                                            <input
                                                type="number"
                                                x-model="params[key]"
                                                :placeholder="param.placeholder || ''"
                                                class="w-full rounded-lg bg-white/5 text-white placeholder-white/40 border border-white/10 focus:ring-2 focus:ring-blue-400/20 focus:border-blue-400/30 outline-none transition h-10 px-3 text-sm"
                                            />
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Response Panel --}}
                    <template x-if="response">
                        <div>
                            <div class="rounded-2xl border border-white/10 bg-white/[0.02] overflow-hidden" x-data="jsonViewer(JSON.stringify(response.data || response), 'viewer-' + Date.now())">
                                {{-- Header --}}
                                <div class="border-b border-white/10 bg-white/[0.02] px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                                        <x-feathericon-code class="h-4 w-4 text-white/60" />
                                        API Response
                                    </h3>

                                    <div class="flex items-center gap-2 w-full sm:w-auto">
                                        {{-- Search --}}
                                        <div class="relative flex-1 sm:flex-initial">
                                            <input
                                                type="text"
                                                x-model="searchQuery"
                                                @input="performSearch"
                                                placeholder="Search..."
                                                class="w-full sm:w-48 h-8 pl-8 pr-3 rounded-lg bg-black/40 border border-white/10 text-white text-xs placeholder-white/40 focus:ring-2 focus:ring-blue-400/20 focus:border-blue-400/30 outline-none transition"
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
                                </div>

                                {{-- JSON Tree View --}}
                                <div class="p-4 sm:p-6 overflow-x-auto overflow-y-auto" style="max-height: 600px">
                                    <div class="bg-black/40 rounded-lg p-4 font-mono text-base leading-relaxed" x-html="renderNode(parsedData, '', 0)"></div>
                                </div>

                                {{-- Footer --}}
                                <div class="border-t border-white/10 bg-white/[0.02] px-4 sm:px-6 py-3 space-y-3">
                                    <button
                                        @click="$dispatch('clear-response')"
                                        class="px-4 py-2 rounded-lg text-sm font-medium border border-white/10 text-white/60 hover:text-white/80 hover:bg-white/5 transition-colors"
                                    >
                                        Clear Response
                                    </button>
                                </div>
                            </div>

                            {{-- Info Message (shown outside JSON viewer scope) --}}
                            <div
                                x-show="selectedMethod === 'getLeverageBrackets' && accounts.find(a => a.id === selectedAccountId)?.api_system?.canonical === 'bybit'"
                                class="mt-3 flex items-start gap-2 rounded-lg bg-blue-500/10 border border-blue-400/30 px-3 py-2"
                            >
                                <x-feathericon-info class="h-4 w-4 text-blue-400 shrink-0 mt-0.5" />
                                <p class="text-xs text-blue-200">
                                    Using <span class="font-semibold">category = linear</span> (USDT perpetual futures)
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </section>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>

    {{-- SCRIPTS: Console functionality --}}
    <x-slot:scripts>
        <style>
            .tab-button {
                color: rgb(96, 165, 250);
                background: linear-gradient(to bottom right, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.05));
                border: 1px solid rgba(59, 130, 246, 0.3);
                box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.2),
                            0 0 12px rgba(59, 130, 246, 0.15);
            }
        </style>
    </x-slot:scripts>
</x-layouts.app>
