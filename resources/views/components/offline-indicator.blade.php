<!--
    Offline Indicator Component
    Shows connection status banner and sync queue indicator

    Usage: Include in your main layout file
    @include('components.offline-indicator')
-->

<div id="offline-indicator" class="hidden">
    <!-- Offline Banner -->
    <div id="offline-banner" class="fixed top-0 left-0 right-0 z-50 bg-red-600 text-white px-4 py-3 shadow-lg transform transition-transform duration-300 -translate-y-full">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <!-- Warning Icon -->
                <svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>

                <div>
                    <p class="font-semibold text-sm">⚠️ OFFLINE MODE</p>
                    <p class="text-xs opacity-90">Working with local data. Changes will sync when connection is restored.</p>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <!-- Sync Queue Badge -->
                <div id="sync-queue-badge" class="hidden bg-white bg-opacity-20 px-3 py-1 rounded-full">
                    <span class="text-xs font-medium">
                        📦 <span id="pending-sync-count">0</span> pending sync
                    </span>
                </div>

                <!-- Retry Button -->
                <button id="retry-connection-btn" class="bg-white bg-opacity-20 hover:bg-opacity-30 px-3 py-1 rounded text-xs font-medium transition-colors">
                    🔁 Retry Connection
                </button>
            </div>
        </div>
    </div>

    <!-- Online Status Toast (shows briefly when reconnected) -->
    <div id="online-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg transform translate-x-full transition-transform duration-300">
        <div class="flex items-center space-x-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <span class="font-medium">✅ Back Online!</span>
        </div>
        <p class="text-xs mt-1 opacity-90">Syncing pending changes...</p>
    </div>

    <!-- Connection Status Indicator (always visible in header/footer) -->
    <div id="connection-status-indicator" class="fixed bottom-4 right-4 z-40">
        <div class="bg-white dark:bg-gray-800 rounded-full shadow-lg px-4 py-2 flex items-center space-x-2 border border-gray-200 dark:border-gray-700">
            <!-- Status Dot -->
            <div id="status-dot" class="w-3 h-3 rounded-full bg-green-500"></div>

            <!-- Status Text -->
            <span id="status-text" class="text-xs font-medium text-gray-700 dark:text-gray-300">Online</span>

            <!-- Sync Count (when offline) -->
            <span id="mini-sync-count" class="hidden bg-red-500 text-white text-xs px-2 py-0.5 rounded-full font-bold">0</span>
        </div>
    </div>
</div>

<style>
    /* Animation for offline banner slide-in */
    #offline-banner.show {
        transform: translateY(0);
    }

    /* Animation for online toast slide-in/out */
    #online-toast.show {
        transform: translateX(0);
    }

    /* Pulse animation for status dot */
    @keyframes pulse-green {
        0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
        50% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
    }

    @keyframes pulse-red {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
    }

    .status-online {
        animation: pulse-green 2s infinite;
    }

    .status-offline {
        animation: pulse-red 2s infinite;
    }
</style>

<script>
/**
 * Initialize Offline Indicator
 * Integrates with ConnectionResolver to show/hide offline indicators
 */
