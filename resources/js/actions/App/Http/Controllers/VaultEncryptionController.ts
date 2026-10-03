import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultEncryptionController::store
 * @see app/Http/Controllers/VaultEncryptionController.php:23
 * @route '/vaults/{vault}/encryption'
 */
export const store = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/encryption',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultEncryptionController::store
 * @see app/Http/Controllers/VaultEncryptionController.php:23
 * @route '/vaults/{vault}/encryption'
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
* @see \App\Http\Controllers\VaultEncryptionController::store
 * @see app/Http/Controllers/VaultEncryptionController.php:23
 * @route '/vaults/{vault}/encryption'
 */
store.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultEncryptionController::store
 * @see app/Http/Controllers/VaultEncryptionController.php:23
 * @route '/vaults/{vault}/encryption'
 */
    const storeForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultEncryptionController::store
 * @see app/Http/Controllers/VaultEncryptionController.php:23
 * @route '/vaults/{vault}/encryption'
 */
        storeForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\VaultEncryptionController::destroy
 * @see app/Http/Controllers/VaultEncryptionController.php:51
 * @route '/vaults/{vault}/encryption'
 */
export const destroy = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/vaults/{vault}/encryption',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\VaultEncryptionController::destroy
 * @see app/Http/Controllers/VaultEncryptionController.php:51
 * @route '/vaults/{vault}/encryption'
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
* @see \App\Http\Controllers\VaultEncryptionController::destroy
 * @see app/Http/Controllers/VaultEncryptionController.php:51
 * @route '/vaults/{vault}/encryption'
 */
destroy.delete = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\VaultEncryptionController::destroy
 * @see app/Http/Controllers/VaultEncryptionController.php:51
 * @route '/vaults/{vault}/encryption'
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
* @see \App\Http\Controllers\VaultEncryptionController::destroy
 * @see app/Http/Controllers/VaultEncryptionController.php:51
 * @route '/vaults/{vault}/encryption'
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
const VaultEncryptionController = { store, destroy }

export default VaultEncryptionController