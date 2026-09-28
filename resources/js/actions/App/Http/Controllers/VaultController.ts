import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/vaults',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\VaultController::index
 * @see app/Http/Controllers/VaultController.php:22
 * @route '/vaults'
 */
        indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    index.form = indexForm
/**
* @see \App\Http\Controllers\VaultController::store
 * @see app/Http/Controllers/VaultController.php:31
 * @route '/vaults'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultController::store
 * @see app/Http/Controllers/VaultController.php:31
 * @route '/vaults'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::store
 * @see app/Http/Controllers/VaultController.php:31
 * @route '/vaults'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultController::store
 * @see app/Http/Controllers/VaultController.php:31
 * @route '/vaults'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultController::store
 * @see app/Http/Controllers/VaultController.php:31
 * @route '/vaults'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\VaultController::close
 * @see app/Http/Controllers/VaultController.php:104
 * @route '/vaults/close'
 */
export const close = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(options),
    method: 'post',
})

close.definition = {
    methods: ["post"],
    url: '/vaults/close',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultController::close
 * @see app/Http/Controllers/VaultController.php:104
 * @route '/vaults/close'
 */
close.url = (options?: RouteQueryOptions) => {
    return close.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::close
 * @see app/Http/Controllers/VaultController.php:104
 * @route '/vaults/close'
 */
close.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: close.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultController::close
 * @see app/Http/Controllers/VaultController.php:104
 * @route '/vaults/close'
 */
    const closeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: close.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultController::close
 * @see app/Http/Controllers/VaultController.php:104
 * @route '/vaults/close'
 */
        closeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: close.url(options),
            method: 'post',
        })
    
    close.form = closeForm
/**
* @see \App\Http\Controllers\VaultController::update
 * @see app/Http/Controllers/VaultController.php:48
 * @route '/vaults/{vault}'
 */
export const update = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/vaults/{vault}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\VaultController::update
 * @see app/Http/Controllers/VaultController.php:48
 * @route '/vaults/{vault}'
 */
update.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::update
 * @see app/Http/Controllers/VaultController.php:48
 * @route '/vaults/{vault}'
 */
update.patch = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\VaultController::update
 * @see app/Http/Controllers/VaultController.php:48
 * @route '/vaults/{vault}'
 */
    const updateForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultController::update
 * @see app/Http/Controllers/VaultController.php:48
 * @route '/vaults/{vault}'
 */
        updateForm.patch = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\VaultController::destroy
 * @see app/Http/Controllers/VaultController.php:63
 * @route '/vaults/{vault}'
 */
export const destroy = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/vaults/{vault}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\VaultController::destroy
 * @see app/Http/Controllers/VaultController.php:63
 * @route '/vaults/{vault}'
 */
destroy.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::destroy
 * @see app/Http/Controllers/VaultController.php:63
 * @route '/vaults/{vault}'
 */
destroy.delete = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\VaultController::destroy
 * @see app/Http/Controllers/VaultController.php:63
 * @route '/vaults/{vault}'
 */
    const destroyForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultController::destroy
 * @see app/Http/Controllers/VaultController.php:63
 * @route '/vaults/{vault}'
 */
        destroyForm.delete = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
/**
* @see \App\Http\Controllers\VaultController::open
 * @see app/Http/Controllers/VaultController.php:81
 * @route '/vaults/{vault}/open'
 */
export const open = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: open.url(args, options),
    method: 'post',
})

open.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/open',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultController::open
 * @see app/Http/Controllers/VaultController.php:81
 * @route '/vaults/{vault}/open'
 */
open.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return open.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultController::open
 * @see app/Http/Controllers/VaultController.php:81
 * @route '/vaults/{vault}/open'
 */
open.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: open.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultController::open
 * @see app/Http/Controllers/VaultController.php:81
 * @route '/vaults/{vault}/open'
 */
    const openForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: open.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultController::open
 * @see app/Http/Controllers/VaultController.php:81
 * @route '/vaults/{vault}/open'
 */
        openForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: open.url(args, options),
            method: 'post',
        })
    
    open.form = openForm
const VaultController = { index, store, close, update, destroy, open }

export default VaultController