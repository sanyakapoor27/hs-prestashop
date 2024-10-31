// Initialize Hyperswitch on document ready
document.addEventListener('DOMContentLoaded', function() {
    // Get configuration from PrestaShop
    const publishableKey = hyperswitch_public_key;
    const testMode = hyperswitch_test_mode;
    const merchantId = hyperswitch_merchant_id;

    // Initialize Hyperswitch
    const hyperswitch = new Hyperswitch({
        publishableKey: publishableKey,
        merchantId: merchantId,
        testMode: testMode
    });

    // Handle payment form submission
    const paymentForm = document.getElementById('payment-form');
    if (paymentForm) {
        paymentForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            try {
                // Show loading state
                const submitButton = paymentForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = 'Processing...';
                }

                // Get payment method
                const paymentMethodId = document.querySelector('input[name="payment-method"]:checked').value;

                // Create payment
                const response = await hyperswitch.createPayment({
                    amount: window.hyperswitchCartAmount, // This should be set by PrestaShop
                    currency: window.hyperswitchCurrency,
                    paymentMethod: paymentMethodId,
                    metadata: {
                        cartId: window.hyperswitchCartId
                    }
                });

                if (response.error) {
                    throw new Error(response.error.message);
                }

                // Handle successful payment
                if (response.status === 'succeeded') {
                    window.location.href = window.hyperswitchSuccessUrl;
                } else {
                    window.location.href = window.hyperswitchFailureUrl;
                }

            } catch (error) {
                // Handle errors
                const errorDiv = document.getElementById('payment-errors');
                if (errorDiv) {
                    errorDiv.textContent = error.message;
                    errorDiv.style.display = 'block';
                }

                // Reset submit button
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Pay Now';
                }
            }
        });
    }

    // Initialize payment methods display
    hyperswitch.listPaymentMethods().then(methods => {
        const container = document.getElementById('hyperswitch-payment-methods');
        if (container) {
            methods.forEach(method => {
                const methodElement = document.createElement('div');
                methodElement.className = 'payment-method-option';
                methodElement.innerHTML = `
                    <input type="radio" name="payment-method" value="${method.id}" id="${method.id}">
                    <label for="${method.id}">${method.name}</label>
                `;
                container.appendChild(methodElement);
            });
        }
    });
});