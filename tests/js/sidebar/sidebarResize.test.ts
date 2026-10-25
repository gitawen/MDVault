import { describe, expect, it } from 'vite-plus/test';
import {
    SIDEBAR_WIDTH,
    SIDEBAR_WIDTH_MIN,
    SIDEBAR_WIDTH_MAX,
    SIDEBAR_WIDTH_COOKIE_NAME,
    SIDEBAR_WIDTH_STORAGE_KEY,
} from '@/components/ui/sidebar/utils';

describe('Sidebar Resize Configuration', () => {
    it('defines standard dimensions and constraints', () => {
        expect(SIDEBAR_WIDTH).toBe('16rem');
        expect(SIDEBAR_WIDTH_MIN).toBe(200);
        expect(SIDEBAR_WIDTH_MAX).toBe(800);
        expect(SIDEBAR_WIDTH_COOKIE_NAME).toBe('sidebar_width');
        expect(SIDEBAR_WIDTH_STORAGE_KEY).toBe('sidebar_width');
    });

    it('ensures minimum width allows readable navigation while fitting compact screens', () => {
        expect(SIDEBAR_WIDTH_MIN).toBeLessThanOrEqual(256);
        expect(SIDEBAR_WIDTH_MAX).toBeGreaterThan(500);
    });
});