(function() {
    'use strict';

    // DOM Elements
    const elements = {
        offlineBanner: document.getElementById('offline-banner'),
        onlineToast: document.getElementById('online-toast'),
        statusDot: document.getElementById('status-dot'),
        statusText: document.getElementById('status-text'),
        syncQueueBadge: document.getElementById('sync-queue-badge'),
        pendingSyncCount: document.getElementById('pending-sync-count'),
        miniSyncCount: document.getElementById('mini-sync-count'),
        retryButton: document.getElementById('retry-connection-btn'),
    };

    let connectionResolver = null;
    let onlineToastTimeout = null;

    /**
     * Show offline banner
     */
    function showOfflineBanner() {
        if (elements.offlineBanner) {
            elements.offlineBanner.classList.remove('-translate-y-full');
            elements.offlineBanner.classList.add('show');
        }

        updateStatusIndicator(false);
    }

    /**
     * Hide offline banner
     */
    function hideOfflineBanner() {
        if (elements.offlineBanner) {
            elements.offlineBanner.classList.add('-translate-y-full');
            elements.offlineBanner.classList.remove('show');
        }
    }

    /**
     * Show online toast notification
     */
    function showOnlineToast() {
        if (elements.onlineToast) {
            elements.onlineToast.classList.add('show');

            // Hide after 5 seconds
            if (onlineToastTimeout) {
                clearTimeout(onlineToastTimeout);
            }

            onlineToastTimeout = setTimeout(() => {
                elements.onlineToast.classList.remove('show');
            }, 5000);
        }
    }

    /**
     * Update status indicator (bottom-right corner)
     */
    function updateStatusIndicator(isOnline) {
        if (!elements.statusDot || !elements.statusText) return;

        if (isOnline) {
            elements.statusDot.className = 'w-3 h-3 rounded-full bg-green-500 status-online';
            elements.statusText.textContent = 'Online';
            elements.statusText.className = 'text-xs font-medium text-gray-700 dark:text-gray-300';
        } else {
            elements.statusDot.className = 'w-3 h-3 rounded-full bg-red-500 status-offline';
            elements.statusText.textContent = 'Offline';
            elements.statusText.className = 'text-xs font-medium text-red-600 dark:text-red-400';
        }
    }

    /**
     * Update sync queue count display
     */
    function updateSyncQueueCount(count) {
        if (count > 0) {
            // Show badges
            if (elements.syncQueueBadge) {
                elements.syncQueueBadge.classList.remove('hidden');
            }
            if (elements.miniSyncCount) {
                elements.miniSyncCount.classList.remove('hidden');
                elements.miniSyncCount.textContent = count;
            }
            if (elements.pendingSyncCount) {
                elements.pendingSyncCount.textContent = count;
            }
        } else {
            // Hide badges
            if (elements.syncQueueBadge) {
                elements.syncQueueBadge.classList.add('hidden');
            }
            if (elements.miniSyncCount) {
                elements.miniSyncCount.classList.add('hidden');
            }
        }
    }

    /**
     * Handle connection status change
     */
    function handleStatusChange(isOnline, state) {
        console.log('[OfflineIndicator] Status changed:', isOnline ? 'ONLINE' : 'OFFLINE', state);

        if (isOnline) {
            hideOfflineBanner();
            showOnlineToast();
        } else {
            showOfflineBanner();
        }

        updateStatusIndicator(isOnline);
    }

    /**
     * Initialize with ConnectionResolver
     */
    function init() {
        // Check if ConnectionResolver is available
        if (typeof window.ConnectionResolver === 'undefined') {
            console.warn('[OfflineIndicator] ConnectionResolver not loaded. Using browser events only.');

            // Fallback to browser events
            window.addEventListener('online', () => {
                handleStatusChange(true, { isOnline: true });
            });

            window.addEventListener('offline', () => {
                handleStatusChange(false, { isOnline: false });
            });

            // Set initial state
            handleStatusChange(navigator.onLine, { isOnline: navigator.onLine });
            return;
        }

        // Initialize ConnectionResolver
        connectionResolver = new window.ConnectionResolver({
            cloudApiUrl: '{{ env("CLOUD_API_URL", "https://api.deltapos.cloud") }}',
            checkInterval: 5000,
            timeout: 3000,
            maxRetries: 3,
            onStatusChange: handleStatusChange,
        });

        // Listen to connection events
        connectionResolver.on('online', (state) => {
            console.log('[OfflineIndicator] Event: online', state);
            hideOfflineBanner();
            showOnlineToast();
            updateStatusIndicator(true);
        });

        connectionResolver.on('offline', (state) => {
            console.log('[OfflineIndicator] Event: offline', state);
            showOfflineBanner();
            updateStatusIndicator(false);
        });

        // Set initial state
        handleStatusChange(connectionResolver.isOnline(), connectionResolver.getState());

        // Make connectionResolver globally accessible
        window.connectionResolver = connectionResolver;
    }

    /**
     * Retry connection button handler
     */
    if (elements.retryButton) {
        elements.retryButton.addEventListener('click', async () => {
            console.log('[OfflineIndicator] Manual connection retry...');

            if (connectionResolver) {
                const isOnline = await connectionResolver.checkConnectivity();
                console.log('[OfflineIndicator] Retry result:', isOnline ? 'ONLINE' : 'OFFLINE');
            } else {
                // Fallback: just check browser state
                handleStatusChange(navigator.onLine, { isOnline: navigator.onLine });
            }
        });
    }

    /**
     * Public API for updating sync queue size
     */
    window.updateSyncQueueSize = function(count) {
        updateSyncQueueCount(count);

        if (connectionResolver) {
            connectionResolver.updateSyncQueueSize(count);
        }
    };

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
