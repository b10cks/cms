/**
 * Same-origin relative path for post-login redirects. Anything that is not a
 * single-slash path (protocol-relative `//host`, absolute URLs, empty) is `/`.
 */
export function safeReturnPath(returnPath: unknown): string {
  if (typeof returnPath !== 'string' || returnPath === '' || !returnPath.startsWith('/')) {
    return '/'
  }

  if (returnPath.startsWith('//')) {
    return '/'
  }

  return returnPath
}
