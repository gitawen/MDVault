import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
const WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})

WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url = (options?: RouteQueryOptions) => {
    return WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
    const WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
        WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/'
 */
        WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9.form = WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9Form
    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
const WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, options),
    method: 'get',
})

WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.definition = {
    methods: ["get","head"],
    url: '/notes/{note}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { note: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'uuid' in args) {
            args = { note: args.uuid }
        }
    
    if (Array.isArray(args)) {
        args = {
                    note: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        note: typeof args.note === 'object'
                ? args.note.uuid
                : args.note,
                }

    return WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
    const WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59fForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
        WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59fForm.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\WorkspaceController::__invoke
 * @see app/Http/Controllers/WorkspaceController.php:23
 * @route '/notes/{note}'
 */
        WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59fForm.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f.form = WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59fForm

/**
* Multiple routes resolve to \App\Http\Controllers\WorkspaceController::WorkspaceController, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `WorkspaceController['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
const WorkspaceController = {
    '/': WorkspaceController980bb49ee7ae63891f1d891d2fbcf1c9,
    '/notes/{note}': WorkspaceController90bb6cf0f6f6941cbce7208f85d6a59f,
}

export default WorkspaceController