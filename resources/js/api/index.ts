import { Ai } from '~/api/resources/ai'
import { AuditLogs } from '~/api/resources/audit-logs'
import { Authorization } from '~/api/resources/authorization'
import { BlockFolders } from '~/api/resources/block-folders'
import { BlockTemplates } from '~/api/resources/block-templates'
import { BlockVersions } from '~/api/resources/block-versions'
import { Comments } from '~/api/resources/comments'
import { DataSources } from '~/api/resources/data-sources'
import { PersonalAccessTokens } from '~/api/resources/personal-access-tokens'
import { Plans } from '~/api/resources/plans'
import { Provider } from '~/api/resources/provider'
import { Redirects } from '~/api/resources/redirects'
import { Releases } from '~/api/resources/releases'
import { SpaceMembers } from '~/api/resources/space-members'
import { Subscriptions } from '~/api/resources/subscriptions'
import { Tokens } from '~/api/resources/tokens'
import { TwoFactorAuth } from '~/api/resources/two-factor'
import { Users } from '~/api/resources/users'

import { ApiClient } from './client'
import { AssetFolders } from './resources/asset-folders'
import { AssetTags } from './resources/asset-tags'
import { Assets } from './resources/assets'
import { Backups } from './resources/backups'
import { BlockTags } from './resources/block-tags'
import { Blocks } from './resources/blocks'
import { ContentMenu } from './resources/content-menu'
import { ContentModel } from './resources/content-model'
import { ContentVersions } from './resources/content-versions'
import { Contents } from './resources/contents'
import { Invites } from './resources/invites'
import { Migrations } from './resources/migrations'
import { SpaceBlueprints } from './resources/space-blueprints'
import { Spaces } from './resources/spaces'
import { Teams } from './resources/teams'

export class API {
  public client: ApiClient
  private readonly _ai: Ai
  private readonly _authorization: Authorization
  private readonly _plans: Plans
  private readonly _provider: Provider
  private readonly _spaces: Spaces
  private readonly _spaceBlueprints: SpaceBlueprints
  private readonly _teams: Teams
  private readonly _invites: Invites
  private readonly _users: Users
  private readonly _twoFactor: TwoFactorAuth
  private readonly _personalAccessTokens: PersonalAccessTokens

  constructor(
    options: {
      baseURL?: string
      authToken?: string
    } = {}
  ) {
    this.client = new ApiClient(options)
    this._ai = new Ai(this.client)
    this._authorization = new Authorization(this.client)
    this._plans = new Plans(this.client)
    this._provider = new Provider(this.client)
    this._spaces = new Spaces(this.client)
    this._spaceBlueprints = new SpaceBlueprints(this.client)
    this._teams = new Teams(this.client)
    this._invites = new Invites(this.client)
    this._users = new Users(this.client)
    this._twoFactor = new TwoFactorAuth(this.client)
    this._personalAccessTokens = new PersonalAccessTokens(this.client)
  }

  public setAuthToken(token?: string): void {
    this.client.setAuthToken(token)
  }

  public setAuthHandler(handler: any): void {
    this.client.setAuthHandler(handler)
  }

  public get ai(): Ai {
    return this._ai
  }

  public get authorization(): Authorization {
    return this._authorization
  }

  public get plans(): Plans {
    return this._plans
  }

  public get provider(): Provider {
    return this._provider
  }

  public get spaces(): Spaces {
    return this._spaces
  }

  public get spaceBlueprints(): SpaceBlueprints {
    return this._spaceBlueprints
  }

  public get teams(): Teams {
    return this._teams
  }

  public get invites(): Invites {
    return this._invites
  }

  public get users(): Users {
    return this._users
  }

  public get twoFactor(): TwoFactorAuth {
    return this._twoFactor
  }

  public get personalAccessTokens(): PersonalAccessTokens {
    return this._personalAccessTokens
  }

  public forSpace(spaceId: string) {
    return {
      ai: new Ai(this.client, spaceId),
      auditLogs: new AuditLogs(this.client, spaceId),
      assetFolders: new AssetFolders(this.client, spaceId),
      assetTags: new AssetTags(this.client, spaceId),
      assets: new Assets(this.client, spaceId),
      backups: new Backups(this.client, spaceId),
      migrations: new Migrations(this.client, spaceId),
      blocks: new Blocks(this.client, spaceId),
      blockTags: new BlockTags(this.client, spaceId),
      blockFolders: new BlockFolders(this.client, spaceId),
      blockTemplates: (blockId: string) => new BlockTemplates(this.client, spaceId, blockId),
      blockVersions: (blockId: string) => new BlockVersions(this.client, spaceId, blockId),
      contents: new Contents(this.client, spaceId),
      contentMenu: new ContentMenu(this.client, spaceId),
      dataSources: new DataSources(this.client, spaceId),
      tokens: new Tokens(this.client, spaceId),
      redirects: new Redirects(this.client, spaceId),
      releases: new Releases(this.client, spaceId),
      contentVersions: (contentId: string) => new ContentVersions(this.client, spaceId, contentId),
      comments: (contentId: string) => new Comments(this.client, spaceId, contentId),
      subscriptions: new Subscriptions(this.client, spaceId),
      members: new SpaceMembers(this.client, spaceId),
    }
  }
}

export const api = new API()

export { ContentModel }
