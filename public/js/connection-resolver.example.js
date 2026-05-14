/**
 * ConnectionResolver - Usage Examples
 *
 * This file demonstrates how to use the ConnectionResolver in your application
 */

// ============================================
// EXAMPLE 1: Basic Initialization
// ============================================

const resolver = new ConnectionResolver({
    cloudApiUrl: 'https://api.deltapos.cloud',
    checkInterval: 5000, // Check every 5 seconds
    timeout: 3000,       // 3 second timeout
    maxRetries: 3,       // Retry 3 times before going offline
});

// Listen for status changes
resolver.on('online', (state) => {
    console.log('✅ Back online!', state);
    showOnlineNotification();
});

resolver.on('offline', (state) => {
    console.log('❌ Gone offline!', state);
    showOfflineNotification();
});


// ============================================
// EXAMPLE 2: Making API Requests with Fallback
// ============================================

async function fetchProducts() {
    try {
        const products = await resolver.request(
            '/api/products',
            { method: 'GET' },
            // Local fallback if offline
            async () => {
                console.log('Fetching from local database...');
                return await fetch('/api/local/products').then(r => r.json());
            }
        );

        renderProducts(products);
    } catch (error) {
        console.error('Failed to fetch products:', error);
        showError('Could not load products');
    }
}


// ============================================
// EXAMPLE 3: Creating Orders with Auto-Fallback
// ============================================

async function createOrder(orderData) {
    try {
        const result = await resolver.request(
            '/api/orders',
            {
                method: 'POST',
                body: JSON.stringify(orderData),
            },
            // Local fallback: save to IndexedDB/SQLite
            async () => {
                console.log('Saving order locally...');

                // Save to local queue for later sync
                await addToSyncQueue({
                    type: 'create_order',
                    data: orderData,
                    timestamp: Date.now(),
                });

                return {
                    success: true,
                    message: 'Order saved locally (will sync when online)',
                    local: true,
                };
            }
        );

        if (result.local) {
            showNotification('Order saved locally', 'warning');
        } else {
            showNotification('Order created successfully', 'success');
        }

        return result;
    } catch (error) {
        console.error('Failed to create order:', error);
        throw error;
    }
}


// ============================================
// EXAMPLE 4: Syncing Pending Operations
// ============================================

async function syncPendingOperations() {
    // Get pending operations from local queue
    const operations = await getPendingOperations();

    if (operations.length === 0) {
        console.log('No pending operations to sync');
        return;
    }

    console.log(`Syncing ${operations.length} operations...`);

    // Show progress
    const progressBar = document.getElementById('sync-progress');

    try {
        const result = await resolver.syncOperations(
            operations,
            (current, total) => {
                // Update progress bar
                const percentage = (current / total) * 100;
                progressBar.value = percentage;
                progressBar.textContent = `${current}/${total}`;
            }
        );

        if (result.success) {
            console.log('✅ All operations synced successfully');
            clearSyncQueue();
            showNotification('All data synced!', 'success');
        } else {
            console.warn(`⚠️ Synced ${result.synced}/${result.total}, ${result.failed} failed`);
            showNotification(`Partial sync: ${result.synced}/${result.total}`, 'warning');
        }
    } catch (error) {
        console.error('Sync failed:', error);
        showNotification('Sync failed - will retry later', 'error');
    }
}


// ============================================
// EXAMPLE 5: Status Indicator UI Component
// ============================================

function createStatusIndicator() {
    const indicator = document.createElement('div');
    indicator.id = 'connection-status';
    indicator.className = 'status-indicator';

    // Initial state
    updateIndicator(resolver.isOnline());

    // Listen for changes
    resolver.on('statusChange', (isOnline, state) => {
        updateIndicator(isOnline, state);
    });

    document.body.appendChild(indicator);

    function updateIndicator(isOnline, state = {}) {
        if (isOnline) {
            indicator.className = 'status-indicator online';
            indicator.innerHTML = `
                <span class="icon">🟢</span>
                <span class="text">Online</span>
                ${state.syncQueueSize > 0 ? `<span class="badge">${state.syncQueueSize}</span>` : ''}
            `;
        } else {
            indicator.className = 'status-indicator offline';
            indicator.innerHTML = `
                <span class="icon">🔴</span>
                <span class="text">Offline</span>
                ${state.syncQueueSize > 0 ? `<span class="badge">${state.syncQueueSize} pending</span>` : ''}
            `;
        }
    }
}


// ============================================
// EXAMPLE 6: Integration with POS System
// ============================================

