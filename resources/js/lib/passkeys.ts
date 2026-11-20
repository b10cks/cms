/**
 * Browser side of the WebAuthn ceremonies. The server sends options as JSON
 * and receives `PublicKeyCredential.toJSON()` back, so the native JSON helpers
 * do all the encoding. Browsers without them get no passkey UI at all.
 */

export type PasskeyCredentialJSON = Record<string, unknown>

export const passkeysSupported = (): boolean =>
  typeof window !== 'undefined' &&
  typeof window.PublicKeyCredential?.parseRequestOptionsFromJSON === 'function' &&
  typeof window.PublicKeyCredential?.parseCreationOptionsFromJSON === 'function'

/** True when the browser can offer passkeys in the autofill of a `webauthn` input. */
export const conditionalMediationAvailable = async (): Promise<boolean> =>
  passkeysSupported() &&
  (await window.PublicKeyCredential.isConditionalMediationAvailable?.()) === true

/** The user closed the browser prompt, or the request was aborted. Not worth an error message. */
export const isPasskeyCancellation = (error: unknown): boolean =>
  error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'AbortError')

export const createPasskey = async (
  options: PublicKeyCredentialCreationOptionsJSON
): Promise<PasskeyCredentialJSON> => {
  const credential = await navigator.credentials.create({
    publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options),
  })

  return toJSON(credential)
}

export const getPasskey = async (
  options: PublicKeyCredentialRequestOptionsJSON,
  { conditional = false, signal }: { conditional?: boolean; signal?: AbortSignal } = {}
): Promise<PasskeyCredentialJSON> => {
  const credential = await navigator.credentials.get({
    publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options),
    mediation: conditional ? 'conditional' : 'optional',
    signal,
  })

  return toJSON(credential)
}

const toJSON = (credential: Credential | null): PasskeyCredentialJSON => {
  if (!(credential instanceof PublicKeyCredential)) {
    throw new DOMException('No passkey was returned', 'NotAllowedError')
  }

  return credential.toJSON() as PasskeyCredentialJSON
}
