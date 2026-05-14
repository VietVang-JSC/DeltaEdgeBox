@extends('layouts.pos')

@section('title', 'POS - DeltaPOS Edge Box')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Left Column: Products Grid -->
    <div class="lg:col-span-2">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Products</h2>

                <!-- Category Filter -->
                <select id="category-filter" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    <option value="">All Categories</option>
                    <option value="coffee">Cà phê</option>
                    <option value="tea">Trà</option>
                    <option value="smoothie">Sinh tố</option>
                    <option value="juice">Nước ép</option>
                </select>
            </div>

            <!-- Products Grid -->
            <div id="products-grid" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <!-- Sample Product Cards -->
                @for($i = 1; $i <= 9; $i++)
                <div class="product-card bg-gray-50 dark:bg-gray-700 rounded-lg p-4 cursor-pointer hover:shadow-md transition-shadow border-2 border-transparent hover:border-red-500">
                    <div class="aspect-square bg-gray-200 dark:bg-gray-600 rounded-lg mb-3 flex items-center justify-center">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <h3 class="font-medium text-gray-900 dark:text-white text-sm mb-1">Product {{ $i }}</h3>
                    <p class="text-red-600 font-semibold">{{ number_format(25000 + ($i * 5000), 0, ',', '.') }}đ</p>
                </div>
                @endfor
            </div>

            <!-- Load More Button -->
            <div class="mt-6 text-center">
                <button id="load-more-btn" class="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-6 py-2 rounded-lg text-sm font-medium transition-colors">
                    Load More Products
                </button>
            </div>
        </div>
    </div>

    <!-- Right Column: Cart/Order Summary -->
    <div class="lg:col-span-1">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 sticky top-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Current Order</h2>

            <!-- Empty Cart Message -->
            <div id="empty-cart" class="text-center py-8">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">Cart is empty</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Click products to add them</p>
            </div>

            <!-- Cart Items (hidden by default) -->
            <div id="cart-items" class="hidden space-y-3 mb-4 max-h-96 overflow-y-auto">
                <!-- Cart item example -->
                <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex-1">
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">Cà phê sữa đá</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400">30,000đ x 1</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button class="w-6 h-6 bg-gray-200 dark:bg-gray-600 rounded flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-500">-</button>
                        <span class="text-sm font-medium text-gray-900 dark:text-white w-6 text-center">1</span>
                        <button class="w-6 h-6 bg-gray-200 dark:bg-gray-600 rounded flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-500">+</button>
                    </div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white ml-3">30,000đ</p>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Subtotal:</span>
                    <span class="font-medium text-gray-900 dark:text-white">0đ</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Tax (10%):</span>
                    <span class="font-medium text-gray-900 dark:text-white">0đ</span>
                </div>
                <div class="flex justify-between text-lg font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
                    <span class="text-gray-900 dark:text-white">Total:</span>
                    <span class="text-red-600">0đ</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 space-y-3">
                <button id="checkout-btn" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Checkout
                </button>

                <button id="clear-cart-btn" class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Clear Cart
                </button>
            </div>

            <!-- Offline Mode Notice -->
            <div id="offline-notice" class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg hidden">
                <p class="text-xs text-yellow-800 dark:text-yellow-200">
                    ⚠️ You're in offline mode. Orders will be saved locally and synced when connection is restored.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
/**
 * Sample POS Page JavaScript
 * Demonstrates integration with ConnectionResolver and offline indicators
 */
