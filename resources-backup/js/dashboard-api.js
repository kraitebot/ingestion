/**
 * Dashboard API Client
 *
 * Handles fetching dashboard data from the API endpoint.
 */

/**
 * Fetch dashboard data (global stats + positions)
 *
 * @returns {Promise<Object>} Dashboard data
 * @throws {Error} If request fails or user is not authenticated
 */
export async function fetchDashboardData() {
    try {
        const response = await fetch('/api/dashboard/data', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin', // Include cookies for auth
        });

        if (response.status === 401) {
            // Unauthorized - redirect to login
            window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname);
            throw new Error('Unauthorized');
        }

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Failed to fetch dashboard data');
        }

        return data.data; // Returns { global_stats, positions: { long: [], short: [] } }
    } catch (error) {
        console.error('Dashboard API Error:', error);
        throw error;
    }
}

/**
 * Poll dashboard data at regular intervals
 *
 * @param {Function} callback Function to call with updated data
 * @param {number} intervalMs Polling interval in milliseconds (default: 30000 = 30 seconds)
 * @returns {Function} Cleanup function to stop polling
 */
export function pollDashboardData(callback, intervalMs = 30000) {
    // Initial fetch
    fetchDashboardData()
        .then(callback)
        .catch(error => console.error('Initial dashboard fetch failed:', error));

    // Set up polling
    const intervalId = setInterval(() => {
        fetchDashboardData()
            .then(callback)
            .catch(error => console.error('Dashboard polling failed:', error));
    }, intervalMs);

    // Return cleanup function
    return () => clearInterval(intervalId);
}
