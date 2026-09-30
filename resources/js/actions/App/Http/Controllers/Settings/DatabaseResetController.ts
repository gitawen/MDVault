import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\DatabaseResetController::destroy
 * @see app/Http/Controllers/Settings/DatabaseResetController.php:14
 * @route '/settings/backup/database'
 */
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/backup/database',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\DatabaseResetController::destroy
 * @see app/Http/Controllers/Settings/DatabaseResetController.php:14
 * @route '/settings/backup/database'
 */
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\DatabaseResetController::destroy
 * @see app/Http/Controllers/Settings/DatabaseResetController.php:14
 * @route '/settings/backup/database'
 */
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\Settings\DatabaseResetController::destroy
 * @see app/Http/Controllers/Settings/DatabaseResetController.php:14
 * @route '/settings/backup/database'
 */
    const destroyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: destroy.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\DatabaseResetController::destroy
 * @see app/Http/Controllers/Settings/DatabaseResetController.php:14
 * @route '/settings/backup/database'
 */
        destroyForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: destroy.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    destroy.form = destroyForm
const DatabaseResetController = { destroy }

export default DatabaseResetController