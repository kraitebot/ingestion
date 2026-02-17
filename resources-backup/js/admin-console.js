document.addEventListener('alpine:init', () => {
    Alpine.data('consoleApp', () => ({
        selectedUserId: '',
        selectedAccountId: '',
        selectedMethod: '',
        selectedApiSystemId: null,
        accounts: [],
        apiMethods: {},
        methodParameters: [],
        exchangeSymbols: [],
        params: {},
        response: null,
        loading: false,

        async loadAccounts() {
            if (!this.selectedUserId) {
                this.accounts = [];
                this.selectedAccountId = '';
                this.apiMethods = {};
                this.selectedMethod = '';
                return;
            }

            try {
                const response = await fetch(`/admin/accounts/${this.selectedUserId}`);
                this.accounts = await response.json();
                this.selectedAccountId = '';
                this.apiMethods = {};
                this.selectedMethod = '';
            } catch (error) {
                console.error('Failed to load accounts:', error);
                if (typeof window.showToast === 'function') {
                    window.showToast('Failed to load accounts', 'error');
                }
            }
        },

        async loadApiMethods() {
            if (!this.selectedAccountId) {
                this.apiMethods = {};
                this.selectedMethod = '';
                this.exchangeSymbols = [];
                return;
            }

            // Get API system ID from selected account
            const account = this.accounts.find(a => a.id == this.selectedAccountId);
            this.selectedApiSystemId = account?.api_system?.id;

            try {
                // Load API methods
                const methodsResponse = await fetch(`/admin/api-methods/${this.selectedAccountId}`);
                this.apiMethods = await methodsResponse.json();

                // Load exchange symbols for this API system
                if (this.selectedApiSystemId) {
                    const symbolsResponse = await fetch(`/admin/exchange-symbols/${this.selectedApiSystemId}`);
                    this.exchangeSymbols = await symbolsResponse.json();
                }

                this.selectedMethod = '';
                this.methodParameters = [];
                this.params = {};
            } catch (error) {
                console.error('Failed to load API methods:', error);
                if (typeof window.showToast === 'function') {
                    window.showToast('Failed to load API methods', 'error');
                }
            }
        },

        loadMethodParameters() {
            if (!this.selectedMethod || !this.apiMethods[this.selectedMethod]) {
                this.methodParameters = [];
                this.params = {};
                return;
            }

            const parameters = this.apiMethods[this.selectedMethod].parameters || {};
            this.methodParameters = parameters;

            // Initialize params object with empty values
            this.params = {};
            Object.keys(parameters).forEach(key => {
                this.params[key] = '';
            });
        },

        async executeApiCall() {
            if (!this.selectedMethod || this.loading) {
                return;
            }

            this.loading = true;
            this.response = null;

            try {
                const response = await fetch('/admin/execute-api', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        account_id: this.selectedAccountId,
                        method: this.selectedMethod,
                        parameters: this.params
                    })
                });

                let data;
                try {
                    data = await response.json();
                } catch (parseError) {
                    throw new Error('Invalid JSON response from server');
                }

                this.response = data;

                if (data.success && typeof window.showToast === 'function') {
                    window.showToast('API call executed successfully', 'success');
                } else if (!data.success && typeof window.showToast === 'function') {
                    window.showToast(data.error || 'API call failed', 'error');
                }
            } catch (error) {
                console.error('API call failed:', error);
                this.response = {
                    success: false,
                    error: error.message
                };

                if (typeof window.showToast === 'function') {
                    window.showToast(error.message || 'API call failed', 'error');
                }
            } finally {
                this.loading = false;
            }
        }
    }));
});
