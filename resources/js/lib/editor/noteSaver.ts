export const AUTOSAVE_DELAY_MS = 1500;
export const AUTOSAVE_MAX_WAIT_MS = 10_000;

export type SaveOutcome =
    | {
          kind: 'saved';
          saved: boolean;
          fileHash: string;
          fileSize: number;
          updatedAt: string | null;
      }
    | {
          kind: 'conflict';
          reason: 'changed' | 'missing';
          currentHash: string | null;
          message: string;
      }
    | { kind: 'error'; message: string };

export type NoteSaverStatus =
    | 'clean'
    | 'dirty'
    | 'saving'
    | 'saved'
    | 'conflict'
    | 'error';

export type NoteSaverState = {
    status: NoteSaverStatus;
    baseHash: string;
    lastSavedAt: Date | null;
    message: string | null;
    conflict: {
        reason: 'changed' | 'missing';
        currentHash: string | null;
    } | null;
};

export type NoteSaver = {
    notifyChange(): void;
    /**
     * Awaits any save already in flight, then keeps sending until the
     * current content matches what was last sent — a caller can never
     * observe `'saved'`/`'clean'` while unsent edits still exist. Resolves
     * `'saved'` if at least one send happened, `'clean'` if nothing was
     * ever dirty, or `'failed'` as soon as any send in the chain fails
     * (the saver stops there; it does not retry on its own).
     */
    flush(): Promise<'clean' | 'saved' | 'failed'>;
    /** Only meaningful after a 'changed' conflict: resend with baseHash = conflict.currentHash. */
    overwrite(): Promise<'saved' | 'failed'>;
    /** Marks clean and stops timers (used by Reload and Discard-and-leave). */
    discard(): void;
    /** True while there is a pending change, an in-flight save, or an unsaved failure. */
    isDirty(): boolean;
    getState(): NoteSaverState;
    /**
     * Stops timers and detaches. This does **not** save first: it silently
     * drops any pending dirty content. Callers that care about that
     * content (every caller in this codebase does) must `await flush()`
     * before calling `dispose()` — see `useUnsavedChangesGuard` and
     * `NoteEditor.vue`'s `onUnmounted`. A dev-time warning fires if
     * `isDirty()` is still true when this is called.
     */
    dispose(): void;
};

export type NoteSaverOptions = {
    baseHash: string;
    baseline: string;
    readContent: () => string;
    send: (content: string, baseHash: string) => Promise<SaveOutcome>;
    onState?: (state: NoteSaverState) => void;
    delayMs?: number;
    maxWaitMs?: number;
};

/**
 * A framework-free autosave engine (ADR `note-save-atomic-replace`, FR-09):
 * debounced autosave (1.5 s idle, 10 s max wait), a single save in flight at
 * a time, hash chaining from one save to the next, and a conflict pause
 * that only `overwrite()` or `discard()` clears.
 *
 * `flush()` is a loop, not a single send (QA-01): once the content it just
 * sent no longer matches the current content — because the caller typed
 * more while that send was in flight — it sends again immediately, before
 * resolving. This is what lets `useUnsavedChangesGuard` and an explicit
 * Ctrl/Cmd+S safely `await flush()` and then navigate/dispose without
 * losing whatever was typed during a save that was already running.
 */
