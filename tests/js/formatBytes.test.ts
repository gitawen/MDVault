import { describe, expect, it } from 'vite-plus/test';
import { formatBytes } from '../../resources/js/lib/formatBytes';

describe('formatBytes', () => {
    it('formats bytes under 1024 without a decimal', () => {
        expect(formatBytes(0)).toBe('0 B');
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(1023)).toBe('1023 B');
    });

    it('formats kilobytes with one decimal place', () => {
        expect(formatBytes(1024)).toBe('1.0 KB');
        expect(formatBytes(1536)).toBe('1.5 KB');
    });

    it('formats megabytes and gigabytes', () => {
        expect(formatBytes(1024 * 1024)).toBe('1.0 MB');
        expect(formatBytes(1024 * 1024 * 1024)).toBe('1.0 GB');
    });

    it('stops scaling past GB', () => {
        expect(formatBytes(1024 * 1024 * 1024 * 1024)).toBe('1024.0 GB');
    });
});
