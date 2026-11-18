const express = require('express');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { connectToWhatsApp, sendSafeMessage, getBotState } = require('./waService');
const { handleIncomingMessage } = require('./waHandler');
const { initScheduledJobs } = require('./waCron');

const app = express();
app.use(cors());
app.use(express.json());

// 1. Endpoint Cek Status Koneksi, QR Code, dan Log Pesan
app.get('/api/wa/status', (req, res) => {
    res.json(getBotState());
});

// 2. Endpoint Tes Kirim Pesan / Broadcast ke Nomor atau Grup WA
app.post('/api/wa/send', async (req, res) => {
    try {
        let { target, message } = req.body;
        if (!target || !message) {
            return res.status(400).json({ error: 'Target dan pesan wajib diisi' });
        }
        // Format nomor HP otomatis jika bukan JID grup (@g.us)
        if (!target.includes('@')) {
            target = target.replace(/[^0-9]/g, '');
            if (target.startsWith('0')) target = '62' + target.slice(1);
            target = `${target}@s.whatsapp.net`;
        }
        await sendSafeMessage(target, message);
        res.json({ success: true, message: 'Pesan berhasil dikirim!' });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// 3. Endpoint Web Chat (Tanya Bot Langsung dari Website Tanpa Buka WA)
app.post('/api/wa/web-chat', async (req, res) => {
    try {
        const { text } = req.body;
        let botReply = 'Maaf, kata kunci belum dikenali. Coba ketik: *menu*, *info magang*, *magang november*, *daftar aset*, *info pegawai*, atau *info tugas*.';

        const fakeMsg = {
            key: { remoteJid: 'web-dashboard@aptika' },
            pushName: 'Admin Web',
            message: { conversation: text }
        };

        await handleIncomingMessage(fakeMsg, async (jid, replyText) => {
            botReply = replyText;
        });

        res.json({ reply: botReply });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// 4. Endpoint Logout / Putuskan Sesi WhatsApp dari Dashboard Web
app.post('/api/wa/logout', async (req, res) => {
    try {
        const authPath = path.join(__dirname, 'auth_info_baileys');
        if (fs.existsSync(authPath)) {
            fs.rmSync(authPath, { recursive: true, force: true });
        }
        res.json({ success: true, message: 'Sesi WhatsApp berhasil dihapus.' });
        // Keluar proses agar bot merestart diri / meminta QR baru
        setTimeout(() => process.exit(0), 1000);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/wa/groups', async (req, res) => {
    try {
        // Mengambil instance sock dari waService atau mengecek fungsi groupFetchAllParticipating
        const { getSockInstance } = require('./waService');
        const sock = getSockInstance();
        if (!sock) {
            return res.json({ success: true, groups: [] });
        }
        const groupsObj = await sock.groupFetchAllParticipating();
        const groups = Object.values(groupsObj).map(g => ({
            id: g.id,
            subject: g.subject
        }));
        res.json({ success: true, groups });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

const PORT = 3005;
app.listen(PORT, () => {
    console.log(`🌐 API Server WA-Bot aktif di http://localhost:${PORT}`);
    console.log('🚀 Menjalankan Layanan WhatsApp Bot Aptika Tools...');
    connectToWhatsApp(handleIncomingMessage);
    initScheduledJobs();
});