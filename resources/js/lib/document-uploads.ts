/**
 * What the server hands back for a file that was just put in a document.
 *
 * `id` is the half that lasts: the editor writes it into the body and
 * ReconcileDocumentFiles reads it back to decide which files are still in use.
 * `url` is for right now, and is rebuilt from the id on every read — see
 * DocumentFile::url().
 */
export interface UploadedDocumentFile {
    id: number;
    url: string;
    name: string;
    mimeType: string;
    size: number;
    width: number | null;
    height: number | null;
}

/**
 * Put one file inside a document.
 *
 * fetch() rather than Inertia's router, which everything else in the document
 * view goes through. Two reasons, and the second is the load-bearing one: this
 * needs the id and the address back in order to build the block, and an Inertia
 * visit answers with a page rather than with a value. It would also rebuild the
 * page around somebody who is mid-sentence, which is the thing the whole save
 * path is arranged to avoid.
 *
 * No Content-Type header on purpose. The body is a FormData, and the browser
 * sets multipart/form-data with the boundary it generated; naming the type here
 * would produce a boundary-less header and a request PHP cannot parse.
 */
export async function uploadDocumentFile(
    endpoint: string,
    file: File,
): Promise<UploadedDocumentFile> {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            ...csrfHeader(),
        },
        // The session cookie decides who is uploading; without this the request
        // arrives unauthenticated and the policy refuses it.
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(await failureMessage(response));
    }

    return (await response.json()) as UploadedDocumentFile;
}

/**
 * The one header Laravel wants back.
 *
 * Kept apart from lib/csrf.ts because that one also sets a JSON content type,
 * which is exactly what an upload must not send.
 */
function csrfHeader(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie
        ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.split('=')[1]) }
        : {};
}

/**
 * What went wrong, in the words the server used where it gave any.
 *
 * A rejected upload is nearly always a rule the workspace set — too large, or a
 * type it does not accept — and those messages are already written and already
 * translated. Falling back to the status only when there is nothing better.
 */
async function failureMessage(response: Response): Promise<string> {
    try {
        const body = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        return (
            body.errors?.file?.[0] ?? body.message ?? String(response.status)
        );
    } catch {
        return String(response.status);
    }
}
