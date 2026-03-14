import { computed, onMounted, watch } from 'vue'
import { toast } from 'vue-sonner'

type ToastLevel = 'success' | 'error' | 'info' | 'warning' | 'message'

type UrlNotificationConfig = {
  level: ToastLevel
  translationKey: string
}

type UrlNotificationMap = Record<string, Record<string, UrlNotificationConfig>>

type ResolvedUrlNotification = UrlNotificationConfig & {
  param: string
  value: string
}

const URL_NOTIFICATION_MAP: UrlNotificationMap = {
  notification: {
    email_verified: {
      level: 'success',
      translationKey: 'labels.notifications.url.email_verified',
    },
    payment_success: {
      level: 'success',
      translationKey: 'labels.notifications.url.payment_success',
    },
    payment_pending: {
      level: 'info',
      translationKey: 'labels.notifications.url.payment_pending',
    },
    payment_failed: {
      level: 'error',
      translationKey: 'labels.notifications.url.payment_failed',
    },
    payment_cancelled: {
      level: 'warning',
      translationKey: 'labels.notifications.url.payment_cancelled',
    },
  },
}

const consumedNotifications = new Set<string>()

function getNotificationKey(notification: ResolvedUrlNotification): string {
  return `${notification.param}:${notification.value}`
}

export function useUrlNotifications() {
  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()

  const resolvedNotifications = computed<ResolvedUrlNotification[]>(() => {
    const notifications: ResolvedUrlNotification[] = []

    for (const [param, allowedValues] of Object.entries(URL_NOTIFICATION_MAP)) {
      const rawValue = route.query[param]

      if (typeof rawValue !== 'string' || rawValue === '') {
        continue
      }

      const config = allowedValues[rawValue]

      if (!config) {
        continue
      }

      notifications.push({
        param,
        value: rawValue,
        ...config,
      })
    }

    return notifications
  })

  const pendingNotifications = computed<ResolvedUrlNotification[]>(() => {
    return resolvedNotifications.value.filter((notification) => {
      return !consumedNotifications.has(getNotificationKey(notification))
    })
  })

  const showToast = (notification: ResolvedUrlNotification) => {
    const message = t(notification.translationKey)

    switch (notification.level) {
      case 'success':
        toast.success(message)
        return
      case 'error':
        toast.error(message)
        return
      case 'info':
        toast.info(message)
        return
      case 'warning':
        toast.warning(message)
        return
      default:
        toast(message)
    }
  }

  const stripConsumedParams = async (notifications: ResolvedUrlNotification[]) => {
    if (notifications.length === 0) {
      return
    }

    const nextQuery = { ...route.query }

    for (const notification of notifications) {
      delete nextQuery[notification.param]
    }

    await router.replace({
      name: route.name ?? undefined,
      params: route.params,
      query: nextQuery,
      hash: route.hash,
    })
  }

  const consume = async () => {
    const notifications = pendingNotifications.value

    if (notifications.length === 0) {
      return
    }

    for (const notification of notifications) {
      consumedNotifications.add(getNotificationKey(notification))
      showToast(notification)
    }

    await stripConsumedParams(notifications)
  }

  onMounted(() => {
    void consume()
  })

  watch(
    () => route.query,
    () => {
      void consume()
    }
  )

  return {
    notifications: resolvedNotifications,
    pendingNotifications,
    consume,
  }
}
