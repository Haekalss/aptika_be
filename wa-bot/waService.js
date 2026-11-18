const { default: makeWASocket, useMultiFileAuthState, DisconnectReason } = require('@whiskeysockets/baileys');
const pino = require('pino');
const qrcodeTerminal = require('qrcode-terminal');
const QRCode = require('qrcode');

let sock = null;
let connectionStatus = 'disconnected'; // 'disconnected' | 'qr' | 'connected'
let currentQrDataUrl = null;
let connectedUser = null;
const messageLogs = []; // Menyimpan 50 riwayat pesan terakhir untuk ditampilkan di web

function addLog(direction, sender, target, text) {
    messageLogs.unshift({
        id: Date.now() + Math.random(),
        time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
        direction, // 'IN' atau 'OUT'
        sender,
        target,
        text
    });
    if (messageLogs.length > 50) messageLogs.pop();
}

async function sendSafeMessage(jid, text, quotedMsg = null) {
    if (!sock) throw new Error('WhatsApp belum terhubung');
    await sock.sendPresenceUpdate('composing', jid);
    const delay = Math.floor(Math.random() * 1500) + 1000;
    await new Promise((r) => setTimeout(r, delay));
    await sock.sendPresenceUpdate('paused', jid);

    const options = quotedMsg ? { quoted: quotedMsg } : {};
    const result = await sock.sendMessage(jid, { text }, options);

    let targetDisplay = jid;
    if (jid.endsWith('@g.us')) {
        try {
            const gMeta = await sock.groupMetadata(jid);
            targetDisplay = `Grup: ${gMeta.subject}`;
        } catch {
            targetDisplay = 'Grup WA';
        }
    }

    addLog('OUT', 'Bot Aptika', targetDisplay, text);
    return result;
}

async function connectToWhatsApp(onMessageCallback) {
    const { state, saveCreds } = await useMultiFileAuthState('./auth_info_baileys');

    sock = makeWASocket({
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            connectionStatus = 'qr';
            qrcodeTerminal.generate(qr, { small: true });
            currentQrDataUrl = await QRCode.toDataURL(qr);
        }

        if (connection === 'close') {
            currentQrDataUrl = null;
            connectedUser = null;
            connectionStatus = 'disconnected';
            const shouldReconnect = lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut;
            if (shouldReconnect) {
                setTimeout(() => connectToWhatsApp(onMessageCallback), 3000);
            }
        } else if (connection === 'open') {
            connectionStatus = 'connected';
            currentQrDataUrl = null;
            connectedUser = sock.user;
            console.log('✅ Bot WhatsApp Aptika Tools berhasil terhubung!');
        }
    });

    sock.ev.on('messages.upsert', async (m) => {
        const msg = m.messages[0];
        if (!msg.message || msg.key.fromMe) return;

        const senderJid = msg.key.remoteJid;
        const pushName = msg.pushName || 'User';
        const text = (msg.message.conversation || msg.message.extendedTextMessage?.text || '').trim();

        if (text) {
            let senderDisplay = pushName;
            
            // Jika pesan datang dari grup (@g.us), ambil nama grupnya
            if (senderJid.endsWith('@g.us')) {
                try {
                    const groupMetadata = await sock.groupMetadata(senderJid);
                    senderDisplay = `${pushName} (${groupMetadata.subject})`;
                } catch {
                    senderDisplay = `${pushName} (Grup)`;
                }
            }

            addLog('IN', senderDisplay, senderJid, text);
            await onMessageCallback(msg, sendSafeMessage);
        }
    });
} // <-- Kurung penutup connectToWhatsApp yang tadinya kurang

function getBotState() {
    return {
        status: connectionStatus,
        qr: currentQrDataUrl,
        user: connectedUser,
        logs: messageLogs
    };
}

// Tambahan fungsi untuk mengambil instance socket Baileys (digunakan untuk fetch grup)
function getSockInstance() {
    return sock;
}

module.exports = { connectToWhatsApp, sendSafeMessage, getBotState, getSockInstance };