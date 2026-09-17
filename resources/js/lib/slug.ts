/**
 * Browser-side slug helper used while typing a name, mirroring the server's
 * `regex:/^[a-z0-9-]+$/` validation. The server still validates — this only
 * keeps the field from ever showing an invalid value.
 */
export function slugify(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 255);
}
