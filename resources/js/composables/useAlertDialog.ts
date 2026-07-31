import { createSharedComposable } from '@vueuse/core'
import type { Component } from 'vue'

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'
type ActionType = 'primary' | 'destructive' | 'cancel' | 'ghost' | 'link'
export interface DialogAction {
  type: ActionType
  label: string
  click?: () => void
  autoClose?: boolean
}
export interface DialogOptions {
  title?: string
  message: string
  actions: DialogAction[]
}
export interface MessageOptions {
  title?: string
  onClose?: () => void
  cancelButton?: boolean
  cancelLabel?: string
  okLabel?: string
}
export interface ConfirmOptions extends MessageOptions {
  onConfirm?: () => void
  onCancel?: () => void
  confirmLabel?: string
  variant?: ActionType
}
interface DialogState {
  isOpen: boolean
  component: Component | null
  resolve: ((value: never) => void) | null
  reject: ((reason?: never) => void) | null
}
interface DefaultLabels {
  ok: string
  cancel: string
  confirm: string
}

const defaultLabels = reactive<DefaultLabels>({
  ok: 'OK',
  cancel: 'Cancel',
  confirm: 'Confirm',
})

export function setAlertDialogDefaultLabels(labels: Partial<DefaultLabels>): void {
  Object.assign(defaultLabels, labels)
}
const useAlertDialogBase = () => {
  let i18n: ReturnType<typeof useI18n> | null = null
  try {
    i18n = useI18n()
  } catch {
    /* empty */
  }

  const getLabel = (key: keyof DefaultLabels, fallback?: string): string => {
    if (fallback) return fallback

    if (i18n) {
      const i18nKey = `alertDialog.${key}`
      const translated = i18n.$t(i18nKey)

      if (translated && translated !== i18nKey) {
        return translated as string
      }
    }

    return defaultLabels[key]
  }
  const state = ref<DialogState>({
    isOpen: false,
    component: null,
    resolve: null,
    reject: null,
  })
  let closeTimeout: ReturnType<typeof setTimeout> | null = null
  // Overwriting a live dialog tore down its markup while its resolvers stayed
  // captured in an unreachable closure, so the first promise never settled.
  const pendingDialogs: Component[] = []

  const showDialog = (component: Component) => {
    if (closeTimeout) {
      clearTimeout(closeTimeout)
      closeTimeout = null
    }

    state.value.component = markRaw(component)
    state.value.isOpen = true
  }

  const openDialog = (component: Component) => {
    if (state.value.isOpen) {
      pendingDialogs.push(markRaw(component))
      return
    }

    showDialog(component)
  }

  const closeDialog = () => {
    if (closeTimeout) {
      clearTimeout(closeTimeout)
    }

    state.value.isOpen = false
    closeTimeout = setTimeout(() => {
      state.value.component = null
      closeTimeout = null

      const next = pendingDialogs.shift()
      if (next) {
        showDialog(next)
      }
    }, 300)
  }
  const dialog = (options: DialogOptions) => {
    return new Promise((resolve) => {
      const handleAction = (action: DialogAction) => {
        if (action.click) {
          action.click()
        }
        resolve(action.type)
        if (action.autoClose !== false) {
          closeDialog()
        }
      }
      const component = defineComponent({
        setup() {
          return () =>
            h(
              AlertDialog,
              {
                open: state.value.isOpen,
                'onUpdate:open': (val: boolean) => {
                  if (!val) {
                    closeDialog()
                    resolve('closed')
                  }
                },
              },
              {
                default: () =>
                  h(
                    AlertDialogContent,
                    {},
                    {
                      default: () => [
                        h(
                          AlertDialogHeader,
                          {},
                          {
                            default: () => [
                              options.title ? h(AlertDialogTitle, {}, () => options.title) : null,
                              h(AlertDialogDescription, {}, () => options.message),
                            ],
                          }
                        ),
                        h(
                          AlertDialogFooter,
                          {},
                          {
                            default: () =>
                              options.actions.map((action) => {
                                if (action.type === 'cancel') {
                                  return h(
                                    AlertDialogCancel,
                                    { onClick: () => handleAction(action) },
                                    () => action.label
                                  )
                                } else {
                                  return h(
                                    AlertDialogAction,
                                    {
                                      onClick: () => handleAction(action),
                                      variant: action.type,
                                    },
                                    () => action.label
                                  )
                                }
                              }),
                          }
                        ),
                      ],
                    }
                  ),
              }
            )
        },
      })
      openDialog(component)
    })
  }
  const message = (message: string, options: MessageOptions = {}) => {
    return dialog({
      title: options.title,
      message,
      actions: [
        ...(options.cancelButton
          ? [
              {
                type: 'cancel' as ActionType,
                label: options.cancelLabel || getLabel('cancel'),
                click: options.onClose,
                autoClose: true,
              },
            ]
          : []),
        {
          type: 'primary' as ActionType,
          label: options.okLabel || getLabel('ok'),
          click: options.onClose,
          autoClose: true,
        },
      ],
    })
  }
  const confirm = (message: string, options: ConfirmOptions = {}) => {
    return new Promise<boolean>((resolve) => {
      let isSettled = false
      const settle = (value: boolean) => {
        if (isSettled) {
          return
        }

        isSettled = true
        resolve(value)
      }

      dialog({
        title: options.title,
        message,
        actions: [
          {
            type: 'cancel' as ActionType,
            label: options.cancelLabel || getLabel('cancel'),
            click: () => {
              if (options.onCancel) options.onCancel()
              settle(false)
            },
            autoClose: true,
          },
          {
            type: options.variant || ('primary' as ActionType),
            label: options.confirmLabel || getLabel('confirm'),
            click: () => {
              if (options.onConfirm) options.onConfirm()
              settle(true)
            },
            autoClose: true,
          },
        ],
        // A dismissal — Escape, an overlay click — only settles the inner dialog
        // promise, which used to be discarded here: every `await confirm(...)`
        // the user escaped out of hung forever. The click handlers run
        // synchronously, before this microtask, so a real answer still wins.
      }).then(() => settle(false))
    })
  }
  return {
    state,
    alert: {
      dialog,
      message,
      confirm,
    },

    setLabels: (labels: Partial<DefaultLabels>) => {
      Object.assign(defaultLabels, labels)
    },
  }
}

export const useAlertDialog = createSharedComposable(useAlertDialogBase)

export const AlertDialogProvider = defineComponent({
  setup(_, { slots }) {
    const { state } = useAlertDialog()

    return () =>
      h('div', { class: 'alert-dialog-provider' }, [
        slots.default?.(),

        h('div', { style: { display: 'none' } }, [
          state.value.component ? h(state.value.component) : null,
        ]),
      ])
  },
})
