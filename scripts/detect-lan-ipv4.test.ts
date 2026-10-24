import os from 'node:os';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { detectLanIPv4, resolveDevServerHost } from './detect-lan-ipv4';

describe('resolveDevServerHost', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('honors explicit localhost without LAN detection', () => {
        vi.spyOn(os, 'networkInterfaces').mockReturnValue({
            WiFi: [
                {
                    address: '192.168.1.50',
                    netmask: '255.255.255.0',
                    family: 'IPv4',
                    mac: '00:00:00:00:00:00',
                    internal: false,
                    cidr: '192.168.1.50/24',
                },
            ],
        });

        expect(resolveDevServerHost('localhost')).toBe('localhost');
        expect(resolveDevServerHost('127.0.0.1')).toBe('127.0.0.1');
    });

    it('uses LAN IPv4 when no preference is set', () => {
        vi.spyOn(os, 'networkInterfaces').mockReturnValue({
            Ethernet: [
                {
                    address: '192.168.0.10',
                    netmask: '255.255.255.0',
                    family: 'IPv4',
                    mac: '00:00:00:00:00:00',
                    internal: false,
                    cidr: '192.168.0.10/24',
                },
            ],
        });

        expect(resolveDevServerHost()).toBe('192.168.0.10');
        expect(resolveDevServerHost('')).toBe('192.168.0.10');
    });

    it('returns a custom host when provided', () => {
        expect(resolveDevServerHost('10.0.0.5')).toBe('10.0.0.5');
    });
});

describe('detectLanIPv4', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns null when no private IPv4 is available', () => {
        vi.spyOn(os, 'networkInterfaces').mockReturnValue({});

        expect(detectLanIPv4()).toBeNull();
    });
});
