import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    createChangeChecker,
    type CheckRunResult,
} from '../../../resources/js/lib/external/changeChecker';

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('createChangeChecker', () => {
    it('debounces several triggers into a single run', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValue('ok');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 250,
        });

        checker.trigger();
        await vi.advanceTimersByTimeAsync(100);
        checker.trigger();
        await vi.advanceTimersByTimeAsync(100);
        checker.trigger();
        await vi.advanceTimersByTimeAsync(250);

        expect(run).toHaveBeenCalledTimes(1);
    });

    it('never runs more than once at a time, and runs exactly once more after a trigger during an in-flight run', async () => {
        let resolveRun: (value: CheckRunResult) => void = () => {};
        const run = vi.fn<() => Promise<CheckRunResult>>(
            () =>
                new Promise<CheckRunResult>((resolve) => {
                    resolveRun = resolve;
                }),
        );
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        // Triggered again while the first run is still in flight.
        checker.trigger();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        resolveRun('ok');
        await vi.advanceTimersByTimeAsync(0);

        expect(run).toHaveBeenCalledTimes(2);
    });

    it('runs again automatically after the adaptive delay elapses (the interval loop)', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValue('ok');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(4_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('scales the delay to 10x a 2s run, floored at the interval', async () => {
        let clock = 0;
        const run = vi.fn<() => Promise<CheckRunResult>>(async () => {
            clock += 2_000;

            return 'ok';
        });
        const checker = createChangeChecker({
            run,
            now: () => clock,
            intervalMs: 5_000,
            maxIntervalMs: 60_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(19_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('caps the delay at maxIntervalMs for a 10s run', async () => {
        let clock = 0;
        const run = vi.fn<() => Promise<CheckRunResult>>(async () => {
            clock += 10_000;

            return 'ok';
        });
        const checker = createChangeChecker({
            run,
            now: () => clock,
            intervalMs: 5_000,
            maxIntervalMs: 60_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(59_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('doubles the delay on each error, and resets to the plain interval after the next ok', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValueOnce('error')
            .mockResolvedValueOnce('error')
            .mockResolvedValueOnce('ok')
            .mockResolvedValue('ok');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            maxIntervalMs: 60_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        // 1st error: delay = 5000 * 2 = 10000.
        await vi.advanceTimersByTimeAsync(9_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);

        // 2nd error: delay = 10000 * 2 = 20000.
        await vi.advanceTimersByTimeAsync(19_999);
        expect(run).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(3);

        // ok: delay resets to the plain interval (5000).
        await vi.advanceTimersByTimeAsync(4_999);
        expect(run).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(4);
    });

    it('keeps the plain interval delay after a skipped run', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValue('skipped');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(4_999);
        expect(run).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('stop disposes the checker: no further automatic runs, and later triggers are ignored', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValue('stop');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        checker.trigger();
        await vi.advanceTimersByTimeAsync(100_000);
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('setActive(false) ignores triggers, and setActive(true) triggers immediately', async () => {
        const run = vi
            .fn<() => Promise<CheckRunResult>>()
            .mockResolvedValue('ok');
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.setActive(false);
        checker.trigger();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(run).not.toHaveBeenCalled();

        checker.setActive(true);
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);
    });

    it('ignores a late result that resolves after dispose', async () => {
        let resolveRun: (value: CheckRunResult) => void = () => {};
        const run = vi.fn<() => Promise<CheckRunResult>>(
            () =>
                new Promise<CheckRunResult>((resolve) => {
                    resolveRun = resolve;
                }),
        );
        const checker = createChangeChecker({
            run,
            intervalMs: 5_000,
            debounceMs: 0,
        });

        checker.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        checker.dispose();
        resolveRun('ok');
        await vi.advanceTimersByTimeAsync(100_000);

        expect(run).toHaveBeenCalledTimes(1);
    });
});
