/**
 * ConnectionResolver - Manages network connectivity detection and fallback logic
 *
 * This script detects when the Edge Box is online/offline and automatically
 * switches between cloud API and local database operations.
 *
 * Usage: Include this script in your HTML pages that need offline support
 */

class ConnectionResolver {
    constructor(options = {}) {
        // Configuration
        this.config = {
            cloudApiUrl: options.cloudApiUrl || 'https://api.deltapos.cloud',
            checkInterval: options.checkInterval || 5000, // Check every 5 seconds
            timeout: options.timeout || 3000, // 3 second timeout for requests
            maxRetries: options.maxRetries || 3,
            retryDelay: options.retryDelay || 2000,
            onStatusChange: options.onStatusChange || null, // Callback when status changes
        };

        // State
        this.state = {
            isOnline: navigator.onLine,
            lastCheck: null,
            consecutiveFailures: 0,
            syncQueueSize: 0,
        };

        // Event listeners
        this.listeners = {
            online: [],
            offline: [],
            statusChange: [],
        };

        // Initialize
        this._init();
    }

    /**
     * Initialize connection resolver
     * @private
     */
    _init() {
        // Listen to browser online/offline events
        window.addEventListener('online', () => this._handleOnline());
        window.addEventListener('offline', () => this._handleOffline());

        // Start periodic checking
        this._startPeriodicCheck();

        console.log('[ConnectionResolver] Initialized', {
            isOnline: this.state.isOnline,
            cloudApiUrl: this.config.cloudApiUrl,
        });
    }

    /**
     * Start periodic connectivity check
     * @private
     */
    _startPeriodicCheck() {
        setInterval(() => {
            this.checkConnectivity();
        }, this.config.checkInterval);
    }

