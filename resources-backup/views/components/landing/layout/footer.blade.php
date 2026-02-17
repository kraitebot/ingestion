{{-- resources/views/components/landing/layout/footer.blade.php --}}
<footer class="bg-[#0c0a0b] border-t border-white/10 py-8 text-center text-sm text-white/60">
    <div class="mx-auto max-w-7xl px-6 flex flex-col lg:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <span>Version: 0.0.1</span>
            <span class="text-white/30">•</span>
            <a href="{{ route('changelog') }}" class="text-white/60 hover:text-red-400 transition-colors">
                Changelog
            </a>
        </div>
        <div>© {{ date('Y') }} Martingalian - All rights reserved</div>
    </div>
</footer>
