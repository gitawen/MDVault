import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultUnlockController::store
 * @see app/Http/Controllers/VaultUnlockController.php:18
 * @route '/vaults/{vault}/unlock'
 */
export const store = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/unlock',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultUnlockController::store
 * @see app/Http/Controllers/VaultUnlockController.php:18
 * @route '/vaults/{vault}/unlock'
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
* @see \App\Http\Controllers\VaultUnlockController::store
 * @see app/Http/Controllers/VaultUnlockController.php:18
 * @route '/vaults/{vault}/unlock'
 */
store.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultUnlockController::store
 * @see app/Http/Controllers/VaultUnlockController.php:18
 * @route '/vaults/{vault}/unlock'
 */
    const storeForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultUnlockController::store
 * @see app/Http/Controllers/VaultUnlockController.php:18
 * @route '/vaults/{vault}/unlock'
 */
        storeForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
const VaultUnlockController = { store }

export default VaultUnlockController