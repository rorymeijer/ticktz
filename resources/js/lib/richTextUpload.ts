import axios from 'axios';

/**
 * What the server hands back for an image somebody pasted.
 */
export type UploadedImage = { url: string; name: string };

/**
 * Why an upload did not work, in terms the editor can put on screen.
 *
 * Deliberately a small set rather than the server's message: the three things
 * that actually happen are "that is not an image we take", "that is too big"
 * and "slow down", and each of those has a sentence worth writing in the
 * reader's own language. Anything else is a fault, and a fault should not be
 * explained to somebody pasting a screenshot.
 */
export type UploadFailure = 'rejected' | 'too_many' | 'failed';

export class RichTextUploadError extends Error {
    constructor(public readonly kind: UploadFailure) {
        super(kind);
        this.name = 'RichTextUploadError';
    }
}

/**
 * Send one image to the server and get back the URL to put in the text.
 *
 * `internal` travels with it so an image pasted into an internal note is never
 * readable by the requester, not even in the window between the paste and the
 * save — the note has no id yet, so the flag is the only thing that knows.
 */
export async function uploadRichTextImage(file: File, internal = false): Promise<UploadedImage> {
    const body = new FormData();

    body.append('file', file);
    body.append('internal', internal ? '1' : '0');

    try {
        const { data } = await axios.post<UploadedImage>('/rich-text/images', body);

        return data;
    } catch (error) {
        const status = axios.isAxiosError(error) ? error.response?.status : undefined;

        throw new RichTextUploadError(
            status === 422 ? 'rejected' : status === 429 ? 'too_many' : 'failed',
        );
    }
}

/** The image files in a clipboard or a drop, in the order they arrived. */
export function imageFilesIn(list: FileList | DataTransferItemList | null | undefined): File[] {
    if (!list) {
        return [];
    }

    const files: File[] = [];

    for (let index = 0; index < list.length; index += 1) {
        const entry = list[index];
        const file = entry instanceof File ? entry : entry.kind === 'file' ? entry.getAsFile() : null;

        if (file && file.type.startsWith('image/')) {
            files.push(file);
        }
    }

    return files;
}
