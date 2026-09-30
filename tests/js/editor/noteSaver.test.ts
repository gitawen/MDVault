import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    AUTOSAVE_DELAY_MS,
    AUTOSAVE_MAX_WAIT_MS,
    createNoteSaver,
    type NoteSaverState,
    type SaveOutcome,
} from '../../../resources/js/lib/editor/noteSaver';

function saved(
    overrides: Partial<Extract<SaveOutcome, { kind: 'saved' }>> = {},
): SaveOutcome {
    return {
        kind: 'saved',
        saved: true,
        fileHash: 'hash-1',
        fileSize: 10,
        updatedAt: null,
        ...overrides,
    };
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('createNoteSaver', () => {
    it('sends nothing before the idle delay and exactly one send after it', async () => {
        let content = 'a';
        const send = vi.fn().mockResolvedValue(saved());
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        content = 'b';
        saver.notifyChange();

        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS - 1);
        expect(send).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenCalledWith('b', 'base');
    });

    it('sends at most every 10s under continuous typing', async () => {
        let content = 'a';
        const send = vi.fn().mockResolvedValue(saved());
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        for (let elapsed = 0; elapsed < 12_000; elapsed += 500) {
            content += 'x';
            saver.notifyChange();
            await vi.advanceTimersByTimeAsync(500);
        }

        expect(send).toHaveBeenCalledTimes(1);
    });

    it('does not send when the content equals the baseline, and reports clean', async () => {
        const content = 'a';
        const send = vi.fn().mockResolvedValue(saved());
        const captured: { state: NoteSaverState | null } = { state: null };
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
            onState: (state) => {
                captured.state = state;
            },
        });

        saver.notifyChange();
        const result = await saver.flush();

        expect(result).toBe('clean');
        expect(send).not.toHaveBeenCalled();
        expect(captured.state?.status).toBe('clean');
    });

    it('sends a second time immediately after the first resolves when changed during the flight, using the new base hash', async () => {
        let content = 'b';
        let resolveFirst: (outcome: SaveOutcome) => void = () => {};
        const send = vi.fn().mockImplementation(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenNthCalledWith(1, 'b', 'base');

        // A further change arrives while the first save is still in flight.
        content = 'c';
        saver.notifyChange();

        resolveFirst(saved({ fileHash: 'hash-after-first' }));
        // No further timer advance: the loop must notice 'c' is still
        // unsent and send it right away, with no extra debounce wait.
        await vi.advanceTimersByTimeAsync(0);

        expect(send).toHaveBeenCalledTimes(2);
        expect(send).toHaveBeenNthCalledWith(2, 'c', 'hash-after-first');
    });

    it('pauses autosave on a conflict, and overwrite() resends with the current hash', async () => {
        let content = 'b';
        const send = vi
            .fn()
            .mockResolvedValueOnce({
                kind: 'conflict',
                reason: 'changed',
                currentHash: 'disk-hash',
                message: 'changed outside MDVault',
            } satisfies SaveOutcome)
            .mockResolvedValue(saved({ fileHash: 'hash-after-overwrite' }));
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(saver.getState().status).toBe('conflict');

        // Further typing while conflicted must not trigger another send.
        content = 'c';
        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);

        const outcome = await saver.overwrite();

        expect(outcome).toBe('saved');
        expect(send).toHaveBeenCalledTimes(2);
        expect(send).toHaveBeenNthCalledWith(2, 'c', 'disk-hash');
        expect(saver.getState().status).toBe('saved');
    });

    it('reports an error and sends again on the next change', async () => {
        let content = 'b';
        const send = vi
            .fn()
            .mockResolvedValueOnce({
                kind: 'error',
                message: 'network down',
            } satisfies SaveOutcome)
            .mockResolvedValue(saved());
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(saver.getState().status).toBe('error');

        content = 'c';
        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);

        expect(send).toHaveBeenCalledTimes(2);
        expect(saver.getState().status).toBe('saved');
    });

    it('discard() clears dirtiness and prevents a pending send', async () => {
        const content = 'b';
        const send = vi.fn().mockResolvedValue(saved());
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        saver.discard();

        expect(saver.isDirty()).toBe(false);

        await vi.advanceTimersByTimeAsync(AUTOSAVE_MAX_WAIT_MS);
        expect(send).not.toHaveBeenCalled();
    });

    it('flush() on a clean saver sends nothing and returns clean', async () => {
        const send = vi.fn().mockResolvedValue(saved());
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => 'a',
            send,
        });

        const result = await saver.flush();

        expect(result).toBe('clean');
        expect(send).not.toHaveBeenCalled();
    });

    // --- QA-01 regression coverage -----------------------------------------
    // A caller (the unsaved-changes guard, Ctrl/Cmd+S, a mode switch) must
    // never see flush() resolve while content typed during an in-flight
    // save is still unsent, and dispose() right after must never drop it.

    it('QA-01: flush() awaits an in-flight save, sends content typed during it, and dispose() after does not drop it', async () => {
        let content = 'a';
        let resolveFirst: (outcome: SaveOutcome) => void = () => {};
        const send = vi.fn().mockImplementationOnce(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        send.mockResolvedValue(saved({ fileHash: 'hash-after-c' }));

        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: '',
            readContent: () => content,
            send,
        });

        // 1. edit "a"; the autosave is in flight.
        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenNthCalledWith(1, 'a', 'base');

        // 2. edit "c" while that save is still unresolved.
        content = 'c';
        saver.notifyChange();

        // 3. flush(), then dispose() — the caller navigating away or the
        // component unmounting, exactly as useUnsavedChangesGuard and
        // NoteEditor's onUnmounted do.
        const flushResult = saver.flush();
        resolveFirst(saved({ fileHash: 'hash-after-a' }));
        const outcome = await flushResult;
        saver.dispose();

        // 4. assert "c" was sent — not dropped by dispose().
        expect(outcome).toBe('saved');
        expect(send).toHaveBeenCalledTimes(2);
        expect(send).toHaveBeenNthCalledWith(2, 'c', 'hash-after-a');
        expect(saver.isDirty()).toBe(false);

        // No further sends happen after dispose(), even if content changes
        // again (a disposed saver is inert).
        content = 'd';
        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_MAX_WAIT_MS);
        expect(send).toHaveBeenCalledTimes(2);
    });

    it('QA-01: flush() drains repeated edits made during the flush itself, one send per edit', async () => {
        let content = 'a';
        const resolvers: Array<(outcome: SaveOutcome) => void> = [];
        const send = vi.fn().mockImplementation(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolvers.push(resolve);
                }),
        );

        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: '',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenNthCalledWith(1, 'a', 'base');

        const flushResult = saver.flush();

        // Edit again while the first send (inside the flush loop) is
        // in flight, then let it resolve.
        content = 'b';
        saver.notifyChange();
        resolvers[0](saved({ fileHash: 'hash-a' }));
        await vi.advanceTimersByTimeAsync(0);
        expect(send).toHaveBeenCalledTimes(2);
        expect(send).toHaveBeenNthCalledWith(2, 'b', 'hash-a');

        // Edit yet again while the loop's second send is in flight.
        content = 'c';
        saver.notifyChange();
        resolvers[1](saved({ fileHash: 'hash-b' }));
        await vi.advanceTimersByTimeAsync(0);
        expect(send).toHaveBeenCalledTimes(3);
        expect(send).toHaveBeenNthCalledWith(3, 'c', 'hash-b');

        // Nothing more to send: the third send resolves and the loop exits.
        resolvers[2](saved({ fileHash: 'hash-c' }));
        const outcome = await flushResult;

        expect(outcome).toBe('saved');
        expect(send).toHaveBeenCalledTimes(3);
        expect(saver.isDirty()).toBe(false);
    });

    it('QA-01: a failure mid-loop stops the loop and reports failed, without dropping the unsent edit into a silent retry', async () => {
        let content = 'a';
        let resolveFirst: (outcome: SaveOutcome) => void = () => {};
        const send = vi.fn().mockImplementationOnce(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        send.mockResolvedValueOnce({
            kind: 'error',
            message: 'network down',
        } satisfies SaveOutcome);

        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: '',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);

        content = 'c';
        saver.notifyChange();

        const flushResult = saver.flush();
        resolveFirst(saved({ fileHash: 'hash-after-a' }));
        const outcome = await flushResult;

        expect(outcome).toBe('failed');
        expect(send).toHaveBeenCalledTimes(2);
        expect(send).toHaveBeenNthCalledWith(2, 'c', 'hash-after-a');
        expect(saver.getState().status).toBe('error');
        // The failed edit is still considered dirty/unsaved — the saver
        // does not silently swallow it, and the next change resends it.
        expect(saver.isDirty()).toBe(true);
    });

    it('R2-02: dispose() during an in-flight send stops the loop from sending again once it resolves', async () => {
        let content = 'a';
        let resolveFirst: (outcome: SaveOutcome) => void = () => {};
        const send = vi.fn().mockImplementationOnce(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolveFirst = resolve;
                }),
        );

        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: '',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(send).toHaveBeenCalledTimes(1);

        // Content changes again while the send is in flight, and the
        // caller disposes without awaiting flush() first (the misuse case
        // — the documented contract is to await flush() before dispose()).
        content = 'c';
        saver.notifyChange();
        saver.dispose();

        resolveFirst(saved({ fileHash: 'hash-after-a' }));
        await vi.advanceTimersByTimeAsync(0);

        // The loop must not start a second send once the first resolves.
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('externalConflict pauses autosave, marks the saver dirty, and overwrite() uses its hash', async () => {
        let content = 'b';
        const send = vi
            .fn()
            .mockResolvedValue(saved({ fileHash: 'hash-after-overwrite' }));
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.notifyChange();
        saver.externalConflict(
            'changed',
            'disk-hash',
            'changed outside MDVault',
        );

        expect(saver.getState().status).toBe('conflict');
        expect(saver.getState().conflict).toEqual({
            reason: 'changed',
            currentHash: 'disk-hash',
        });
        expect(saver.isDirty()).toBe(true);

        // No send fires even once the idle/max-wait timers would have.
        await vi.advanceTimersByTimeAsync(AUTOSAVE_MAX_WAIT_MS);
        expect(send).not.toHaveBeenCalled();

        content = 'c';
        const outcome = await saver.overwrite();

        expect(outcome).toBe('saved');
        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenCalledWith('c', 'disk-hash');
    });

    it('externalConflict is ignored while a save is already in flight', async () => {
        let resolveSend: (outcome: SaveOutcome) => void = () => {};
        const send = vi.fn().mockImplementationOnce(
            () =>
                new Promise<SaveOutcome>((resolve) => {
                    resolveSend = resolve;
                }),
        );
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => 'b',
            send,
        });

        saver.notifyChange();
        await vi.advanceTimersByTimeAsync(AUTOSAVE_DELAY_MS);
        expect(saver.getState().status).toBe('saving');

        saver.externalConflict('changed', 'disk-hash', 'ignored while saving');
        expect(saver.getState().status).toBe('saving');

        resolveSend(saved());
        await vi.advanceTimersByTimeAsync(0);

        expect(saver.getState().status).toBe('saved');
    });

    it('resume() clears a missing conflict and flushes the pending edit', async () => {
        let content = 'a';
        const send = vi
            .fn()
            .mockResolvedValue(saved({ fileHash: 'hash-after-resume' }));
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => content,
            send,
        });

        saver.externalConflict(
            'missing',
            null,
            'moved or deleted outside MDVault',
        );
        expect(saver.getState().status).toBe('conflict');

        content = 'b';
        saver.notifyChange();

        const result = await saver.resume();

        expect(result).toBe('saved');
        expect(saver.getState().conflict).toBeNull();
        expect(send).toHaveBeenCalledWith('b', 'base');
    });

    it('resume() on a clean saver resolves clean with no send', async () => {
        const send = vi.fn();
        const saver = createNoteSaver({
            baseHash: 'base',
            baseline: 'a',
            readContent: () => 'a',
            send,
        });

        const result = await saver.resume();

        expect(result).toBe('clean');
        expect(send).not.toHaveBeenCalled();
    });
});
