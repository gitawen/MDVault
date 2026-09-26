import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
const WorkspaceController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})

WorkspaceController.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
WorkspaceController.url = (options?: RouteQueryOptions) => {
    return WorkspaceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
WorkspaceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
WorkspaceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
    const WorkspaceControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: WorkspaceController.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
        WorkspaceControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:16
 * @route '/'
 */
        WorkspaceControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    WorkspaceController.form = WorkspaceControllerForm
export default WorkspaceController