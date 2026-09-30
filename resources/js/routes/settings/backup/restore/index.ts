import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::browse
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:24
 * @route '/settings/backup/restore/browse'
 */
export const browse = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

browse.definition = {
    methods: ["post"],
    url: '/settings/backup/restore/browse',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::browse
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:24
 * @route '/settings/backup/restore/browse'
 */
browse.url = (options?: RouteQueryOptions) => {
    return browse.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::browse
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:24
 * @route '/settings/backup/restore/browse'
 */
browse.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::browse
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:24
 * @route '/settings/backup/restore/browse'
 */
    const browseForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: browse.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::browse
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:24
 * @route '/settings/backup/restore/browse'
 */
        browseForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: browse.url(options),
            method: 'post',
        })
    
    browse.form = browseForm
/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::inspect
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:44
 * @route '/settings/backup/restore/inspect'
 */
export const inspect = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: inspect.url(options),
    method: 'post',
})

inspect.definition = {
    methods: ["post"],
    url: '/settings/backup/restore/inspect',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::inspect
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:44
 * @route '/settings/backup/restore/inspect'
 */
inspect.url = (options?: RouteQueryOptions) => {
    return inspect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::inspect
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:44
 * @route '/settings/backup/restore/inspect'
 */
inspect.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: inspect.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::inspect
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:44
 * @route '/settings/backup/restore/inspect'
 */
    const inspectForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: inspect.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::inspect
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:44
 * @route '/settings/backup/restore/inspect'
 */
        inspectForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: inspect.url(options),
            method: 'post',
        })
    
    inspect.form = inspectForm
/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::store
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:53
 * @route '/settings/backup/restore'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/backup/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::store
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:53
 * @route '/settings/backup/restore'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BackupRestoreController::store
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:53
 * @route '/settings/backup/restore'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::store
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:53
 * @route '/settings/backup/restore'
 */
    const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\BackupRestoreController::store
 * @see app/Http/Controllers/Settings/BackupRestoreController.php:53
 * @route '/settings/backup/restore'
 */
        storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(options),
            method: 'post',
        })
    
    store.form = storeForm
const restore = {
    browse: Object.assign(browse, browse),
inspect: Object.assign(inspect, inspect),
store: Object.assign(store, store),
}

export default restore