(function() {
    'use strict';

    // State
    let cart = [];
    let isOffline = false;

    // DOM Elements
    const elements = {
        emptyCart: document.getElementById('empty-cart'),
        cartItems: document.getElementById('cart-items'),
        checkoutBtn: document.getElementById('checkout-btn'),
        clearCartBtn: document.getElementById('clear-cart-btn'),
        offlineNotice: document.getElementById('offline-notice'),
        productsGrid: document.getElementById('products-grid'),
    };

    /**
     * Add product to cart
     */
    function addToCart(product) {
        console.log('[POS] Adding to cart:', product);

        // If offline, save to local storage first
        if (isOffline) {
            saveToLocalQueue({
                type: 'add_to_cart',
                data: product,
                timestamp: new Date().toISOString()
            });
        }

        cart.push(product);
        updateCartUI();
    }

    /**
     * Update cart UI
     */
    function updateCartUI() {
        if (cart.length === 0) {
            elements.emptyCart.classList.remove('hidden');
            elements.cartItems.classList.add('hidden');
            elements.checkoutBtn.disabled = true;
            elements.clearCartBtn.disabled = true;
        } else {
            elements.emptyCart.classList.add('hidden');
            elements.cartItems.classList.remove('hidden');
            elements.checkoutBtn.disabled = false;
            elements.clearCartBtn.disabled = false;
        }

        // TODO: Render cart items
        // For demo, just showing/hiding sections
    }

    /**
     * Save operation to local queue (for offline mode)
     */
    function saveToLocalQueue(operation) {
        try {
            let queue = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');
            queue.push(operation);
            localStorage.setItem('pos_offline_queue', JSON.stringify(queue));

            // Update sync queue indicator
            if (typeof window.updateSyncQueueSize === 'function') {
                window.updateSyncQueueSize(queue.length);
            }

            console.log('[POS] Saved to offline queue:', operation);
        } catch (error) {
            console.error('[POS] Failed to save to offline queue:', error);
        }
    }

    /**
     * Handle connection status change
     */
    function handleConnectionChange(isOnline) {
        isOffline = !isOnline;

        if (isOffline) {
            elements.offlineNotice.classList.remove('hidden');
            console.log('[POS] Switched to offline mode');
        } else {
            elements.offlineNotice.classList.add('hidden');
            console.log('[POS] Back online - syncing pending operations...');

            // Sync pending operations
            syncPendingOperations();
        }
    }

    /**
     * Sync pending operations from local queue
     */
    async function syncPendingOperations() {
        try {
            const queue = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');

            if (queue.length === 0) {
                console.log('[POS] No pending operations to sync');
                return;
            }

            console.log(`[POS] Syncing ${queue.length} pending operations...`);

            // TODO: Send operations to cloud API
            // For demo, just clear the queue
            localStorage.removeItem('pos_offline_queue');

            if (typeof window.updateSyncQueueSize === 'function') {
                window.updateSyncQueueSize(0);
            }

            console.log('[POS] Sync completed successfully');
        } catch (error) {
            console.error('[POS] Sync failed:', error);
        }
    }

    /**
     * Initialize event listeners
     */
    function initEventListeners() {
        // Product card clicks
        if (elements.productsGrid) {
            elements.productsGrid.addEventListener('click', (e) => {
                const card = e.target.closest('.product-card');
                if (card) {
                    // Simulate adding product
                    addToCart({
                        id: Date.now(),
                        name: 'Sample Product',
                        price: 30000,
                        quantity: 1
                    });
                }
            });
        }

        // Clear cart button
        if (elements.clearCartBtn) {
            elements.clearCartBtn.addEventListener('click', () => {
                cart = [];
                updateCartUI();
            });
        }

        // Checkout button
        if (elements.checkoutBtn) {
            elements.checkoutBtn.addEventListener('click', async () => {
                console.log('[POS] Processing checkout...');

                if (isOffline) {
                    // Save order to local queue
                    saveToLocalQueue({
                        type: 'create_order',
                        data: { cart, total: 0 },
                        timestamp: new Date().toISOString()
                    });

                    alert('Order saved locally! It will sync when you\'re back online.');
                } else {
                    // Process normally via API
                    alert('Processing order...');
                }
            });
        }
    }

    /**
     * Initialize page
     */
    function init() {
        console.log('[POS] Initializing POS page...');

        // Initialize event listeners
        initEventListeners();

        // Listen for connection status changes
        if (window.connectionResolver) {
            window.connectionResolver.on('statusChange', (isOnline, state) => {
                handleConnectionChange(isOnline);
            });

            // Set initial state
            handleConnectionChange(window.connectionResolver.isOnline());
        } else {
            // Fallback to browser events
            window.addEventListener('online', () => handleConnectionChange(true));
            window.addEventListener('offline', () => handleConnectionChange(false));
            handleConnectionChange(navigator.onLine);
        }

        // Check for pending operations on load
        const pendingOps = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');
        if (pendingOps.length > 0 && typeof window.updateSyncQueueSize === 'function') {
            window.updateSyncQueueSize(pendingOps.length);
        }

        console.log('[POS] POS page initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endpush
