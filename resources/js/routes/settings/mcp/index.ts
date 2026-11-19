import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/mcp',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
    const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
        editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Settings\McpController::edit
 * @see app/Http/Controllers/Settings/McpController.php:15
 * @route '/settings/mcp'
 */
        editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    edit.form = editForm
/**
* @see \App\Http\Controllers\Settings\McpController::provision
 * @see app/Http/Controllers/Settings/McpController.php:78
 * @route '/settings/mcp/provision'
 */
export const provision = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: provision.url(options),
    method: 'post',
})

provision.definition = {
    methods: ["post"],
    url: '/settings/mcp/provision',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\McpController::provision
 * @see app/Http/Controllers/Settings/McpController.php:78
 * @route '/settings/mcp/provision'
 */
provision.url = (options?: RouteQueryOptions) => {
    return provision.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpController::provision
 * @see app/Http/Controllers/Settings/McpController.php:78
 * @route '/settings/mcp/provision'
 */
provision.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: provision.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\McpController::provision
 * @see app/Http/Controllers/Settings/McpController.php:78
 * @route '/settings/mcp/provision'
 */
    const provisionForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: provision.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\McpController::provision
 * @see app/Http/Controllers/Settings/McpController.php:78
 * @route '/settings/mcp/provision'
 */
        provisionForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: provision.url(options),
            method: 'post',
        })
    
    provision.form = provisionForm
const mcp = {
    edit: Object.assign(edit, edit),
provision: Object.assign(provision, provision),
}

export default mcp