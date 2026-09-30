import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
export const copy = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: copy.url(args, options),
    method: 'post',
})

copy.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/notes/copy',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
copy.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { vault: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { vault: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    vault: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        vault: typeof args.vault === 'object'
                ? args.vault.uuid
                : args.vault,
                }

    return copy.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
copy.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: copy.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
    const copyForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: copy.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
        copyForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: copy.url(args, options),
            method: 'post',
        })
    
    copy.form = copyForm
/**
* @see \App\Http\Controllers\NoteController::store
 * @see app/Http/Controllers/NoteController.php:18
 * @route '/vaults/{vault}/notes'
 */
export const store = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/notes',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\NoteController::store
 * @see app/Http/Controllers/NoteController.php:18
 * @route '/vaults/{vault}/notes'
 */
store.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { vault: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { vault: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    vault: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        vault: typeof args.vault === 'object'
                ? args.vault.uuid
                : args.vault,
                }

    return store.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteController::store
 * @see app/Http/Controllers/NoteController.php:18
 * @route '/vaults/{vault}/notes'
 */
store.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\NoteController::store
 * @see app/Http/Controllers/NoteController.php:18
 * @route '/vaults/{vault}/notes'
 */
    const storeForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteController::store
 * @see app/Http/Controllers/NoteController.php:18
 * @route '/vaults/{vault}/notes'
 */
        storeForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
const notes = {
    copy: Object.assign(copy, copy),
store: Object.assign(store, store),
}

export default notes