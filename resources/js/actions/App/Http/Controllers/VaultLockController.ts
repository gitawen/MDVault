import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultLockController::storeAll
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
export const storeAll = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeAll.url(options),
    method: 'post',
})

storeAll.definition = {
    methods: ["post"],
    url: '/vaults/lock',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultLockController::storeAll
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
storeAll.url = (options?: RouteQueryOptions) => {
    return storeAll.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultLockController::storeAll
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
storeAll.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeAll.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultLockController::storeAll
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
    const storeAllForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: storeAll.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultLockController::storeAll
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
        storeAllForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: storeAll.url(options),
            method: 'post',
        })
    
    storeAll.form = storeAllForm
/**
* @see \App\Http\Controllers\VaultLockController::store
 * @see app/Http/Controllers/VaultLockController.php:17
 * @route '/vaults/{vault}/lock'
 */
export const store = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/lock',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultLockController::store
 * @see app/Http/Controllers/VaultLockController.php:17
 * @route '/vaults/{vault}/lock'
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
* @see \App\Http\Controllers\VaultLockController::store
 * @see app/Http/Controllers/VaultLockController.php:17
 * @route '/vaults/{vault}/lock'
 */
store.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultLockController::store
 * @see app/Http/Controllers/VaultLockController.php:17
 * @route '/vaults/{vault}/lock'
 */
    const storeForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultLockController::store
 * @see app/Http/Controllers/VaultLockController.php:17
 * @route '/vaults/{vault}/lock'
 */
        storeForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
const VaultLockController = { storeAll, store }

export default VaultLockController