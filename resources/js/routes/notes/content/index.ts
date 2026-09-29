import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\NoteContentController::__invoke
 * @see app/Http/Controllers/NoteContentController.php:16
 * @route '/notes/{note}/content'
 */
export const update = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/notes/{note}/content',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\NoteContentController::__invoke
 * @see app/Http/Controllers/NoteContentController.php:16
 * @route '/notes/{note}/content'
 */
update.url = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{note}', parsedArgs.note.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\NoteContentController::__invoke
 * @see app/Http/Controllers/NoteContentController.php:16
 * @route '/notes/{note}/content'
 */
update.put = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

    /**
* @see \App\Http\Controllers\NoteContentController::__invoke
 * @see app/Http/Controllers/NoteContentController.php:16
 * @route '/notes/{note}/content'
 */
    const updateForm = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: update.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PUT',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\NoteContentController::__invoke
 * @see app/Http/Controllers/NoteContentController.php:16
 * @route '/notes/{note}/content'
 */
        updateForm.put = (args: { note: string | { uuid: string } } | [note: string | { uuid: string } ] | string | { uuid: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: update.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PUT',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    update.form = updateForm
const content = {
    update: Object.assign(update, update),
}

export default content