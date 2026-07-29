import { BaseResource } from './base-resource'

export class Notifications extends BaseResource<
  NotificationResource,
  never,
  never,
  NotificationQueryParams
> {
  protected basePath: string = '/mgmt/v1/users/me/notifications'

  public async list(
    params: NotificationQueryParams = {}
  ): Promise<ApiCollectionResponse<NotificationResource>> {
    return this.client.get<ApiCollectionResponse<NotificationResource>>(
      this.basePath,
      params as Record<string, unknown>
    )
  }

  public async unreadCount(): Promise<UnreadCountResponse> {
    return this.client.get<UnreadCountResponse>(`${this.basePath}/unread-count`)
  }

  public async markAsRead(id: string): Promise<void> {
    await this.client.patch(`${this.basePath}/${id}/read`, {})
  }

  public async markAsUnread(id: string): Promise<void> {
    await this.client.patch(`${this.basePath}/${id}/unread`, {})
  }

  public async markAllAsRead(): Promise<void> {
    await this.client.post(`${this.basePath}/read`, {})
  }

  public async remove(id: string): Promise<void> {
    await this.client.delete(`${this.basePath}/${id}`)
  }

  public async removeAll(): Promise<void> {
    await this.client.delete(this.basePath)
  }

  public async removeAllRead(): Promise<void> {
    await this.client.delete(`${this.basePath}/read`)
  }
}
