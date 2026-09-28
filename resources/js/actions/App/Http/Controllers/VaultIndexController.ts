import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultIndexController::__invoke
 * @see app/Http/Controllers/VaultIndexController.php:16
 * @route '/vaults/{vault}/reindex'
 */
const VaultIndexController = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: VaultIndexController.url(args, options),
    method: 'post',
})

VaultIndexController.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/reindex',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultIndexController::__invoke
 * @see app/Http/Controllers/VaultIndexController.php:16
 * @route '/vaults/{vault}/reindex'
 */
VaultIndexController.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return VaultIndexController.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultIndexController::__invoke
 * @see app/Http/Controllers/VaultIndexController.php:16
 * @route '/vaults/{vault}/reindex'
 */
VaultIndexController.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: VaultIndexController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultIndexController::__invoke
 * @see app/Http/Controllers/VaultIndexController.php:16
 * @route '/vaults/{vault}/reindex'
 */
    const VaultIndexControllerForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: VaultIndexController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultIndexController::__invoke
 * @see app/Http/Controllers/VaultIndexController.php:16
 * @route '/vaults/{vault}/reindex'
 */
        VaultIndexControllerForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: VaultIndexController.url(args, options),
            method: 'post',
        })
    
    VaultIndexController.form = VaultIndexControllerForm
export default VaultIndexController