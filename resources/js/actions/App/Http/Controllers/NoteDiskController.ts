import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
const NoteDiskController = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NoteDiskController.url(args, options),
    method: 'get',
})

NoteDiskController.definition = {
    methods: ["get","head"],
    url: '/notes/{note}/disk',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
NoteDiskController.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return NoteDiskController.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
NoteDiskController.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NoteDiskController.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
NoteDiskController.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: NoteDiskController.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
    const NoteDiskControllerForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: NoteDiskController.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
        NoteDiskControllerForm.get = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: NoteDiskController.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\NoteDiskController::__invoke
 * @see app/Http/Controllers/NoteDiskController.php:14
 * @route '/notes/{note}/disk'
 */
        NoteDiskControllerForm.head = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: NoteDiskController.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    NoteDiskController.form = NoteDiskControllerForm
export default NoteDiskController