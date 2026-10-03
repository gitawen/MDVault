import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
export const browse = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

browse.definition = {
    methods: ["post"],
    url: '/directories/browse',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
browse.url = (options?: RouteQueryOptions) => {
    return browse.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
browse.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: browse.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
    const browseForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: browse.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
        browseForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: browse.url(options),
            method: 'post',
        })
    
    browse.form = browseForm
const directories = {
    browse: Object.assign(browse, browse),
}

export default directories