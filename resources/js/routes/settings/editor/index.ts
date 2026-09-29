import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/editor',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
    const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
 */
        editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Settings\EditorController::edit
 * @see app/Http/Controllers/Settings/EditorController.php:17
 * @route '/settings/editor'
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
* @see \App\Http\Controllers\Settings\EditorController::update
 * @see app/Http/Controllers/Settings/EditorController.php:25
 * @route '/settings/editor'
 */
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/editor',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\EditorController::update
 * @see app/Http/Controllers/Settings/EditorController.php:25
 * @route '/settings/editor'
 */
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EditorController::update
 * @see app/Http/Controllers/Settings/EditorController.php:25
 * @route '/settings/editor'
 */
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Settings\EditorController::update
 * @see app/Http/Controllers/Settings/EditorController.php:25
 * @route '/settings/editor'
 */
    const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\EditorController::update
 * @see app/Http/Controllers/Settings/EditorController.php:25
 * @route '/settings/editor'
 */
        updateForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    update.form = updateForm
const editor = {
    edit: Object.assign(edit, edit),
update: Object.assign(update, update),
}

export default editor