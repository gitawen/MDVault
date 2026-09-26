import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/storage',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
    const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
 */
        editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Settings\StorageController::edit
 * @see app/Http/Controllers/Settings/StorageController.php:17
 * @route '/settings/storage'
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
* @see \App\Http\Controllers\Settings\StorageController::update
 * @see app/Http/Controllers/Settings/StorageController.php:25
 * @route '/settings/storage'
 */
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/storage',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\StorageController::update
 * @see app/Http/Controllers/Settings/StorageController.php:25
 * @route '/settings/storage'
 */
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\StorageController::update
 * @see app/Http/Controllers/Settings/StorageController.php:25
 * @route '/settings/storage'
 */
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Settings\StorageController::update
 * @see app/Http/Controllers/Settings/StorageController.php:25
 * @route '/settings/storage'
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
* @see \App\Http\Controllers\Settings\StorageController::update
 * @see app/Http/Controllers/Settings/StorageController.php:25
 * @route '/settings/storage'
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
/**
* @see \App\Http\Controllers\Settings\StorageController::browse
 * @see app/Http/Controllers/Settings/StorageController.php:34
 * @route '/settings/storage/browse'
 */
export const browse = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

browse.definition = {
    methods: ["post"],
    url: '/settings/storage/browse',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\StorageController::browse
 * @see app/Http/Controllers/Settings/StorageController.php:34
 * @route '/settings/storage/browse'
 */
browse.url = (options?: RouteQueryOptions) => {
    return browse.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\StorageController::browse
 * @see app/Http/Controllers/Settings/StorageController.php:34
 * @route '/settings/storage/browse'
 */
browse.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\StorageController::browse
 * @see app/Http/Controllers/Settings/StorageController.php:34
 * @route '/settings/storage/browse'
 */
    const browseForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: browse.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\StorageController::browse
 * @see app/Http/Controllers/Settings/StorageController.php:34
 * @route '/settings/storage/browse'
 */
        browseForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: browse.url(options),
            method: 'post',
        })
    
    browse.form = browseForm
/**
* @see \App\Http\Controllers\Settings\StorageController::destroy
 * @see app/Http/Controllers/Settings/StorageController.php:51
 * @route '/settings/storage'
 */
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/storage',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\StorageController::destroy
 * @see app/Http/Controllers/Settings/StorageController.php:51
 * @route '/settings/storage'
 */
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\StorageController::destroy
 * @see app/Http/Controllers/Settings/StorageController.php:51
 * @route '/settings/storage'
 */
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\Settings\StorageController::destroy
 * @see app/Http/Controllers/Settings/StorageController.php:51
 * @route '/settings/storage'
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
* @see \App\Http\Controllers\Settings\StorageController::destroy
 * @see app/Http/Controllers/Settings/StorageController.php:51
 * @route '/settings/storage'
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
const storage = {
    edit: Object.assign(edit, edit),
update: Object.assign(update, update),
browse: Object.assign(browse, browse),
destroy: Object.assign(destroy, destroy),
}

export default storage