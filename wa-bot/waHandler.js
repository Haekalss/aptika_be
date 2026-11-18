// aptika_be/wa-bot/waHandler.js
const db = require('./db');

async function handleIncomingMessage(msg, sendSafeMessage) {
    const senderJid = msg.key.remoteJid;
    const pushName = msg.pushName || 'Rekan Aptika';

    const textMessage = (
        msg.message.conversation ||
        msg.message.extendedTextMessage?.text ||
        ''
    ).trim();

    if (!textMessage) return;

    const lowerText = textMessage.toLowerCase();
    console.log(`[Pesan Masuk] ${pushName} (${senderJid}): ${textMessage}`);

    // 1. Cek ID Grup (Bisa ketik !idgrup atau !idgroup)
    if (lowerText === '!idgrup' || lowerText === '!idgroup') {
        await sendSafeMessage(senderJid, `ID Grup/Chat ini adalah:\n*${senderJid}*`, msg);
        return;
    }

    // 2. Menu Utama
    if (['halo', 'hai', 'menu', 'ping'].includes(lowerText)) {
        const reply = `Halo *${pushName}*! 👋\nSelamat datang di Bot WhatsApp *Aptika Tools*.\n\nSilakan ketik kata kunci berikut untuk mengecek data tabel:\n1️⃣ *info magang* - Cek total terdaftar & sedang aktif magang\n2️⃣ *magang november* - Cek anak magang bulan November\n3️⃣ *daftar aset* - Cek daftar Aset TI\n4️⃣ *info pegawai* - Cek total data pegawai\n5️⃣ *info tugas* - Cek daftar task/agenda terbaru`;
        await sendSafeMessage(senderJid, reply, msg);
        return;
    }

    // 3. Baca Tabel 'magangs' (Sesuai kolom: nama, nama_kampus, tgl_mulai_magang, tgl_selesai_magang)
    if (lowerText.includes('magang')) {
        try {
            if (lowerText.includes('november')) {
                const [novRows] = await db.query(
                    "SELECT * FROM magangs WHERE (MONTH(tgl_mulai_magang) <= 11 AND MONTH(tgl_selesai_magang) >= 11) OR MONTH(created_at) = 11"
                );

                let daftarNov = '';
                if (novRows.length > 0) {
                    daftarNov = '\n\n*Daftar Anak Magang (November):*\n' + novRows.slice(0, 15).map((item, i) => {
                        return `${i + 1}. *${item.nama}* (${item.nama_kampus || '-'})`;
                    }).join('\n');
                }

                const reply = `Halo *${pushName}*, berikut data *Magang Bulan November*:\n\n📊 Jumlah tercatat: *${novRows.length} mahasiswa*${daftarNov}`;
                await sendSafeMessage(senderJid, reply, msg);
                return;
            }

            // Default: Tampilkan Total Terdaftar & Sedang Aktif (Persis Dashboard)
            const [allRows] = await db.query("SELECT * FROM magangs");
            const [activeRows] = await db.query(
                "SELECT * FROM magangs WHERE CURDATE() BETWEEN tgl_mulai_magang AND tgl_selesai_magang"
            );

            let daftarNama = '';
            const rowsToShow = activeRows.length > 0 ? activeRows : allRows;
            if (rowsToShow.length > 0) {
                daftarNama = '\n\n*Daftar Anak Magang:*\n' + rowsToShow.slice(0, 10).map((item, i) => {
                    return `${i + 1}. *${item.nama}* (${item.nama_kampus || '-'})`;
                }).join('\n');
            }

            const reply = `Halo *${pushName}*, berikut ringkasan data *Magang Aptika Tools*:\n\n🎓 *Total Mahasiswa Magang:* ${allRows.length} terdaftar\n🟢 *Sedang Aktif Magang:* ${activeRows.length} mahasiswa${daftarNama}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            console.error('Error DB Magang:', err.message);
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel magangs: ${err.message}`, msg);
        }
        return;
    }

    // 4. Baca Tabel 'pegawai'
    if (lowerText.includes('info pegawai') || lowerText.includes('daftar pegawai')) {
        try {
            const [rows] = await db.query("SELECT * FROM pegawai LIMIT 10");
            const [countResult] = await db.query("SELECT COUNT(*) as total FROM pegawai");
            const total = countResult[0].total;

            let listPegawai = rows.map((p, i) => {
                const nama = p.nama || p.name || p.nama_pegawai || `Pegawai #${p.id}`;
                return `${i + 1}. ${nama}`;
            }).join('\n');

            const reply = `Halo *${pushName}*, terdapat total *${total} pegawai* di database Aptika Tools.\n\n*10 Data Teratas:*\n${listPegawai}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            console.error('Error DB Pegawai:', err.message);
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel pegawai: ${err.message}`, msg);
        }
        return;
    }

    // 5. Baca Tabel 'tasks' (Tugas / Agenda)
    if (lowerText.includes('info tugas') || lowerText.includes('agenda')) {
        try {
            const [rows] = await db.query("SELECT * FROM tasks ORDER BY id DESC LIMIT 5");
            if (rows.length === 0) {
                await sendSafeMessage(senderJid, 'Belum ada data tugas/agenda di tabel tasks.', msg);
                return;
            }

            let listTask = rows.map((t, i) => {
                const judul = t.title || t.judul || t.nama_tugas || t.name || `Task #${t.id}`;
                const status = t.status ? ` [${t.status}]` : '';
                return `${i + 1}. ${judul}${status}`;
            }).join('\n');

            const reply = `*5 Tugas/Agenda Terbaru di Aptika Tools:*\n\n${listTask}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            console.error('Error DB Tasks:', err.message);
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel tasks: ${err.message}`, msg);
        }
        return;
    }

    // 6. Baca Tabel 'daftar_aset_tis' (Daftar Aset TI - 49 Data)
    if (lowerText.includes('aset')) {
        try {
            const [rows] = await db.query("SELECT * FROM daftar_aset_tis LIMIT 10");
            const [countResult] = await db.query("SELECT COUNT(*) as total FROM daftar_aset_tis");
            const total = countResult[0].total;

            let listAset = '';
            if (rows.length > 0) {
                listAset = '\n\n*10 Aset Teratas:*\n' + rows.map((a, i) => {
                    const nama = a.nama_aset || a.nama || a.kode_aset || a.kode_barang || a.spesifikasi || `Aset #${a.id}`;
                    return `${i + 1}. ${nama}`;
                }).join('\n');
            }

            const reply = `Halo *${pushName}*, tercatat total *${total} Aset TI* di database Aptika Tools.${listAset}`;
            await sendSafeMessage(senderJid, reply, msg);
        } catch (err) {
            console.error('Error DB Aset:', err.message);
            await sendSafeMessage(senderJid, `Maaf, gagal membaca tabel daftar_aset_tis: ${err.message}`, msg);
        }
        return;
    }
}

module.exports = { handleIncomingMessage };