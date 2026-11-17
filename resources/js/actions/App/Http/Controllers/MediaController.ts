import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\MediaController::store
 * @see app/Http/Controllers/MediaController.php:21
 * @route '/vaults/{vault}/media'
 */
export const store = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/media',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\MediaController::store
 * @see app/Http/Controllers/MediaController.php:21
 * @route '/vaults/{vault}/media'
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
* @see \App\Http\Controllers\MediaController::store
 * @see app/Http/Controllers/MediaController.php:21
 * @route '/vaults/{vault}/media'
 */
store.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\MediaController::store
 * @see app/Http/Controllers/MediaController.php:21
 * @route '/vaults/{vault}/media'
 */
    const storeForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\MediaController::store
 * @see app/Http/Controllers/MediaController.php:21
 * @route '/vaults/{vault}/media'
 */
        storeForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
export const show = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/media/{filename}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
show.url = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { filename: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    filename: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        filename: args.filename,
                }

    return show.definition.url
            .replace('{filename}', parsedArgs.filename.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
show.get = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
show.head = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
    const showForm = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
        showForm.get = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\MediaController::show
 * @see app/Http/Controllers/MediaController.php:45
 * @route '/media/{filename}'
 */
        showForm.head = (args: { filename: string | number } | [filename: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
const MediaController = { store, show }

export default MediaController