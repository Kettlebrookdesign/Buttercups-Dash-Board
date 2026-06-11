const API_ROOT = window.bcDashboard ? window.bcDashboard.root : '';
const NONCE = window.bcDashboard ? window.bcDashboard.nonce : '';

// Direct named exports for cleaner component imports
export const getSummary = async (date) => {
    const response = await fetch(`${API_ROOT}/summary?date=${encodeURIComponent(date)}`, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const getSlotDetail = async (id) => {
    // Handle array of IDs (for grouped slots) or single ID
    const idParam = Array.isArray(id) ? id.join(',') : id;
    const url = `${API_ROOT}/slot-detail?appointment_id=${encodeURIComponent(idParam)}`;
    console.log("api.js: getSlotDetail charging URL:", url);
    const response = await fetch(url, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const search = async (query, date = '') => {
    const response = await fetch(`${API_ROOT}/search?query=${encodeURIComponent(query)}&date=${encodeURIComponent(date)}`, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const getBookingDetail = async (id) => {
    const response = await fetch(`${API_ROOT}/booking-detail?id=${encodeURIComponent(id)}`, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const getManualBookingOptions = async (date) => {
    const response = await fetch(`${API_ROOT}/manual-booking/options?date=${encodeURIComponent(date)}`, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const getManualBookingAvailability = async (appointmentId) => {
    const response = await fetch(`${API_ROOT}/manual-booking/availability?appointment_id=${encodeURIComponent(appointmentId)}`, {
        headers: { 'X-WP-Nonce': NONCE }
    });
    return response.json();
};

export const createManualBooking = async (bookingData) => {
    const response = await fetch(`${API_ROOT}/manual-booking`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': NONCE
        },
        body: JSON.stringify(bookingData)
    });
    return response.json();
};

// Also maintain the consolidated 'api' object for backwards compatibility
export const api = {
    getSummary,
    getSlotDetail,
    search,
    getBookingDetail,
    getManualBookingOptions,
    getManualBookingAvailability,
    createManualBooking
};
