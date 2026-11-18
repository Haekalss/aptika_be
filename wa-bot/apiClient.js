const API_BASE_URL = 'https://aptika-be.wasmer.dev/api';

// Isi dengan email & password yang kamu pakai login di aptika-fe.vercel.app
const ADMIN_EMAIL = process.env.APTIKA_ADMIN_EMAIL || 'admin.aptika@jabarprov.go.id'; // Sesuaikan jika beda
const ADMIN_PASSWORD = process.env.APTIKA_ADMIN_PASSWORD || 'password';               // Sesuaikan jika beda

let authToken = null;

async function getToken() {
    if (authToken) return authToken;

    const res = await fetch(`${API_BASE_URL}/login`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            email: ADMIN_EMAIL,
            password: ADMIN_PASSWORD
        })
    });

    const data = await res.json();
    authToken = data.token || data.access_token || data.data?.token;

    if (!authToken) {
        throw new Error('Gagal login ke API online: ' + JSON.stringify(data));
    }
    return authToken;
}

async function fetchFromAptikaApi(endpoint) {
    let token = await getToken();

    let res = await fetch(`${API_BASE_URL}${endpoint}`, {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
        }
    });

    // Jika token kedaluwarsa (401), login ulang otomatis
    if (res.status === 401) {
        authToken = null;
        token = await getToken();
        res = await fetch(`${API_BASE_URL}${endpoint}`, {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json'
            }
        });
    }

    return await res.json();
}

module.exports = { fetchFromAptikaApi };