export function createNoteSaver(options: NoteSaverOptions): NoteSaver {
    const delayMs = options.delayMs ?? AUTOSAVE_DELAY_MS;
    const maxWaitMs = options.maxWaitMs ?? AUTOSAVE_MAX_WAIT_MS;

    let baseline = options.baseline;
    let baseHash = options.baseHash;
    let status: NoteSaverStatus = 'clean';
    let lastSavedAt: Date | null = null;
    let message: string | null = null;
    let conflict: NoteSaverState['conflict'] = null;

    let pending = false;
    let hadFailure = false;
    let disposed = false;

    /** The whole flush loop currently running, if any (single flight). */
    let flushPromise: Promise<'clean' | 'saved' | 'failed'> | null = null;

    let idleTimer: ReturnType<typeof setTimeout> | null = null;
    let maxWaitTimer: ReturnType<typeof setTimeout> | null = null;

    function clearTimers(): void {
        if (idleTimer !== null) {
            clearTimeout(idleTimer);
            idleTimer = null;
        }

        if (maxWaitTimer !== null) {
            clearTimeout(maxWaitTimer);
            maxWaitTimer = null;
        }
    }

    function getState(): NoteSaverState {
        return { status, baseHash, lastSavedAt, message, conflict };
    }

    function emitState(): void {
        options.onState?.(getState());
    }

    function scheduleTimers(): void {
        if (idleTimer !== null) {
            clearTimeout(idleTimer);
        }

        idleTimer = setTimeout(() => {
            void flush();
        }, delayMs);

        if (maxWaitTimer === null) {
            maxWaitTimer = setTimeout(() => {
                void flush();
            }, maxWaitMs);
        }
    }

    function markDirtyAndSchedule(): void {
        status = 'dirty';
        emitState();
        scheduleTimers();
    }

    function notifyChange(): void {
        if (disposed) {
            return;
        }

        pending = true;

        if (status === 'conflict') {
            return;
        }

        if (flushPromise !== null) {
            // A flush loop is already running and will notice this on its
            // own next iteration (it re-reads content after every send) —
            // no separate timer or "reschedule" bookkeeping is needed.
            return;
        }

        markDirtyAndSchedule();
    }

    /** A single send of whatever `readContent()` returns right now. */
    async function sendOnce(): Promise<'saved' | 'failed'> {
        const content = options.readContent();

        status = 'saving';
        emitState();

        const outcome = await options.send(content, baseHash);

        if (outcome.kind === 'saved') {
            baseline = content;
            baseHash = outcome.fileHash;
            lastSavedAt = new Date();
            hadFailure = false;
            status = 'saved';
            message = null;
            conflict = null;
            emitState();

            return 'saved';
        }

        hadFailure = true;

        if (outcome.kind === 'conflict') {
            status = 'conflict';
            message = outcome.message;
            conflict = {
                reason: outcome.reason,
                currentHash: outcome.currentHash,
            };
        } else {
            status = 'error';
            message = outcome.message;
        }

        emitState();

        return 'failed';
    }

    async function runFlushLoop(): Promise<'clean' | 'saved' | 'failed'> {
        clearTimers();

        let sentAnything = false;

        for (;;) {
            if (disposed) {
                // A dispose() that races an in-flight send (rather than
                // following the documented await-flush-first contract)
                // must not let the loop start another send once that one
                // resolves (R2-02).
                return 'failed';
            }

            const content = options.readContent();

            if (content === baseline) {
                pending = false;
                hadFailure = false;
                status = sentAnything || status === 'saved' ? 'saved' : 'clean';
                emitState();

                return sentAnything ? 'saved' : 'clean';
            }

            const result = await sendOnce();

            if (result === 'failed') {
                return 'failed';
            }

            sentAnything = true;
            // Loop again: readContent() may already differ from the
            // baseline we just set, if the caller typed more while that
            // send was in flight.
        }
    }

    function flush(): Promise<'clean' | 'saved' | 'failed'> {
        if (flushPromise !== null) {
            return flushPromise;
        }

        const promise = runFlushLoop().finally(() => {
            flushPromise = null;
        });

        flushPromise = promise;

        return promise;
    }

    async function overwrite(): Promise<'saved' | 'failed'> {
        if (
            conflict === null ||
            conflict.reason !== 'changed' ||
            conflict.currentHash === null
        ) {
            return 'failed';
        }

        baseHash = conflict.currentHash;
        conflict = null;
        pending = true;
        status = 'dirty';
        emitState();

        const result = await flush();

        return result === 'saved' ? 'saved' : 'failed';
    }

    function discard(): void {
        clearTimers();
        pending = false;
        hadFailure = false;
        status = 'clean';
        message = null;
        conflict = null;
        emitState();
    }

    function isDirty(): boolean {
        return pending || flushPromise !== null || hadFailure;
    }

    function dispose(): void {
        if (isDirty() && typeof console !== 'undefined') {
            console.warn(
                'noteSaver.dispose() was called while dirty; call and await flush() first or the pending edit will be lost.',
            );
        }

        disposed = true;
        clearTimers();
    }

    return {
        notifyChange,
        flush,
        overwrite,
        discard,
        isDirty,
        getState,
        dispose,
    };
}
