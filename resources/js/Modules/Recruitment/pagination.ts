/**
 * The numbered page links of a paginator, whichever shape it arrives in.
 *
 * Laravel sends two. A plain `->paginate()` puts the page links at `links` — an array of
 * `{url, label, page, active}`. An API resource collection puts *navigation* there instead, as an
 * object of `{first, last, prev, next}`, and moves the page links to `meta.links`.
 *
 * Five recruitment screens read `links` and iterated it. Against the resource shape that iterates
 * an object: the values are URL strings, and `prev`/`next` are null whenever there is only one
 * page — so the template read `.label` off null and the whole screen went blank. It is the
 * single-page case that breaks, which is every list with nothing in it yet.
 */
export type PageLink = { url: string | null; label: string; page?: number | null; active: boolean }

export function pageLinks(paginator: any): PageLink[] {
    const links = paginator?.links

    if (Array.isArray(links)) {
        return links
    }

    // The resource shape: `links` held first/last/prev/next, and the pages are under meta.
    const fromMeta = paginator?.meta?.links

    return Array.isArray(fromMeta) ? fromMeta : []
}