    /**
     * Check if Edge Box can reach cloud API
     * @returns {Promise<boolean>}
     */
    async checkConnectivity() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.config.timeout);

            const response = await fetch(`${this.config.cloudApiUrl}/health`, {
                method: 'GET',
                signal: controller.signal,
                headers: {
                    'Accept': 'application/json',
                },
            });

            clearTimeout(timeoutId);

            if (response.ok) {
                this._setOnline();
                return true;
            } else {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (error) {
            this._handleConnectionFailure(error);
            return false;
        }
    }

    /**
     * Make an API request with automatic fallback
     *
     * @param {string} endpoint - API endpoint (e.g., '/api/products')
     * @param {Object} options - Fetch options
     * @param {Function} localFallback - Function to call if offline (should return Promise)
     * @returns {Promise<Object>} Response data
     */
    async request(endpoint, options = {}, localFallback = null) {
        const url = `${this.config.cloudApiUrl}${endpoint}`;

        // If offline, use local fallback immediately
        if (!this.state.isOnline) {
            console.log('[ConnectionResolver] Offline - using local fallback');
            if (localFallback) {
                return await localFallback();
            } else {
                throw new Error('Offline and no local fallback provided');
            }
        }

        // Try cloud API first
        let lastError = null;
        for (let attempt = 1; attempt <= this.config.maxRetries; attempt++) {
            try {
                console.log(`[ConnectionResolver] Attempt ${attempt}/${this.config.maxRetries}: ${endpoint}`);

                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), this.config.timeout);

                const response = await fetch(url, {
                    ...options,
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        ...options.headers,
                    },
                });

                clearTimeout(timeoutId);

                if (response.ok) {
                    this._setOnline();
                    return await response.json();
                } else {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
            } catch (error) {
                lastError = error;
                console.warn(`[ConnectionResolver] Attempt ${attempt} failed:`, error.message);

                // Wait before retry (exponential backoff)
                if (attempt < this.config.maxRetries) {
                    await this._delay(this.config.retryDelay * attempt);
                }
            }
        }

        // All retries failed - switch to offline mode
        console.error('[ConnectionResolver] All attempts failed, switching to offline mode');
        this._handleConnectionFailure(lastError);

        // Use local fallback if available
        if (localFallback) {
            console.log('[ConnectionResolver] Using local fallback');
            return await localFallback();
        } else {
            throw new Error(`Failed to reach cloud API after ${this.config.maxRetries} attempts: ${lastError.message}`);
        }
    }

    /**
     * Sync pending operations to cloud
     *
     * @param {Array} operations - Array of operations to sync
     * @param {Function} onProgress - Progress callback (current, total)
     * @returns {Promise<Object>} Sync result
     */
    async syncOperations(operations, onProgress = null) {
        if (!this.state.isOnline) {
            console.warn('[ConnectionResolver] Cannot sync - offline');
            return { success: false, message: 'Offline' };
        }

        const total = operations.length;
        let synced = 0;
        const results = [];

        for (const operation of operations) {
            try {
                const result = await this.request(`/api/sync/operations`, {
                    method: 'POST',
                    body: JSON.stringify(operation),
                });

                results.push({ ...operation, synced: true, result });
                synced++;

                if (onProgress) {
                    onProgress(synced, total);
                }
            } catch (error) {
                console.error('[ConnectionResolver] Failed to sync operation:', error);
                results.push({ ...operation, synced: false, error: error.message });
            }
        }

        return {
            success: synced === total,
            total,
            synced,
            failed: total - synced,
            results,
        };
    }

    /**
     * Handle browser online event
     * @private
     */
    _handleOnline() {
        console.log('[ConnectionResolver] Browser detected online');
        this._setOnline();
    }

    /**
     * Handle browser offline event
     * @private
     */
    _handleOffline() {
        console.log('[ConnectionResolver] Browser detected offline');
        this._setOffline();
    }

    /**
     * Handle connection failure
     * @private
     */
    _handleConnectionFailure(error) {
        this.state.consecutiveFailures++;
        console.warn(`[ConnectionResolver] Connection failure #${this.state.consecutiveFailures}:`, error.message);

        // If multiple consecutive failures, mark as offline
        if (this.state.consecutiveFailures >= 3) {
            this._setOffline();
        }
    }

    /**
     * Set state to online
     * @private
     */
    _setOnline() {
        const wasOffline = !this.state.isOnline;

        this.state.isOnline = true;
        this.state.lastCheck = new Date();
        this.state.consecutiveFailures = 0;

        if (wasOffline) {
            console.log('[ConnectionResolver] Status changed: OFFLINE → ONLINE');
            this._notifyListeners('online');
            this._notifyStatusChange(true);
        }
    }

    /**
     * Set state to offline
     * @private
     */
    _setOffline() {
        const wasOnline = this.state.isOnline;

        this.state.isOnline = false;
        this.state.lastCheck = new Date();

        if (wasOnline) {
            console.log('[ConnectionResolver] Status changed: ONLINE → OFFLINE');
            this._notifyListeners('offline');
            this._notifyStatusChange(false);
        }
    }

    /**
     * Notify event listeners
     * @private
     */
    _notifyListeners(eventType) {
        if (this.listeners[eventType]) {
            this.listeners[eventType].forEach(callback => {
                try {
                    callback(this.state);
                } catch (error) {
                    console.error('[ConnectionResolver] Listener error:', error);
                }
            });
        }
    }

    /**
     * Notify status change callback
     * @private
     */
    _notifyStatusChange(isOnline) {
        if (this.config.onStatusChange) {
            try {
                this.config.onStatusChange(isOnline, this.state);
            } catch (error) {
                console.error('[ConnectionResolver] Status change callback error:', error);
            }
        }
    }

    /**
     * Add event listener
     *
     * @param {string} event - Event type ('online', 'offline', 'statusChange')
     * @param {Function} callback - Callback function
     */
    on(event, callback) {
        if (this.listeners[event]) {
            this.listeners[event].push(callback);
        }
    }

    /**
     * Remove event listener
     *
     * @param {string} event - Event type
     * @param {Function} callback - Callback function to remove
     */
    off(event, callback) {
        if (this.listeners[event]) {
            this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
        }
    }

    /**
     * Get current connection state
     * @returns {Object} Current state
     */
    getState() {
        return { ...this.state };
    }

    /**
     * Check if currently online
     * @returns {boolean}
     */
    isOnline() {
        return this.state.isOnline;
    }

    /**
     * Check if currently offline
     * @returns {boolean}
     */
    isOffline() {
        return !this.state.isOnline;
    }

    /**
     * Update sync queue size
     * @param {number} size - Number of pending operations
     */
    updateSyncQueueSize(size) {
        this.state.syncQueueSize = size;
    }

    /**
     * Delay utility
     * @private
     */
    _delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Destroy connection resolver and cleanup
     */
    destroy() {
        // Remove event listeners
        window.removeEventListener('online', this._handleOnline.bind(this));
        window.removeEventListener('offline', this._handleOffline.bind(this));

        // Clear listeners
        this.listeners = {
            online: [],
            offline: [],
            statusChange: [],
        };

        console.log('[ConnectionResolver] Destroyed');
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ConnectionResolver;
}

// Make available globally for browser usage
if (typeof window !== 'undefined') {
    window.ConnectionResolver = ConnectionResolver;
}
