const express = require('express');
const cors = require('cors');
const fs = require('fs');
const path = require('path');
const { connectToWhatsApp, sendSafeMessage, getBotState, getSockInstance } = require('./waService');
const {
    handleIncomingMessage,
    getAiConfig,
    setAiConfig,
    getAutomations,
    addAutomation,
    deleteAutomation,
    getAgendas,
    addAgenda,
    deleteAgenda,
    markAgendasSent,
    formatOfficialAgenda,
    getIndonesianDateLabel,
} = require('./waHandler');
const { initScheduledJobs } = require('./waCron');

const app = express();
app.use(cors());
app.use(express.json());

// 1. Status Koneksi, QR Code, Log, dan Konfigurasi AI
app.get('/api/wa/status', (req, res) => {
    const aiConfig = getAiConfig();
    res.json({
        ...getBotState(),
        aiEnabled: aiConfig.enabled,
        aiTone: aiConfig.tone,
    });
});

// 2. Pengaturan Smart Chat AI (ON/OFF & Gaya Bahasa Ramah/Formal)
app.post('/api/wa/ai-config', (req, res) => {
    const updated = setAiConfig(req.body || {});
    res.json({ success: true, aiConfig: updated });
});

app.post('/api/wa/toggle-ai', (req, res) => {
    const { enabled } = req.body;
    const current = getAiConfig();
    const updated = setAiConfig({ enabled: typeof enabled === 'boolean' ? enabled : !current.enabled });
    res.json({ success: true, aiEnabled: updated.enabled, aiTone: updated.tone });
});

// 3. Automation (Jawaban Manual) CRUD
app.get('/api/wa/automations', (req, res) => {
    res.json({ success: true, automations: getAutomations() });
});

app.post('/api/wa/automations', (req, res) => {
    const { keyword, reply } = req.body;
    if (!keyword || !reply) return res.status(400).json({ error: 'Kata kunci dan balasan wajib diisi' });
    const created = addAutomation(keyword, reply);
    res.json({ success: true, item: created, automations: getAutomations() });
});

app.delete('/api/wa/automations/:id', (req, res) => {
    const automations = deleteAutomation(req.params.id);
    res.json({ success: true, automations });
});

// 4. Agenda & Scheduling Posting Automation (Format Resmi Bidang Aptika)
app.get('/api/wa/agendas', (req, res) => {
    const agendas = getAgendas();
    res.json({
        success: true,
        agendas,
        defaultDateLabel: getIndonesianDateLabel(0),
        preview: agendas.length > 0 ? formatOfficialAgenda(agendas) : '',
    });
});

app.post('/api/wa/agendas', (req, res) => {
    const { waktu, kegiatan, lokasi, peserta, content, sender, scheduleFor } = req.body;
    if (!kegiatan && !content) {
        return res.status(400).json({ error: 'Nama kegiatan wajib diisi' });
    }
    const created = addAgenda({
        waktu,
        kegiatan: kegiatan || content,
        lokasi,
        peserta,
        sender: sender || 'Admin Web',
        source: 'Web Dashboard',
        scheduleFor,
    });
    const agendas = getAgendas();
    res.json({
        success: true,
        item: created,
        agendas,
        preview: formatOfficialAgenda(agendas),
    });
});

app.delete('/api/wa/agendas/:id', (req, res) => {
    const agendas = deleteAgenda(req.params.id);
    res.json({
        success: true,
        agendas,
        preview: agendas.length > 0 ? formatOfficialAgenda(agendas) : '',
    });
});

app.post('/api/wa/agendas/broadcast', async (req, res) => {
    try {
        const { target, dateLabel } = req.body;
        if (!target) return res.status(400).json({ error: 'Pilih grup tujuan terlebih dahulu' });

        const agendas = getAgendas();
        if (agendas.length === 0) {
            return res.status(400).json({ error: 'Belum ada agenda tersimpan untuk dibagikan' });
        }

        const formattedMessage = formatOfficialAgenda(agendas, dateLabel);
        await sendSafeMessage(target, formattedMessage);
        const updatedAgendas = markAgendasSent();

        res.json({
            success: true,
            message: 'Agenda Bidang Aptika berhasil dikirim ke grup!',
            preview: formattedMessage,
            agendas: updatedAgendas,
        });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// 5. Kirim Pesan Personal (One-by-One) / Broadcast ke Grup WA
app.post('/api/wa/send', async (req, res) => {
    try {
        let { target, message } = req.body;
        if (!target || !message) {
            return res.status(400).json({ error: 'Target dan pesan wajib diisi' });
        }
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

// 6. Web Chat (Tanya Bot & Tes /tambahagenda dari Website)
app.post('/api/wa/web-chat', async (req, res) => {
    try {
        const { text } = req.body;
        let botReply =
            'Maaf, kata kunci belum dikenali. Coba ketik: *menu*, *agenda hari ini*, *info magang*, *daftar aset*, *info pegawai*, atau */tambahagenda [detail]*.';

        const fakeMsg = {
            key: { remoteJid: 'web-dashboard@aptika' },
            pushName: 'Admin Web',
            message: { conversation: text },
        };

        await handleIncomingMessage(fakeMsg, async (jid, replyText) => {
            botReply = replyText;
        });

        res.json({ reply: botReply, aiConfig: getAiConfig() });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// 7. Logout Sesi WhatsApp
app.post('/api/wa/logout', async (req, res) => {
    try {
        const authPath = path.join(__dirname, 'auth_info_baileys');
        if (fs.existsSync(authPath)) {
            fs.rmSync(authPath, { recursive: true, force: true });
        }
        res.json({ success: true, message: 'Sesi WhatsApp berhasil dihapus.' });
        setTimeout(() => process.exit(0), 1000);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// 8. Daftar Grup WhatsApp
app.get('/api/wa/groups', async (req, res) => {
    try {
        const sock = getSockInstance();
        if (!sock) return res.json({ success: true, groups: [] });

        const groupsObj = await sock.groupFetchAllParticipating();
        const groups = Object.values(groupsObj).map((g) => ({
            id: g.id,
            subject: g.subject,
        }));
        res.json({ success: true, groups });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

const PORT = process.env.PORT || 3005;
app.listen(PORT, () => {
    console.log(`API Server WA-Bot aktif di http://localhost:${PORT}`);
    console.log('Menjalankan Layanan WhatsApp Bot Aptika Tools...');
    connectToWhatsApp(handleIncomingMessage);
    initScheduledJobs();
});