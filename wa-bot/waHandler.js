// aptika_be/wa-bot/waHandler.js
const fs = require('fs');
const path = require('path');
const db = require('./db');

// Load aiService secara aman jika tersedia
let aiService = null;
try {
    aiService = require('./aiService');
} catch (err) {
    console.warn('[waHandler] aiService belum tersedia:', err.message);
}

// File penyimpanan lokal untuk Automation Manual & Agenda Scheduling
const AUTOMATION_FILE = path.join(__dirname, 'data_automations.json');
const AGENDA_FILE = path.join(__dirname, 'data_agendas.json');

// State Konfigurasi Smart Chat AI
let aiConfig = {
    enabled: true,
    tone: 'ramah', // 'ramah' | 'formal'
};

// Default aturan jawaban manual (Automation)
const DEFAULT_AUTOMATIONS = [
    {
        id: 1,
        keyword: 'jam kerja',
        reply: 'Jam operasional Bidang APTIKA Diskominfo Jabar adalah Senin - Jumat, pukul 07:30 - 16:00 WIB.',
    },
    {
        id: 2,
        keyword: 'alamat kantor',
        reply: 'Diskominfo Provinsi Jawa Barat berlokasi di Jl. Tamansari No. 55, Bandung.',
    },
];

function loadJsonFile(filePath, fallbackData) {
    try {
        if (!fs.existsSync(filePath)) {
            fs.writeFileSync(filePath, JSON.stringify(fallbackData, null, 2));
            return fallbackData;
        }
        return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
    } catch {
        return fallbackData;
    }
}

function saveJsonFile(filePath, data) {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
}

function getAiConfig() {
    return aiConfig;
}

function setAiConfig(updates) {
    if (typeof updates.enabled === 'boolean') aiConfig.enabled = updates.enabled;
    if (updates.tone === 'ramah' || updates.tone === 'formal') aiConfig.tone = updates.tone;
    return aiConfig;
}

function getAutomations() {
    return loadJsonFile(AUTOMATION_FILE, DEFAULT_AUTOMATIONS);
}

function addAutomation(keyword, reply) {
    const list = getAutomations();
    const newItem = {
        id: Date.now(),
        keyword: keyword.trim().toLowerCase(),
        reply: reply.trim(),
    };
    list.unshift(newItem);
    saveJsonFile(AUTOMATION_FILE, list);
    return newItem;
}

function deleteAutomation(id) {
    const list = getAutomations().filter((item) => String(item.id) !== String(id));
    saveJsonFile(AUTOMATION_FILE, list);
    return list;
}

// Helper: Ambil angka menit dari string waktu (misal "Pk. 08.30 - 10.00 WIB" -> 510) untuk urutan kronologis
function extractStartMinutes(waktuStr = '') {
    const match = waktuStr.match(/(\d{1,2})[.:](\d{2})/);
    if (!match) return 9999;
    return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
}

// Helper: Format tanggal bahasa Indonesia (contoh: "Kamis, 19 November 2026")
function getIndonesianDateLabel(offsetDays = 0) {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);
    return date.toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    });
}

function getAgendas() {
    const list = loadJsonFile(AGENDA_FILE, []);
    // Normalisasi jika ada data lama yang hanya punya field content
    return list.map((item) => ({
        ...item,
        waktu: item.waktu || 'Pk. 09.00 - Selesai',
        kegiatan: item.kegiatan || item.content || '-',
        lokasi: item.lokasi || '',
        peserta: item.peserta || item.sender || 'Tim Aptika',
    }));
}

