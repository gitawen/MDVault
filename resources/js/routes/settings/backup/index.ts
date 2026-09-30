import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
import restore from './restore'
import database from './database'
/**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/backup',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
    const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: edit.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
 */
        editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: edit.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Settings\BackupController::edit
 * @see app/Http/Controllers/Settings/BackupController.php:18
 * @route '/settings/backup'
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
* @see \App\Http\Controllers\Settings\BackupController::store
 * @see app/Http/Controllers/Settings/BackupController.php:27
 * @route '/settings/backup'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/backup',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\BackupController::store
 * @see app/Http/Controllers/Settings/BackupController.php:27
 * @route '/settings/backup'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BackupController::store
 * @see app/Http/Controllers/Settings/BackupController.php:27
 * @route '/settings/backup'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\BackupController::store
 * @see app/Http/Controllers/Settings/BackupController.php:27
 * @route '/settings/backup'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\BackupController::store
 * @see app/Http/Controllers/Settings/BackupController.php:27
 * @route '/settings/backup'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
const backup = {
    edit: Object.assign(edit, edit),
store: Object.assign(store, store),
restore: Object.assign(restore, restore),
database: Object.assign(database, database),
}

export default backup