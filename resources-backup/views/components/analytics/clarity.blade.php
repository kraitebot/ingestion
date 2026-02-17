{{-- resources/views/components/analytics/clarity.blade.php --}}
@props([
    'id',
    // Optional kill-switch; pass :enabled="false" to disable on a page
    'enabled' => true,
])

@if ($enabled)
    {{-- Microsoft Clarity (with CSP nonce) --}}
    <script>
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r); t.async = 1; t.src = "https://www.clarity.ms/tag/" + i;
            y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y);
        })(window, document, "clarity", "script", @json($id));
    </script>
@endif
