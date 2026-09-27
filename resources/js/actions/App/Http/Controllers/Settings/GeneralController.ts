import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/general',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
    const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
 */
        editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Settings\GeneralController::edit
 * @see app/Http/Controllers/Settings/GeneralController.php:16
 * @route '/settings/general'
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
* @see \App\Http\Controllers\Settings\GeneralController::update
 * @see app/Http/Controllers/Settings/GeneralController.php:24
 * @route '/settings/general'
 */
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/general',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\GeneralController::update
 * @see app/Http/Controllers/Settings/GeneralController.php:24
 * @route '/settings/general'
 */
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\GeneralController::update
 * @see app/Http/Controllers/Settings/GeneralController.php:24
 * @route '/settings/general'
 */
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Settings\GeneralController::update
 * @see app/Http/Controllers/Settings/GeneralController.php:24
 * @route '/settings/general'
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
* @see \App\Http\Controllers\Settings\GeneralController::update
 * @see app/Http/Controllers/Settings/GeneralController.php:24
 * @route '/settings/general'
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
const GeneralController = { edit, update }

export default GeneralController