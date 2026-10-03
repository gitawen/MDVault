import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
export const workspace = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})

workspace.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
workspace.url = (options?: RouteQueryOptions) => {
    return workspace.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
workspace.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
workspace.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspace.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
    const workspaceForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: workspace.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
        workspaceForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: workspace.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:26
 * @route '/'
 */
        workspaceForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: workspace.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    workspace.form = workspaceForm