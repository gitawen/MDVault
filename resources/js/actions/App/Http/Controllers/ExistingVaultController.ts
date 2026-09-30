import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ExistingVaultController::store
 * @see app/Http/Controllers/ExistingVaultController.php:20
 * @route '/vaults/existing'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/vaults/existing',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ExistingVaultController::store
 * @see app/Http/Controllers/ExistingVaultController.php:20
 * @route '/vaults/existing'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ExistingVaultController::store
 * @see app/Http/Controllers/ExistingVaultController.php:20
 * @route '/vaults/existing'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ExistingVaultController::store
 * @see app/Http/Controllers/ExistingVaultController.php:20
 * @route '/vaults/existing'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ExistingVaultController::store
 * @see app/Http/Controllers/ExistingVaultController.php:20
 * @route '/vaults/existing'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\ExistingVaultController::browse
 * @see app/Http/Controllers/ExistingVaultController.php:44
 * @route '/vaults/existing/browse'
 */
export const browse = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

browse.definition = {
    methods: ["post"],
    url: '/vaults/existing/browse',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ExistingVaultController::browse
 * @see app/Http/Controllers/ExistingVaultController.php:44
 * @route '/vaults/existing/browse'
 */
browse.url = (options?: RouteQueryOptions) => {
    return browse.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ExistingVaultController::browse
 * @see app/Http/Controllers/ExistingVaultController.php:44
 * @route '/vaults/existing/browse'
 */
browse.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\ExistingVaultController::browse
 * @see app/Http/Controllers/ExistingVaultController.php:44
 * @route '/vaults/existing/browse'
 */
    const browseForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: browse.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\ExistingVaultController::browse
 * @see app/Http/Controllers/ExistingVaultController.php:44
 * @route '/vaults/existing/browse'
 */
        browseForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: browse.url(options),
            method: 'post',
        })
    
    browse.form = browseForm
const ExistingVaultController = { store, browse }

export default ExistingVaultController