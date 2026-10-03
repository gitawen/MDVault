import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\EncryptedVaultController::store
 * @see app/Http/Controllers/EncryptedVaultController.php:19
 * @route '/vaults/encrypted'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/encrypted',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\EncryptedVaultController::store
 * @see app/Http/Controllers/EncryptedVaultController.php:19
 * @route '/vaults/encrypted'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\EncryptedVaultController::store
 * @see app/Http/Controllers/EncryptedVaultController.php:19
 * @route '/vaults/encrypted'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\EncryptedVaultController::store
 * @see app/Http/Controllers/EncryptedVaultController.php:19
 * @route '/vaults/encrypted'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\EncryptedVaultController::store
 * @see app/Http/Controllers/EncryptedVaultController.php:19
 * @route '/vaults/encrypted'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
const EncryptedVaultController = { store }

export default EncryptedVaultController