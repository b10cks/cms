import type { ContentResource, ContentVersionListResource } from '~/types/contents'

export class ContentModel {
  constructor(private resource: ContentResource) {}

  public get data(): ContentResource {
    return this.resource
  }

  public get isPublished(): boolean {
    return this.resource.published_at !== null
  }

  public get isCurrentVersionPublished(): boolean {
    return this.resource.current_version?.published_at !== null
  }

  public get hasPublishedVersion(): boolean {
    return this.resource.published_version_id !== null
  }

  public get hasCurrentVersion(): boolean {
    return this.resource.current_version_id !== null
  }

  public get isInRelease(): boolean {
    return !!this.resource.current_version?.release_id
  }

  public get releaseId(): string | null | undefined {
    return this.resource.current_version?.release_id
  }

  public get isScheduled(): boolean {
    return this.resource.current_version?.scheduled_at !== null
  }

  public get scheduledAt(): string | null | undefined {
    return this.resource.current_version?.scheduled_at
  }

  public get hasBeenPublished(): boolean {
    return this.resource.first_published_at !== null
  }

  public get canPublish(): boolean {
    return !this.isCurrentVersionPublished && !this.isInRelease
  }

  public get canUnpublish(): boolean {
    return this.isPublished
  }

  public get hasUnpublishedChanges(): boolean {
    return this.hasCurrentVersion && !this.isCurrentVersionPublished
  }

  public get currentVersion(): ContentVersionListResource | null | undefined {
    return this.resource.current_version
  }

  public get publishedVersion(): ContentVersionListResource | null | undefined {
    return this.resource.published_version
  }

  public get name(): string {
    return this.resource.name
  }

  public get slug(): string {
    return this.resource.slug
  }

  public get fullSlug(): string {
    return this.resource.full_slug
  }

  public get id(): string {
    return this.resource.id
  }

  public get parentId(): string | null {
    return this.resource.parent_id
  }

  public get blockId(): string {
    return this.resource.block_id
  }

  public get block(): any {
    return this.resource.block
  }

  public get isLocalization(): boolean {
    return this.resource.i18n_parent_id !== null
  }

  public get i18nParentId(): string | null {
    return this.resource.i18n_parent_id
  }

  public get i18nTranslations(): any[] | undefined {
    return this.resource.i18n_translations
  }

  public get i18nSiblings(): any[] | undefined {
    return this.resource.i18n_siblings
  }

  public get hasChildren(): boolean {
    return (this.resource.children_count ?? 0) > 0
  }

  public get childrenCount(): number {
    return this.resource.children_count ?? 0
  }

  public get createdAt(): string {
    return this.resource.created_at
  }

  public get updatedAt(): string {
    return this.resource.updated_at
  }

  public get firstPublishedAt(): string | null {
    return this.resource.first_published_at
  }

  public get publishedAt(): string | null {
    return this.resource.published_at
  }

  public get description(): string {
    return this.resource.description
  }

  public get settings(): any {
    return this.resource.settings
  }

  public get content(): object {
    return this.resource.content
  }
}