function addAgenda({ waktu, kegiatan, lokasi, peserta, content, sender, source, scheduleFor }) {
    const list = getAgendas();
    const cleanWaktu = (waktu || 'Pk. 09.00 - Selesai').trim();
    const normalizedWaktu = cleanWaktu.toLowerCase().startsWith('pk') ? cleanWaktu : `Pk. ${cleanWaktu}`;
    const cleanKegiatan = (kegiatan || content || '').trim();
    const cleanLokasi = (lokasi || '').trim();
    const cleanPeserta = (peserta || sender || 'Tim Aptika').replace(/^\(|\)$/g, '').trim();

    const newAgenda = {
        id: Date.now(),
        waktu: normalizedWaktu,
        kegiatan: cleanKegiatan,
        lokasi: cleanLokasi,
        peserta: cleanPeserta,
        content: `${normalizedWaktu} - ${cleanKegiatan}`,
        sender: sender || 'Admin Web',
        source: source || 'Web Dashboard',
        scheduleFor: scheduleFor || 'Besok 07:00 WIB',
        status: 'Terjadwal',
        createdAt: new Date().toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' }),
    };

    list.push(newAgenda);
    saveJsonFile(AGENDA_FILE, list);
    return newAgenda;
}

function deleteAgenda(id) {
    const list = getAgendas().filter((item) => String(item.id) !== String(id));
    saveJsonFile(AGENDA_FILE, list);
    return list;
}

function markAgendasSent() {
    const list = getAgendas().map((a) => ({ ...a, status: 'Terkirim' }));
    saveJsonFile(AGENDA_FILE, list);
    return list;
}

// Generator Format Resmi "Agenda Bidang Aptika"
function formatOfficialAgenda(agendas, customDateLabel) {
    const dateLabel = (customDateLabel || '').trim() || getIndonesianDateLabel(0);
    const sorted = [...agendas].sort(
        (a, b) => extractStartMinutes(a.waktu) - extractStartMinutes(b.waktu)
    );

    const body = sorted
        .map((item, idx) => {
            const lines = [`${idx + 1}.\t${item.waktu}`, item.kegiatan];
            if (item.lokasi) lines.push(item.lokasi);
            lines.push(`(${item.peserta || 'Tim Aptika'})`);
            return lines.join('\n');
        })
        .join('\n\n');

    const nextNumber = sorted.length + 1;

    return (
        `Agenda Bidang Aptika \n` +
        `${dateLabel}\n\n` +
        `${body}\n\n` +
        `${nextNumber}.\t\n\n` +
        `#Aptika, Maju Kolaborasi Hebat 💪`
    );
}

