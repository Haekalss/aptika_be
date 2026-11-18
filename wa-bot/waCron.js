const cron = require('node-cron');
const { sendSafeMessage } = require('./waService');
const db = require('./db');

function initScheduledJobs() {
    // ID Grup WA kamu yang tertangkap di terminal tadi
    const targetGroupJid = '120363416320868172@g.us';

    // Jadwal: '0 7 * * *' = Setiap jam 07:00 pagi WIB
    // (Ubah ke '*/2 * * * *' kalau mau ngetes kirim otomatis tiap 2 menit sekarang)
    cron.schedule('0 7 * * *', async () => {
        console.log('⏰ Menjalankan pengiriman rekap otomatis ke grup...');

        try {
            const [magangCount] = await db.query("SELECT COUNT(*) as total FROM magangs");
            const [taskRows] = await db.query("SELECT * FROM tasks ORDER BY id DESC LIMIT 5");

            let daftarAgenda = taskRows.length > 0
                ? taskRows.map((t, i) => `${i + 1}. ${t.title || t.judul || t.name || 'Kegiatan #' + t.id}`).join('\n')
                : '- Belum ada agenda tercatat hari ini.';

            const pesan = `*REKAP HARIAN APTIKA TOOLS*\n\n📌 *Agenda / Tugas Terbaru:*\n${daftarAgenda}\n\n👥 *Total Anak Magang Aktif:* ${magangCount[0].total} orang\n\n_Pesan otomatis dari Bot Aptika Tools_`;

            await sendSafeMessage(targetGroupJid, pesan);
            console.log('✅ Rekap otomatis berhasil dikirim ke grup!');
        } catch (error) {
            console.error('Gagal mengirim jadwal otomatis:', error.message);
        }
    }, { timezone: "Asia/Jakarta" });
}

module.exports = { initScheduledJobs };