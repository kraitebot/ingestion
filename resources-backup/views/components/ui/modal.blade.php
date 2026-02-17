{{-- resources/views/components/ui/modal.blade.php --}}
@php
    /**
     * <x-ui.modal>
     * - Reusable modal/dialog with blurred overlay
     * - Triggered via JavaScript: showModal('modal-id') / hideModal('modal-id')
     *
     * Props:
     * - id (required): unique identifier for the modal
     * - title: modal heading
     * - confirm-text: text for confirm button (default: "Confirm")
     * - confirm-icon: icon for confirm button (default: "check")
     * - cancel-text: text for cancel button (default: "Cancel")
     * - danger: boolean, makes confirm button destructive red style
     *
     * Slots:
     * - default: modal body content
     * - footer: custom footer (overrides default buttons)
     */

    $id = $attributes->get('id');
    if (!$id) {
        throw new \Exception('Modal component requires an "id" attribute');
    }

    $title = $attributes->get('title', 'Confirm');
    $confirmText = $attributes->get('confirm-text', 'Confirm');
    $confirmIcon = $attributes->get('confirm-icon', 'check');
    $cancelText = $attributes->get('cancel-text', 'Cancel');
    $dangerRaw = $attributes->get('danger', false);
    $danger = is_bool($dangerRaw) ? $dangerRaw : filter_var($dangerRaw, FILTER_VALIDATE_BOOLEAN);

    // Pass-through attributes
    $passthrough = $attributes->except(['id', 'title', 'confirm-text', 'confirm-icon', 'cancel-text', 'danger']);
@endphp

{{-- Modal Backdrop --}}
<div
    id="{{ $id }}"
    class="modal-overlay fixed inset-0 z-50 hidden opacity-0"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    {{-- Blurred Backdrop --}}
    <div
        class="modal-backdrop fixed inset-0 bg-black/60 backdrop-blur-sm transition-all duration-500 ease-out"
        onclick="window.hideModal('{{ $id }}')"
    ></div>

    {{-- Modal Container --}}
    <div class="fixed inset-0 overflow-y-auto" onclick="window.hideModal('{{ $id }}')">
        <div class="flex min-h-full items-start justify-center pt-40 pb-4 px-4" onclick="window.hideModal('{{ $id }}')">
            {{-- Modal Content --}}
            <div
                class="modal-content relative w-full max-w-md transform rounded-2xl border border-white/20 p-6 shadow-2xl transition-all duration-500 ease-out scale-95 opacity-0"
                style="background-color: {{ theme('background.modal') }}"
                onclick="event.stopPropagation()"
            >
                {{-- Close Button --}}
                <button
                    type="button"
                    onclick="window.hideModal('{{ $id }}')"
                    class="absolute top-4 right-4 text-white/40 hover:text-white transition-colors cursor-pointer"
                    aria-label="Close"
                >
                    <x-feathericon-x class="h-5 w-5"/>
                </button>

                {{-- Modal Header --}}
                <div class="mb-4 pr-8">
                    <h3 id="{{ $id }}-title" class="text-lg font-semibold text-white">
                        {{ $title }}
                    </h3>
                </div>

                {{-- Modal Body --}}
                <div class="mb-6 text-sm text-white/80">
                    {{ $slot }}
                </div>

                {{-- Modal Footer --}}
                @if (isset($footer))
                    {{ $footer }}
                @else
                    <div class="flex gap-3">
                        <button
                            type="button"
                            onclick="window.hideModal('{{ $id }}')"
                            class="flex-1 h-10 px-4 rounded-lg border border-white/20 bg-white/5 text-white hover:bg-white/10 transition-colors text-sm font-medium cursor-pointer"
                        >
                            {{ $cancelText }}
                        </button>
                        <button
                            type="button"
                            data-modal-confirm="{{ $id }}"
                            @if($danger)
                                class="flex-1 h-10 px-4 rounded-lg border border-red-400/40 bg-gradient-to-br from-red-500/[0.28] to-red-600/[0.18] text-white hover:from-red-500/[0.38] hover:to-red-600/[0.28] hover:border-red-400/60 transition-all text-sm font-medium inline-flex items-center justify-center gap-2 cursor-pointer"
                            @else
                                class="flex-1 h-10 px-4 rounded-lg border border-emerald-400/40 bg-gradient-to-br from-emerald-500/[0.28] to-emerald-600/[0.18] text-white hover:from-emerald-500/[0.38] hover:to-emerald-600/[0.28] hover:border-emerald-400/60 transition-all text-sm font-medium inline-flex items-center justify-center gap-2 cursor-pointer"
                            @endif
                        >
                            @if($confirmIcon)
                                <x-feathericon-{{ $confirmIcon }} class="h-4 w-4"/>
                            @endif
                            {{ $confirmText }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