// Smart Parser: Pecah ketikan /tambahagenda menjadi { waktu, kegiatan, lokasi, peserta }
async function parseAgendaInput(rawText) {
    const cleanText = rawText.trim();

    // Opsi A: Pakai pemisah "|" -> /tambahagenda 10.00 - Selesai | Rapat Tim | RR Bidang Aptika | Dwi, Angga
    if (cleanText.includes('|')) {
        const parts = cleanText.split('|').map((p) => p.trim());
        const waktuRaw = parts[0] || '09.00 - Selesai';
        const lokasiRaw = parts[2] || '';
        return {
            waktu: waktuRaw.toLowerCase().startsWith('pk') ? waktuRaw : `Pk. ${waktuRaw}`,
            kegiatan: parts[1] || cleanText,
            lokasi: lokasiRaw
                ? lokasiRaw.includes(':')
                    ? lokasiRaw
                    : `Lokasi : ${lokasiRaw}`
                : '',
            peserta: parts[3] ? parts[3].replace(/^\(|\)$/g, '') : 'Tim Aptika',
        };
    }

    // Opsi B: Multi-baris (Enter) persis seperti format WA resmi
    const lines = cleanText.split('\n').map((l) => l.trim()).filter(Boolean);
    if (lines.length >= 2) {
        let waktu = lines[0];
        if (!waktu.toLowerCase().startsWith('pk')) waktu = `Pk. ${waktu}`;
        const kegiatan = lines[1];
        const lokasiLines = [];
        let peserta = '';

        for (let i = 2; i < lines.length; i++) {
            const line = lines[i];
            if (line.startsWith('(') && line.endsWith(')')) {
                peserta = line.slice(1, -1).trim();
            } else {
                lokasiLines.push(line);
            }
        }

        return {
            waktu,
            kegiatan,
            lokasi: lokasiLines.join('\n'),
            peserta: peserta || 'Tim Aptika',
        };
    }

    // Opsi C: 1 baris kalimat bebas -> ekstrak otomatis via Gemini AI jika aktif
    const askFn = aiService?.askGemini || aiService?.generateAIReply || aiService?.askAI;
    if (aiConfig.enabled && typeof askFn === 'function') {
        try {
            const extractPrompt =
                `Ekstrak teks agenda berikut menjadi JSON murni tanpa markdown dengan key: ` +
                `"waktu" (format "Pk. HH.MM - Selesai" atau "Pk. HH.MM - HH.MM WIB"), ` +
                `"kegiatan", ` +
                `"lokasi" (awali dengan "Lokasi : " atau "Online : ", kosongkan "" jika tidak disebut), ` +
                `dan "peserta" (tanpa tanda kurung).\nTeks: "${cleanText}"`;
            const rawJson = await askFn(extractPrompt);
            const jsonMatch = rawJson && rawJson.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                const parsed = JSON.parse(jsonMatch[0]);
                return {
                    waktu: parsed.waktu || 'Pk. 09.00 - Selesai',
                    kegiatan: parsed.kegiatan || cleanText,
                    lokasi: parsed.lokasi || '',
                    peserta: parsed.peserta || 'Tim Aptika',
                };
            }
        } catch (err) {
            console.warn('[AI Agenda Parser fallback]:', err.message);
        }
    }

    // Fallback standar jika 1 baris tanpa AI
    const timeMatch = cleanText.match(/(\d{1,2}[.:]\d{2}(?:\s*[-–]\s*(?:\d{1,2}[.:]\d{2}|selesai)(?:\s*wib)?)?)/i);
    return {
        waktu: timeMatch ? `Pk. ${timeMatch[1]}` : 'Pk. 09.00 - Selesai',
        kegiatan: timeMatch ? cleanText.replace(timeMatch[0], '').trim() || cleanText : cleanText,
        lokasi: '',
        peserta: 'Tim Aptika',
    };
}

// Helper: Generate balasan Smart Chat AI yang luwes untuk /tambahagenda
async function buildSmartAgendaReply(pushName, agendaPreview) {
    const tone = aiConfig.tone;

    const askFn = aiService?.askGemini || aiService?.generateAIReply || aiService?.askAI;
    if (aiConfig.enabled && typeof askFn === 'function') {
        try {
            const styleDesc =
                tone === 'ramah'
                    ? 'santai, ramah, hangat, dan akrab seperti rekan kerja (contoh: "Oke banget nih agendanya, sudah saya simpan ya!")'
                    : 'formal, sopan, dan profesional khas instansi pemerintahan';
            const prompt = `Kamu adalah Asisten Bot WhatsApp Aptika Tools Diskominfo Jabar. Balas pesan dari ${pushName} yang baru saja menambahkan agenda berikut:\n${agendaPreview}\nBerikan konfirmasi singkat (maksimal 2 kalimat) dengan gaya bahasa ${styleDesc} bahwa agenda sudah berhasil disimpan dan siap dibagikan sesuai jadwal.`;
            const aiReply = await askFn(prompt);
            if (aiReply) return `${aiReply}\n\n*Tercatat:*\n${agendaPreview}`;
        } catch (err) {
            console.error('[Smart Agenda AI fallback]:', err.message);
        }
    }

    if (!aiConfig.enabled) {
        return `Agenda dari *${pushName}* berhasil disimpan:\n\n${agendaPreview}\n\nStatus: Terjadwal untuk dibagikan otomatis.`;
    }

    const ramahPool = [
        `Oke banget nih agendanya, *${pushName}*! Sudah saya save ya:\n\n${agendaPreview}\n\nSiap dibagikan otomatis sesuai jadwal!`,
        `Siap *${pushName}*, agenda sudah aman tersimpan di sistem:\n\n${agendaPreview}\n\nNanti langsung saya bantu share ke grup pas waktunya ya.`,
        `Mantap *${pushName}*, catatan agendanya sudah masuk!\n\n${agendaPreview}\n\nTenang saja, jadwal posting otomatis sudah aktif.`,
    ];

    const formalPool = [
        `Baik *${pushName}*, agenda telah berhasil kami simpan ke dalam sistem:\n\n${agendaPreview}\n\nAgenda ini akan dibagikan secara otomatis sesuai jadwal yang ditentukan.`,
        `Terima kasih *${pushName}*. Data agenda berikut telah tercatat:\n\n${agendaPreview}\n\nSistem penjadwalan otomatis akan meneruskannya kepada anggota grup.`,
    ];

    const pool = tone === 'ramah' ? ramahPool : formalPool;
    return pool[Math.floor(Math.random() * pool.length)];
}

