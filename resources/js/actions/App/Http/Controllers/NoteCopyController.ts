import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
const NoteCopyController = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NoteCopyController.url(args, options),
    method: 'post',
})

NoteCopyController.definition = {
    methods: ["post"],
    url: '/vaults/{vault}/notes/copy',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
NoteCopyController.url = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return NoteCopyController.definition.url
            .replace('{vault}', parsedArgs.vault.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
NoteCopyController.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NoteCopyController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
    const NoteCopyControllerForm = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: NoteCopyController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteCopyController::__invoke
 * @see app/Http/Controllers/NoteCopyController.php:18
 * @route '/vaults/{vault}/notes/copy'
 */
        NoteCopyControllerForm.post = (args: { vault: string | { uuid: string } } | [vault: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: NoteCopyController.url(args, options),
            method: 'post',
        })
    
    NoteCopyController.form = NoteCopyControllerForm
export default NoteCopyController