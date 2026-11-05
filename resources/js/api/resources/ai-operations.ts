import type {
  AiOperation,
  AiOperationAnsweredPage,
  AiOperationAnswer,
  AiOperationAnswerResult,
  AiOperationApproval,
  AiOperationArrivals,
  AiOperationItemPage,
  AiOperationItemStatus,
  AiOperationPlan,
  AiOperationQuestionMemberPage,
  AiOperationQuestionPage,
  StartAiOperationRequest,
} from '~/lib/aiOperations'
import type { AiRunRecord } from '~/lib/aiRun'

import type { ApiClient } from '../client'

export interface AiOperationItemParams {
  status?: AiOperationItemStatus
  reason?: string
  page?: number
  per_page?: number
}

/** AI content operations of a space: freeze, plan, approve, steer and review. */
export class AiOperations {
  protected client: ApiClient
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.basePath = `/mgmt/v1/spaces/${spaceId}/ai/operations`
  }

  /** The actor's latest 50 operations, optionally of one thread. */
  public async list(conversationId?: string | null): Promise<{ data: AiOperation[] }> {
    return this.client.get<{ data: AiOperation[] }>(this.basePath, { conversation_id: conversationId ?? undefined })
  }

  public async get(operationId: string): Promise<{ data: AiOperation }> {
    return this.client.get<{ data: AiOperation }>(`${this.basePath}/${operationId}`)
  }

  /** Freeze the scope into a draft; 422 `scope_too_large` freezes nothing. */
  public async create(payload: StartAiOperationRequest): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(this.basePath, payload)
  }

  public async savePlan(operationId: string, plan: AiOperationPlan): Promise<{ data: AiOperation }> {
    return this.client.patch<{ data: AiOperation }>(`${this.basePath}/${operationId}`, { plan })
  }

  /** Start the planning run in the operation's thread. */
  public async plan(operationId: string): Promise<{ data: AiRunRecord }> {
    return this.client.post<{ data: AiRunRecord }>(`${this.basePath}/${operationId}/plan`)
  }

  /**
   * Approve the plan and scope preview shown, which freezes the scope. 409
   * `plan_changed` when the plan moved on, 409 `scope_changed` with `added`
   * and `removed` when entries joined or left the scope since the preview.
   */
  public async approve(operationId: string, approval: AiOperationApproval): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/approve`, approval)
  }

  /** Count a draft's scope again after `scope_changed`; 422 when it grew too large or is empty now. */
  public async refreshScope(operationId: string): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/scope`)
  }

  public async pause(operationId: string): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/pause`)
  }

  public async resume(operationId: string): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/resume`)
  }

  public async cancel(operationId: string): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/cancel`)
  }

  /** Set a new total budget above what was spent; resumes a budget pause. */
  public async extendBudget(operationId: string, maxUsd: number): Promise<{ data: AiOperation }> {
    return this.client.post<{ data: AiOperation }>(`${this.basePath}/${operationId}/budget`, { max_usd: maxUsd })
  }

  public async items(operationId: string, params: AiOperationItemParams): Promise<AiOperationItemPage> {
    return this.client.get<AiOperationItemPage>(`${this.basePath}/${operationId}/items`, { ...params })
  }

  /** Open questions grouped by what they ask, with figures over the whole operation under `summary`. */
  public async questions(operationId: string, params: { page?: number; per_page?: number } = {}): Promise<AiOperationQuestionPage> {
    return this.client.get<AiOperationQuestionPage>(`${this.basePath}/${operationId}/questions`, { ...params })
  }

  /** The exact outputs one question asks about. Empty once it has been answered. */
  public async questionMembers(operationId: string, questionId: string, params: { page?: number; per_page?: number } = {}): Promise<AiOperationQuestionMemberPage> {
    return this.client.get<AiOperationQuestionMemberPage>(`${this.basePath}/${operationId}/questions/${questionId}/members`, { ...params })
  }

  /** Answers given so far, newest first. */
  public async answeredQuestions(operationId: string, params: { page?: number; per_page?: number } = {}): Promise<AiOperationAnsweredPage> {
    return this.client.get<AiOperationAnsweredPage>(`${this.basePath}/${operationId}/questions/answered`, { ...params })
  }

  /**
   * Answer and resume only the outputs named. `results` holds one entry per
   * answer: `recorded`, `duplicate` (already answered, nothing happened) or
   * `stale` (the group changed, nothing happened). 409 `questions_closed`
   * while work runs or after a cancellation.
   */
  public async answerQuestions(operationId: string, answers: AiOperationAnswer[]): Promise<{ data: { results: AiOperationAnswerResult[]; operation: AiOperation } }> {
    return this.client.post<{ data: { results: AiOperationAnswerResult[]; operation: AiOperation } }>(`${this.basePath}/${operationId}/questions/answers`, { answers })
  }

  public async arrivals(operationId: string): Promise<{ data: AiOperationArrivals }> {
    return this.client.get<{ data: AiOperationArrivals }>(`${this.basePath}/${operationId}/arrivals`)
  }
}
