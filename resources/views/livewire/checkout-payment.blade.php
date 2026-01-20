<div>
    <div x-data="paypalPayment">
        <div class="my-6 xl:my-8">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.payment') }}
            </div>
            <div class="text-gray-700">
                {{ trans('statamic-resrv::frontend.paymentDescription') }}
            </div>
        </div>

        <div class="my-6 xl:my-8">
            <!-- PayPal Button Container -->
            <div id="paypal-button-container" class="mb-6"></div>

            <!-- Divider -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-300"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-4 bg-white text-gray-500">{{ __('or pay with card') }}</span>
                </div>
            </div>

            <!-- Card Fields -->
            <form id="card-form" x-on:submit.prevent="submitCard">
                <div class="space-y-4">
                    <!-- Card Number -->
                    <div>
                        <label for="card-number" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Card Number') }}
                        </label>
                        <div id="card-number-field-container" class="h-11 border border-gray-300 rounded-lg px-3 py-2"></div>
                    </div>

                    <!-- Expiry and CVV Row -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="card-expiry" class="block text-sm font-medium text-gray-700 mb-1">
                                {{ __('Expiry Date') }}
                            </label>
                            <div id="card-expiry-field-container" class="h-11 border border-gray-300 rounded-lg px-3 py-2"></div>
                        </div>
                        <div>
                            <label for="card-cvv" class="block text-sm font-medium text-gray-700 mb-1">
                                {{ __('CVV') }}
                            </label>
                            <div id="card-cvv-field-container" class="h-11 border border-gray-300 rounded-lg px-3 py-2"></div>
                        </div>
                    </div>

                    <!-- Cardholder Name -->
                    <div>
                        <label for="card-name" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ __('Cardholder Name') }}
                        </label>
                        <div id="card-name-field-container" class="h-11 border border-gray-300 rounded-lg px-3 py-2"></div>
                    </div>
                </div>

                <div class="mt-6 xl:mt-8">
                    <button
                        type="submit"
                        class="flex items-center justify-center w-full relative px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4
                        focus:outline-none focus:ring-blue-300 rounded-lg text-center disabled:opacity-70 transition-opacity duration-300"
                        x-bind:disabled="loading || !cardFieldsReady"
                    >
                        <span class="py-0.5" x-cloak x-transition x-show="loading === true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="animate-spin w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </span>
                        <span x-transition x-show="loading === false">
                            {{ trans('statamic-resrv::frontend.pay') }}
                            <span class="font-bold">{{ config('resrv-config.currency_symbol') }} {{ $amount }} </span>
                            {{ trans('statamic-resrv::frontend.toCompleteYourReservation') }}
                        </span>
                    </button>
                </div>
            </form>

            <!-- Error Display -->
            <p x-show="errors" x-cloak x-transition class="mt-6 xm:mt-8 text-red-600">
                <span x-html="errors"></span>
            </p>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('paypalPayment', () => ({
    orderId: $wire.clientSecret,
    publicKey: $wire.publicKey,
    checkoutCompletedUrl: $wire.checkoutCompletedUrl,
    loading: false,
    errors: false,
    cardFields: null,
    cardFieldsReady: false,
    paypalLoaded: false,

    async init() {
        await this.loadPayPalSDK();
        this.renderPayPalButton();
        this.renderCardFields();
    },

    loadPayPalSDK() {
        return new Promise((resolve, reject) => {
            if (window.paypal) {
                this.paypalLoaded = true;
                resolve();
                return;
            }

            const script = document.createElement('script');
            const mode = '{{ config("services.paypal.mode") }}';
            const baseUrl = mode === 'live'
                ? 'https://www.paypal.com'
                : 'https://www.sandbox.paypal.com';

            script.src = `${baseUrl}/sdk/js?client-id=${this.publicKey}&components=buttons,card-fields&currency={{ config('resrv-config.currency_isoCode') }}`;
            script.async = true;

            script.onload = () => {
                this.paypalLoaded = true;
                resolve();
            };

            script.onerror = () => {
                this.errors = 'Failed to load PayPal SDK. Please refresh the page and try again.';
                reject(new Error('PayPal SDK failed to load'));
            };

            document.head.appendChild(script);
        });
    },

    renderPayPalButton() {
        if (!window.paypal || !window.paypal.Buttons) {
            console.error('PayPal Buttons not available');
            return;
        }

        paypal.Buttons({
            style: {
                layout: 'vertical',
                color: 'gold',
                shape: 'rect',
                label: 'paypal'
            },
            createOrder: () => {
                return this.orderId;
            },
            onApprove: async (data) => {
                this.loading = true;
                this.errors = false;

                try {
                    await this.captureOrder(data.orderID);
                } catch (error) {
                    this.errors = error.message || 'Payment failed. Please try again.';
                    this.loading = false;
                }
            },
            onCancel: () => {
                this.errors = 'Payment was cancelled. Please try again.';
            },
            onError: (err) => {
                console.error('PayPal Button Error:', err);
                this.errors = 'An error occurred with PayPal. Please try again.';
            }
        }).render('#paypal-button-container');
    },

    renderCardFields() {
        if (!window.paypal || !window.paypal.CardFields) {
            console.error('PayPal CardFields not available');
            return;
        }

        const cardFieldStyle = {
            'input': {
                'font-size': '16px',
                'font-family': 'inherit',
                'color': '#333'
            },
            ':focus': {
                'color': '#333'
            }
        };

        this.cardFields = paypal.CardFields({
            createOrder: () => {
                return this.orderId;
            },
            onApprove: async (data) => {
                this.loading = true;
                this.errors = false;

                try {
                    await this.captureOrder(data.orderID);
                } catch (error) {
                    this.errors = error.message || 'Payment failed. Please try again.';
                    this.loading = false;
                }
            },
            onError: (err) => {
                console.error('CardFields Error:', err);
                this.errors = 'Card payment error. Please check your details and try again.';
                this.loading = false;
            },
            style: cardFieldStyle
        });

        // Check if card fields are eligible (ACDC enabled for merchant)
        if (this.cardFields.isEligible()) {
            // Render individual card fields
            this.cardFields.NumberField().render('#card-number-field-container');
            this.cardFields.ExpiryField().render('#card-expiry-field-container');
            this.cardFields.CVVField().render('#card-cvv-field-container');
            this.cardFields.NameField().render('#card-name-field-container');

            this.cardFieldsReady = true;
        } else {
            // Hide card form if not eligible
            document.getElementById('card-form').style.display = 'none';
            document.querySelector('.relative.my-6').style.display = 'none'; // Hide divider
            console.log('Card fields not eligible for this merchant');
        }
    },

    async submitCard() {
        if (!this.cardFields || !this.cardFieldsReady) {
            this.errors = 'Card payment is not available. Please use PayPal.';
            return;
        }

        this.loading = true;
        this.errors = false;

        try {
            await this.cardFields.submit();
            // onApprove callback will handle the rest
        } catch (error) {
            console.error('Card submit error:', error);
            this.errors = error.message || 'Failed to process card payment. Please try again.';
            this.loading = false;
        }
    },

    async captureOrder(orderID) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const response = await fetch(`/resrv-paypal/capture/${orderID}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        });

        const result = await response.json();

        if (response.ok && result.status === 'COMPLETED') {
            // Redirect to checkout completed page
            window.location.href = this.checkoutCompletedUrl + '?id={{ request()->input("id") }}';
        } else {
            throw new Error(result.error || 'Payment capture failed');
        }
    }
}));
</script>
@endscript
