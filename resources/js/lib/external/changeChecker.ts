export const CHECK_INTERVAL_MS = 5_000;
export const MAX_CHECK_INTERVAL_MS = 60_000;
export const TRIGGER_DEBOUNCE_MS = 250;
export const DURATION_FACTOR = 10;

export type CheckRunResult = 'ok' | 'skipped' | 'error' | 'stop';

export type ChangeChecker = {
    start(): void;
    trigger(): void;
    setActive(active: boolean): void;
    dispose(): void;
};

export type ChangeCheckerOptions = {
    run: () => Promise<CheckRunResult>;
    now?: () => number;
    intervalMs?: number;
    maxIntervalMs?: number;
    debounceMs?: number;
};

/**
 * A framework-free scheduler for the external-change check (ADR
 * `external-change-detection`, FR-09): a single check in flight at a time,
 * a debounced `trigger()`, an adaptive delay after each `ok` (scaled to how
 * long the check took), exponential back-off on `error`, and a pause while
 * `setActive(false)`.
 */
export function createChangeChecker(
    options: ChangeCheckerOptions,
): ChangeChecker {
    const intervalMs = options.intervalMs ?? CHECK_INTERVAL_MS;
    const maxIntervalMs = options.maxIntervalMs ?? MAX_CHECK_INTERVAL_MS;
    const debounceMs = options.debounceMs ?? TRIGGER_DEBOUNCE_MS;
    const now = options.now ?? (() => Date.now());

    let active = true;
    let disposed = false;
    let running = false;
    let runAgain = false;
    let lastErrorDelayMs: number | null = null;

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let nextRunTimer: ReturnType<typeof setTimeout> | null = null;

    function clearDebounceTimer(): void {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
    }

    function clearNextRunTimer(): void {
        if (nextRunTimer !== null) {
            clearTimeout(nextRunTimer);
            nextRunTimer = null;
        }
    }

    function scheduleNextRun(delayMs: number): void {
        clearNextRunTimer();

        if (disposed || !active) {
            return;
        }

        nextRunTimer = setTimeout(() => {
            void runNow();
        }, delayMs);
    }

    async function runNow(): Promise<void> {
        if (disposed || !active) {
            return;
        }

        if (running) {
            runAgain = true;

            return;
        }

        running = true;
        const startedAt = now();

        let result: CheckRunResult;
        try {
            result = await options.run();
        } catch {
            result = 'error';
        }

        running = false;

        if (disposed) {
            return;
        }

        if (result === 'stop') {
            dispose();

            return;
        }

        if (result === 'ok') {
            const duration = now() - startedAt;
            lastErrorDelayMs = null;
            scheduleNextRun(
                Math.min(
                    maxIntervalMs,
                    Math.max(intervalMs, duration * DURATION_FACTOR),
                ),
            );
        } else if (result === 'error') {
            const base = lastErrorDelayMs ?? intervalMs;
            lastErrorDelayMs = Math.min(maxIntervalMs, base * 2);
            scheduleNextRun(lastErrorDelayMs);
        } else {
            scheduleNextRun(intervalMs);
        }

        if (runAgain && active && !disposed) {
            runAgain = false;
            trigger();
        }
    }

    function trigger(): void {
        if (disposed || !active) {
            return;
        }

        clearDebounceTimer();
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            void runNow();
        }, debounceMs);
    }

    function start(): void {
        trigger();
    }

    function setActive(nextActive: boolean): void {
        if (disposed) {
            return;
        }

        const wasActive = active;
        active = nextActive;

        if (!active) {
            clearDebounceTimer();
            clearNextRunTimer();

            return;
        }

        if (!wasActive) {
            trigger();
        }
    }

    function dispose(): void {
        disposed = true;
        active = false;
        clearDebounceTimer();
        clearNextRunTimer();
    }

    return { start, trigger, setActive, dispose };
}
