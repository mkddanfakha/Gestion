import os from 'node:os';

const PRIVATE_IPV4 =
    /^(?:10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})$/;

function isIPv4(family: string | number): boolean {
    return family === 'IPv4' || family === 4;
}

/**
 * Détecte l'IPv4 privée (RFC 1918) la plus probable pour l'accès LAN (Wi‑Fi / Ethernet).
 * Aucune adresse en dur — recalculée à chaque démarrage de Vite (DHCP OK).
 */
export function detectLanIPv4(): string | null {
    const candidates: Array<{ address: string; score: number }> = [];

    for (const [name, nets] of Object.entries(os.networkInterfaces())) {
        if (!nets) {
            continue;
        }

        const iface = name.toLowerCase();
        let score = 0;

        if (/wi-?fi|wlan|wireless/.test(iface)) {
            score += 20;
        } else if (/ethernet|eth/.test(iface)) {
            score += 10;
        }

        if (/virtual|vethernet|vmware|hyper-v|docker|wsl|loopback|vbox|npcap|bluetooth/.test(iface)) {
            score -= 30;
        }

        for (const net of nets) {
            if (!isIPv4(net.family) || net.internal) {
                continue;
            }

            if (!PRIVATE_IPV4.test(net.address)) {
                continue;
            }

            candidates.push({ address: net.address, score });
        }
    }

    if (candidates.length === 0) {
        return null;
    }

    candidates.sort((a, b) => b.score - a.score);

    return candidates[0].address;
}

export function resolveDevServerHost(preferred?: string): string {
    if (preferred && preferred !== 'localhost' && preferred !== '127.0.0.1') {
        return preferred;
    }

    return detectLanIPv4() ?? 'localhost';
}
