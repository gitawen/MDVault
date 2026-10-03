import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultLockController::all
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
export const all = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: all.url(options),
    method: 'post',
})

all.definition = {
    methods: ["post"],
    url: '/vaults/lock',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultLockController::all
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
all.url = (options?: RouteQueryOptions) => {
    return all.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultLockController::all
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
all.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: all.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultLockController::all
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
    const allForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: all.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultLockController::all
 * @see app/Http/Controllers/VaultLockController.php:30
 * @route '/vaults/lock'
 */
        allForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: all.url(options),
            method: 'post',
        })
    
    all.form = allForm