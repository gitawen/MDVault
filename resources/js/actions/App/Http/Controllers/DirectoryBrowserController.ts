import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
const DirectoryBrowserController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DirectoryBrowserController.url(options),
    method: 'post',
})

DirectoryBrowserController.definition = {
    methods: ["post"],
    url: '/directories/browse',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
DirectoryBrowserController.url = (options?: RouteQueryOptions) => {
    return DirectoryBrowserController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
DirectoryBrowserController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DirectoryBrowserController.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
    const DirectoryBrowserControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: DirectoryBrowserController.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\DirectoryBrowserController::__invoke
 * @see app/Http/Controllers/DirectoryBrowserController.php:11
 * @route '/directories/browse'
 */
        DirectoryBrowserControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: DirectoryBrowserController.url(options),
            method: 'post',
        })
    
    DirectoryBrowserController.form = DirectoryBrowserControllerForm
export default DirectoryBrowserController