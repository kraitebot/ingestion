{{-- resources/views/sections/forms/early-access/early-access-form.blade.php --}}
<div class="rounded-xl border border-slate-200/60 dark:border-white/10 bg-white dark:bg-white/5 p-5 md:p-6 leading-relaxed shadow-sm dark:shadow-none"
     x-data="{
         submitted: {{ session('success') ? 'true' : 'false' }},
         loading: false,
         email: '{{ old('email') }}',
         error: '{{ $errors->first('email') }}',
         async submitForm() {
             this.loading = true;
             this.error = '';

             const form = this.$refs.form;
             const formData = new FormData(form);

             try {
                 const response = await fetch('{{ route('early-access.store') }}', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']').content,
                         'Accept': 'application/json',
                         'X-Requested-With': 'XMLHttpRequest'
                     },
                     body: formData
                 });

                 const data = await response.json();

                 if (response.ok) {
                     // Success
                     this.submitted = true;
                     this.email = '';
                 } else if (response.status === 422) {
                     // Validation error
                     if (data.errors && data.errors.email) {
                         this.error = data.errors.email[0];
                     } else if (data.message) {
                         this.error = data.message;
                     }
                 } else {
                     // Other error
                     this.error = data.message || 'Something went wrong. Please try again.';
                 }
             } catch (error) {
                 console.error('Error:', error);
                 this.error = 'Failed to submit. Please check your connection.';
             } finally {
                 this.loading = false;
             }
         }
     }">
    <div class="text-sm font-semibold text-slate-900 dark:text-white">Pre-register for a special discount on launch date</div>
    <p class="mt-1 text-xs text-slate-500 dark:text-white/60">We only accept 50 users for early-access. 16 users already registered, 34 available</p>

    <div x-show="submitted" x-cloak class="mt-4">
        <p class="text-red-500 dark:text-red-300 text-sm font-medium">
            {{ session('success') ?: 'You have successfully pre-registered for early access! Check your email (and spam folder if needed).' }}
        </p>
    </div>

    <form x-show="!submitted"
          x-ref="form"
          @submit.prevent="submitForm"
          class="mt-3 flex flex-col gap-2.5"
          novalidate>
        @csrf
        <x-honeypot />

        <label class="sr-only" for="pre_email">Email</label>

        <div class="flex flex-col sm:flex-row gap-2.5 w-full">
            <div class="flex-1">
                {{-- Email input with Alpine binding --}}
                <div class="space-y-2 [&_svg]:stroke-current [&_svg]:text-current">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 [&_svg]:h-4 [&_svg]:w-4"
                              :class="error ? 'text-red-400' : 'text-white/60'">
                            <x-feathericon-mail class="h-4 w-4"/>
                        </span>

                        <input
                            id="pre_email"
                            name="email"
                            type="email"
                            placeholder="you@domain.com"
                            autocomplete="email"
                            x-model="email"
                            :disabled="loading"
                            :class="error ? 'border-red-400 ring-2 ring-red-400/30 focus:border-red-400 focus:ring-red-400/40' : 'border-white/10 focus:ring-2 focus:ring-white/10 focus:border-white/20'"
                            class="w-full rounded-lg bg-white/5 text-white placeholder-white/40 outline-none transition border py-0 h-12 text-base pl-10 pr-10"
                        />
                    </div>

                    {{-- Error message --}}
                    <p x-show="error" x-text="error" x-cloak class="mt-1 text-xs text-red-400" role="alert"></p>
                </div>
            </div>

            {{-- Submit button --}}
            <button
                type="submit"
                :disabled="loading"
                :class="loading ? 'opacity-60 cursor-wait' : 'cursor-pointer'"
                class="inline-flex items-center justify-center gap-2 h-12 px-5 font-semibold w-full sm:w-auto rounded-lg bg-red-400/10 border border-red-400/30 text-red-300 hover:bg-red-400/20 transition-colors disabled:opacity-60"
            >
                <x-feathericon-percent class="h-4 w-4" x-show="!loading"/>
                <svg x-show="loading" x-cloak class="animate-spin h-4 w-4 text-red-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="loading ? 'Submitting...' : 'Get Discount'">Get Discount</span>
            </button>
        </div>
    </form>

    <div x-show="!submitted" class="mt-2 text-[11px] text-slate-400 dark:text-white/50">No spam — You just hear again from us when we launch</div>
</div>