class POSSystem {
    constructor() {
        this.resolver = new ConnectionResolver({
            cloudApiUrl: window.EDGE_BOX_CONFIG?.apiUrl || 'http://localhost:8000',
            onStatusChange: (isOnline) => this.handleStatusChange(isOnline),
        });

        this.init();
    }

    init() {
        // Create status indicator
        createStatusIndicator();

        // Start periodic sync when online
        this.resolver.on('online', () => {
            this.startAutoSync();
        });

        this.resolver.on('offline', () => {
            this.stopAutoSync();
        });
    }

    async handleStatusChange(isOnline) {
        if (isOnline) {
            console.log('Connection restored - syncing pending data...');
            await syncPendingOperations();
        } else {
            console.log('Connection lost - switching to offline mode');
            this.enableOfflineMode();
        }
    }

    enableOfflineMode() {
        // Disable features that require cloud
        document.querySelectorAll('.cloud-only').forEach(el => {
            el.disabled = true;
            el.title = 'Not available offline';
        });

        // Enable local-only features
        document.querySelectorAll('.local-feature').forEach(el => {
            el.disabled = false;
        });
    }

    startAutoSync() {
        // Sync every 30 seconds when online
        this.syncInterval = setInterval(() => {
            syncPendingOperations();
        }, 30000);
    }

    stopAutoSync() {
        if (this.syncInterval) {
            clearInterval(this.syncInterval);
        }
    }

    // Product management
    async loadProducts() {
        return await this.resolver.request(
            '/api/products',
            {},
            async () => {
                // Load from local SQLite
                return await this.loadLocalProducts();
            }
        );
    }

    async createPayment(paymentData) {
        return await this.resolver.request(
            '/api/payments',
            {
                method: 'POST',
                body: JSON.stringify(paymentData),
            },
            async () => {
                // Save to local database and queue for sync
                await this.savePaymentLocally(paymentData);
                await this.queueForSync('payment', paymentData);

                return {
                    success: true,
                    local: true,
                    message: 'Payment saved locally',
                };
            }
        );
    }
}


// ============================================
// EXAMPLE 7: HTML Integration
// ============================================

/*
<!DOCTYPE html>
<html>
<head>
    <title>DeltaPOS Edge Box</title>
</head>
<body>
    <!-- Include ConnectionResolver -->
    <script src="/js/connection-resolver.js"></script>

    <!-- Your app script -->
    <script>
        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            const posSystem = new POSSystem();

            // Load products
            posSystem.loadProducts().then(products => {
                renderProductGrid(products);
            });
        });
    </script>
</body>
</html>
*/


// ============================================
// EXAMPLE 8: Error Handling Best Practices
// ============================================

async function robustApiCall() {
    try {
        const data = await resolver.request(
            '/api/customers',
            { method: 'GET' },
            async () => {
                // Fallback: load from cache
                const cached = localStorage.getItem('customers_cache');
                if (cached) {
                    return JSON.parse(cached);
                }
                throw new Error('No cached data available');
            }
        );

        // Cache the successful response
        localStorage.setItem('customers_cache', JSON.stringify(data));

        return data;
    } catch (error) {
        console.error('API call failed:', error);

        // Show user-friendly error
        if (resolver.isOffline()) {
            alert('You are offline. Some features may be limited.');
        } else {
            alert('Network error. Please try again.');
        }

        throw error;
    }
}


// ============================================
// EXAMPLE 9: Cleanup on Page Unload
// ============================================

window.addEventListener('beforeunload', () => {
    // Destroy resolver to cleanup listeners
    if (window.connectionResolver) {
        window.connectionResolver.destroy();
    }
});


// ============================================
// EXAMPLE 10: Testing Connection Resolver
// ============================================

function testConnectionResolver() {
    console.log('Testing ConnectionResolver...');

    const resolver = new ConnectionResolver({
        cloudApiUrl: 'http://localhost:8000',
        checkInterval: 2000,
    });

    // Test 1: Check initial state
    console.assert(typeof resolver.isOnline() === 'boolean', 'Should return boolean');

    // Test 2: Add listeners
    let onlineCalled = false;
    resolver.on('online', () => { onlineCalled = true; });

    // Test 3: Make request
    resolver.request('/health')
        .then(result => {
            console.log('Request succeeded:', result);
        })
        .catch(error => {
            console.log('Request failed (expected if server down):', error.message);
        });

    // Test 4: Get state
    const state = resolver.getState();
    console.log('Current state:', state);

    // Cleanup
    setTimeout(() => {
        resolver.destroy();
        console.log('Test complete');
    }, 5000);
}

// Run test in development
if (window.location.hostname === 'localhost') {
    // testConnectionResolver();
}
