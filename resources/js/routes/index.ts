import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
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
 * @see app/Http/Controllers/WorkspaceController.php:14
 * @route '/'
 */
workspace.url = (options?: RouteQueryOptions) => {
    return workspace.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
 * @route '/'
 */
workspace.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
 * @route '/'
 */
workspace.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspace.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
 * @route '/'
 */
    const workspaceForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: workspace.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
 * @route '/'
 */
        workspaceForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: workspace.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:14
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
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
export const settings = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(options),
    method: 'get',
})

settings.definition = {
    methods: ["get","head","post","put","patch","delete","options"],
    url: '/settings',
} satisfies RouteDefinition<["get","head","post","put","patch","delete","options"]>

/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.url = (options?: RouteQueryOptions) => {
    return settings.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: settings.url(options),
    method: 'get',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: settings.url(options),
    method: 'head',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: settings.url(options),
    method: 'post',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: settings.url(options),
    method: 'put',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: settings.url(options),
    method: 'patch',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: settings.url(options),
    method: 'delete',
})
/**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
settings.options = (options?: RouteQueryOptions): RouteDefinition<'options'> => ({
    url: settings.url(options),
    method: 'options',
})

    /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
    const settingsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: settings.url(options),
        method: 'get',
    })

            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: settings.url(options),
            method: 'get',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: settings.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: settings.url(options),
            method: 'post',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: settings.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: settings.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: settings.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
            /**
* @see \Illuminate\Routing\RedirectController::__invoke
 * @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
 * @route '/settings'
 */
        settingsForm.options = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: settings.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'OPTIONS',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    settings.form = settingsForm