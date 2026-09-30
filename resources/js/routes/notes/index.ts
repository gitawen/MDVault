import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import content from './content'
import disk from './disk'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
export const show = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/notes/{note}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
show.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { note: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { note: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    note: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        note: typeof args.note === 'object'
                ? args.note.uuid
                : args.note,
                }

    return show.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
show.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
show.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
    const showForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
        showForm.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
        showForm.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
/**
* @see \App\Http\Controllers\NoteController::update
 * @see app/Http/Controllers/NoteController.php:27
 * @route '/notes/{note}'
 */
export const update = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/notes/{note}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\NoteController::update
 * @see app/Http/Controllers/NoteController.php:27
 * @route '/notes/{note}'
 */
update.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { note: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { note: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    note: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        note: typeof args.note === 'object'
                ? args.note.uuid
                : args.note,
                }

    return update.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteController::update
 * @see app/Http/Controllers/NoteController.php:27
 * @route '/notes/{note}'
 */
update.patch = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\NoteController::update
 * @see app/Http/Controllers/NoteController.php:27
 * @route '/notes/{note}'
 */
    const updateForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteController::update
 * @see app/Http/Controllers/NoteController.php:27
 * @route '/notes/{note}'
 */
        updateForm.patch = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    update.form = updateForm
/**
* @see \App\Http\Controllers\NoteController::move
 * @see app/Http/Controllers/NoteController.php:40
 * @route '/notes/{note}/move'
 */
export const move = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

move.definition = {
    methods: ["post"],
    url: '/notes/{note}/move',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\NoteController::move
 * @see app/Http/Controllers/NoteController.php:40
 * @route '/notes/{note}/move'
 */
move.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { note: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { note: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    note: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        note: typeof args.note === 'object'
                ? args.note.uuid
                : args.note,
                }

    return move.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteController::move
 * @see app/Http/Controllers/NoteController.php:40
 * @route '/notes/{note}/move'
 */
move.post = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: move.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\NoteController::move
 * @see app/Http/Controllers/NoteController.php:40
 * @route '/notes/{note}/move'
 */
    const moveForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: move.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteController::move
 * @see app/Http/Controllers/NoteController.php:40
 * @route '/notes/{note}/move'
 */
        moveForm.post = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: move.url(args, options),
            method: 'post',
        })
    
    move.form = moveForm
/**
* @see \App\Http\Controllers\NoteController::destroy
 * @see app/Http/Controllers/NoteController.php:56
 * @route '/notes/{note}'
 */
export const destroy = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/notes/{note}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\NoteController::destroy
 * @see app/Http/Controllers/NoteController.php:56
 * @route '/notes/{note}'
 */
destroy.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { note: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { note: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    note: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        note: typeof args.note === 'object'
                ? args.note.uuid
                : args.note,
                }

    return destroy.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteController::destroy
 * @see app/Http/Controllers/NoteController.php:56
 * @route '/notes/{note}'
 */
destroy.delete = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\NoteController::destroy
 * @see app/Http/Controllers/NoteController.php:56
 * @route '/notes/{note}'
 */
    const destroyForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteController::destroy
 * @see app/Http/Controllers/NoteController.php:56
 * @route '/notes/{note}'
 */
        destroyForm.delete = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
const notes = {
    show: Object.assign(show, show),
update: Object.assign(update, update),
content: Object.assign(content, content),
move: Object.assign(move, move),
destroy: Object.assign(destroy, destroy),
disk: Object.assign(disk, disk),
}

export default notes