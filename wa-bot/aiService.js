const { GoogleGenAI } = require('@google/genai');
const db = require('./db');

// Inisialisasi Google Gen AI (mengambil API key dari environment variable GEMINI_API_KEY)
const ai = new GoogleGenAI();

async function handleAiQuery(userMessage, pushName) {
    try {
        // Ambil ringkasan data dari database untuk dijadikan konteks pengetahuan bot
        const [magangRows] = await db.query("SELECT nama, nama_kampus, tgl_mulai_magang, tgl_selesai_magang FROM magangs");
        const [asetRows] = await db.query("SELECT COUNT(*) as total FROM daftar_aset_tis");
        const [pegawaiRows] = await db.query("SELECT COUNT(*) as total FROM pegawai");
        const [taskRows] = await db.query("SELECT title, status FROM tasks");

        const dbContext = `
Data saat ini di Aptika Tools:
- Total Magang: ${magangRows.length} orang. Daftar: ${JSON.stringify(magangRows)}
- Total Aset TI: ${asetRows[0].total} unit
- Total Pegawai: ${pegawaiRows[0].total} orang
- Daftar Tugas: ${JSON.stringify(taskRows)}
        `;

        const response = await ai.models.generateContent({
            model: 'gemini-2.5-flash',
            contents: [
                {
                    role: 'user',
                    parts: [
                        {
                            text: `Kamu adalah Asisten AI untuk platform Aptika Tools Diskominfo Jawa Barat. Jawablah pertanyaan dari "${pushName}" dengan ramah, profesional, dan menggunakan data berikut:\n\n${dbContext}\n\nPertanyaan pengguna: "${userMessage}"`
                        }
                    ]
                }
            ]
        });

        return response.text || "Maaf, saya tidak dapat memproses jawaban saat ini.";
    } catch (err) {
        console.error('Error Gemini AI:', err.message);
        return null; // Jika gagal/belum set API key, kembalikan null agar kembali ke keyword handler biasa
    }
}

module.exports = { handleAiQuery };