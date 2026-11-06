/** Everything the meta previews render, resolved with the fallbacks each platform applies. */
export interface MetaPreviewData {
  url: URL | null
  siteName: string
  siteIcon: string | null
  /** Search title: the meta title, else the entry name. */
  title: string
  description: string
  /** Share title: the Open Graph title, else the search title. */
  shareTitle: string
  shareDescription: string
  /** Where the share title comes from, so a card can say it borrowed the search title. */
  shareTitleSource: 'og' | 'search'
  image: string | null
}

export const PLATFORMS = [
  { id: 'google', label: 'Google', icon: 'brand:google' },
  { id: 'facebook', label: 'Facebook', icon: 'brand:facebook' },
  { id: 'linkedin', label: 'LinkedIn', icon: 'brand:linkedin' },
  { id: 'x', label: 'X', icon: 'brand:x' },
  { id: 'slack', label: 'Slack', icon: 'brand:slack' },
  { id: 'whatsapp', label: 'WhatsApp', icon: 'brand:whatsapp' },
] as const

export type Platform = (typeof PLATFORMS)[number]['id']
export type SocialPlatform = Exclude<Platform, 'google'>

/** The bare host a card prints: "schlafteq.de", never "https://www.schlafteq.de". */
export const displayHost = (url: URL | null): string => url?.host.replace(/^www\./, '') ?? ''