async function handleIncomingMessage(msg, sendSafeMessage) {
    const senderJid = msg.key.remoteJid;
    const pushName = msg.pushName || 'Rekan Aptika';
    const isGroup = senderJid.endsWith('@g.us');
    const isWebChat = senderJid === 'web-dashboard@aptika';

    const textMessage = (
        msg.message.conversation ||
        msg.message.extendedTextMessage?.text ||
        ''
    ).trim();

    if (!textMessage) return;

    const lowerText = textMessage.toLowerCase();
    console.log(`[Pesan Masuk] ${pushName} (${senderJid}): ${textMessage}`);

    // 1. Perintah Khusus Grup / Personal: /tambahagenda
    if (lowerText.startsWith('/tambahagenda')) {
        const rawInput = textMessage.replace(/^\/tambahagenda\s*/i, '').trim();
        if (!rawInput) {
            await sendSafeMessage(
                senderJid,
                'Format penambahan agenda:\n\n' +
                    '*Cara 1 (Pemisah | ):*\n' +
                    '/tambahagenda 10.00 - Selesai | Desk Aplikasi Dinas Sosial | RR Bidang Aptika | Tim Layanan\n\n' +
                    '*Cara 2 (Kalimat Bebas - Smart AI):*\n' +
                    '/tambahagenda Jam 13.00 - Selesai Optimalisasi Aplikasi Dishut di Kantor Dishut peserta Kabid, Mdeni, Dwi',
                msg
            );
            return;
        }

        const parsed = await parseAgendaInput(rawInput);
        addAgenda({
            ...parsed,
            sender: pushName,
            source: isGroup ? 'Grup WhatsApp' : isWebChat ? 'Web Chat' : 'Chat Personal',
            scheduleFor: 'Besok 07:00 WIB',
        });

        const previewRingkas = `${parsed.waktu}\n${parsed.kegiatan}${parsed.lokasi ? `\n${parsed.lokasi}` : ''}\n(${parsed.peserta})`;
        const smartReply = await buildSmartAgendaReply(pushName, previewRingkas);
        await sendSafeMessage(senderJid, smartReply, msg);
        return;
    }

    // 2. Cek Rekap Agenda Resmi Bidang Aptika
    if (['agenda hari ini', '/agenda', 'rekap agenda'].includes(lowerText)) {
        const agendas = getAgendas();
        if (agendas.length === 0) {
            await sendSafeMessage(senderJid, 'Belum ada agenda Bidang Aptika yang tercatat saat ini.', msg);
            return;
        }
        await sendSafeMessage(senderJid, formatOfficialAgenda(agendas), msg);
        return;
    }

    // 3. Cek ID Grup
    if (lowerText === '!idgrup' || lowerText === '!idgroup') {
        await sendSafeMessage(senderJid, `ID Grup/Chat ini adalah:\n*${senderJid}*`, msg);
        return;
    }

    // 4. Cek Aturan Automation (Jawaban Manual dari Web Dashboard)
    const automations = getAutomations();
    const matchedAuto = automations.find(
        (item) => lowerText === item.keyword || lowerText.includes(item.keyword)
    );
    if (matchedAuto && !isGroup) {
        await sendSafeMessage(senderJid, matchedAuto.reply, msg);
        return;
    }

    // 5. Menu Utama
    if (['halo', 'hai', 'menu', 'ping'].includes(lowerText)) {
        const aiStatusText = aiConfig.enabled ? `AKTIF (${aiConfig.tone.toUpperCase()})` : 'NONAKTIF (OFF)';
        const reply =
            `Halo *${pushName}*!\nSelamat datang di Bot WhatsApp *Aptika Tools*.\nStatus Smart Chat AI: *${aiStatusText}*\n\n` +
            `Kata kunci cepat yang tersedia:\n` +
            `1. *info magang* - Cek total & mahasiswa magang aktif\n` +
            `2. *magang november* - Cek anak magang bulan November\n` +
            `3. *daftar aset* - Cek daftar Aset TI\n` +
            `4. *info pegawai* - Cek total data pegawai\n` +
            `5. *info tugas* - Cek daftar tugas terbaru\n` +
            `6. *agenda hari ini* - Tampilkan format resmi Agenda Bidang Aptika\n` +
            `7. */tambahagenda [detail]* - Simpan agenda baru untuk posting otomatis`;
        await sendSafeMessage(senderJid, reply, msg);
        return;
    }

    // 6. Baca Tabel 'magangs'
    if (lowerText.includes('magang')) {
        try {
            if (lowerText.includes('november')) {
                const [novRows] = await db.query(
                    "SELECT * FROM magangs WHERE (MONTH(tgl_mulai_magang) <= 11 AND MONTH(tgl_selesai_magang) >= 11) OR MONTH(created_at) = 11"
                );
                let daftarNov = '';
                if (novRows.length > 0) {
                    daftarNov =
                        '\n\n*Daftar Anak Magang (November):*\n' +
                        novRows
                            .slice(0, 15)
                            .map((item, i) => `${i + 1}. *${item.nama}* (${item.nama_kampus || '-'})`)
                            .join('\n');
                }
                const reply = `Halo *${pushName}*, berikut data *Magang Bulan November*:\n\nJumlah tercatat: *${novRows.length} mahasiswa*${daftarNov}`;
                await sendSafeMessage(senderJid, reply, msg);
                return;
            }

            const [allRows] = await db.query('SELECT * FROM magangs');
            const [activeRows] = await db.query(
                'SELECT * FROM magangs WHERE CURDATE() BETWEEN tgl_mulai_magang AND tgl_selesai_magang'
            );

            let daftarNama = '';
            const rowsToShow = activeRows.length > 0 ? activeRows : allRows;
            if (rowsToShow.length > 0) {
                daftarNama =
                    '\n\n*Daftar Anak Magang:*\n' +
                    rowsToShow
                        .slice(0, 10)
                        .map((item, i) => `${i + 1}. *${item.nama}* (${item.nama_kampus || '-'})`)
                        .join('\n');
            }

            const reply = `Halo *${pushName}*, berikut ringkasan data *Magang Aptika Tools*:\n\n- *Total Mahasiswa Magang:* ${allRows.length} terdaftar\n- *Sedang Aktif Magang:* ${activeRows.length} mahasiswa${daftarNama}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel magangs: ${err.message}`, msg);
        }
        return;
    }

    // 7. Baca Tabel 'pegawai'
    if (lowerText.includes('pegawai')) {
        try {
            const [rows] = await db.query('SELECT * FROM pegawai LIMIT 10');
            const [countResult] = await db.query('SELECT COUNT(*) as total FROM pegawai');
            const total = countResult[0].total;

            const listPegawai = rows
                .map((p, i) => `${i + 1}. ${p.nama || p.name || p.nama_pegawai || `Pegawai #${p.id}`}`)
                .join('\n');

            const reply = `Halo *${pushName}*, terdapat total *${total} pegawai* di database Aptika Tools.\n\n*10 Data Teratas:*\n${listPegawai}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel pegawai: ${err.message}`, msg);
        }
        return;
    }

    // 8. Baca Tabel 'tasks'
    if (lowerText.includes('tugas') || lowerText.includes('rapat')) {
        try {
            const [rows] = await db.query('SELECT * FROM tasks ORDER BY id DESC LIMIT 5');
            if (rows.length === 0) {
                await sendSafeMessage(senderJid, 'Belum ada data tugas di tabel tasks.', msg);
                return;
            }
            const listTask = rows
                .map((t, i) => {
                    const judul = t.title || t.judul || t.nama_tugas || t.name || `Task #${t.id}`;
                    const status = t.status ? ` [${t.status}]` : '';
                    return `${i + 1}. ${judul}${status}`;
                })
                .join('\n');

            await sendSafeMessage(senderJid, `*5 Tugas Terbaru di Aptika Tools:*\n\n${listTask}`, msg);
        } catch (err) {
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel tasks: ${err.message}`, msg);
        }
        return;
    }

    // 9. Baca Tabel 'daftar_aset_tis'
    if (lowerText.includes('aset')) {
        try {
            const [rows] = await db.query('SELECT * FROM daftar_aset_tis LIMIT 10');
            const [countResult] = await db.query('SELECT COUNT(*) as total FROM daftar_aset_tis');
            const total = countResult[0].total;

            let listAset = '';
            if (rows.length > 0) {
                listAset =
                    '\n\n*10 Aset Teratas:*\n' +
                    rows
                        .map((a, i) => `${i + 1}. ${a.nama_aset || a.nama || a.kode_aset || a.spesifikasi || `Aset #${a.id}`}`)
                        .join('\n');
            }

            await sendSafeMessage(
                senderJid,
                `Halo *${pushName}*, tercatat total *${total} Aset TI* di database Aptika Tools.${listAset}`,
                msg
            );
        } catch (err) {
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel daftar_aset_tis: ${err.message}`, msg);
        }
        return;
    }

    // 10. Fallback Smart Chat AI (Khusus Chat Personal / Web Chat agar tidak spam di Grup)
    if (isGroup) return;

    if (!aiConfig.enabled) {
        if (isWebChat) {
            await sendSafeMessage(
                senderJid,
                'Smart Chat AI sedang *NONAKTIF (OFF)*. Silakan nyalakan di menu Smart Chat AI atau gunakan kata kunci cepat (*menu*, *agenda hari ini*, *info magang*, *daftar aset*, */tambahagenda*).',
                msg
            );
        }
        return;
    }

    const askFn = aiService?.askGemini || aiService?.generateAIReply || aiService?.askAI;
    if (typeof askFn === 'function') {
        try {
            const tonePrompt =
                aiConfig.tone === 'ramah'
                    ? 'Jawab dengan gaya ramah, santai, dan membantu.'
                    : 'Jawab dengan gaya formal dan profesional.';
            const aiReply = await askFn(`${tonePrompt}\nPertanyaan pengguna: ${textMessage}`);
            if (aiReply) {
                await sendSafeMessage(senderJid, aiReply, msg);
                return;
            }
        } catch (err) {
            console.error('Error AI Service:', err.message);
        }
    }

    if (isWebChat) {
        await sendSafeMessage(
            senderJid,
            'Maaf, pertanyaan belum dikenali. Coba ketik *menu*, *agenda hari ini*, *info magang*, *daftar aset*, atau */tambahagenda [detail]*.',
            msg
        );
    }
}

module.exports = {
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
};