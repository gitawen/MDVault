import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\VaultChangeController::__invoke
 * @see app/Http/Controllers/VaultChangeController.php:15
 * @route '/vaults/{vault}/changes'
 */
export const check = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: check.url(args, options),
    method: 'post',
})

check.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/changes',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\VaultChangeController::__invoke
 * @see app/Http/Controllers/VaultChangeController.php:15
 * @route '/vaults/{vault}/changes'
 */
check.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return check.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\VaultChangeController::__invoke
 * @see app/Http/Controllers/VaultChangeController.php:15
 * @route '/vaults/{vault}/changes'
 */
check.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: check.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\VaultChangeController::__invoke
 * @see app/Http/Controllers/VaultChangeController.php:15
 * @route '/vaults/{vault}/changes'
 */
    const checkForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: check.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\VaultChangeController::__invoke
 * @see app/Http/Controllers/VaultChangeController.php:15
 * @route '/vaults/{vault}/changes'
 */
        checkForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: check.url(args, options),
            method: 'post',
        })
    
    check.form = checkForm
const changes = {
    check: Object.assign(check, check),
}

export default changes