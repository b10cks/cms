import type { PluginsInjections } from 'nuxt-i18n-micro'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import type { Component } from 'vue'

import { createSharedComposable } from '@vueuse/core'

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
  label: string | CleanTranslation
  click?: () => void
  autoClose?: boolean
}
export interface DialogOptions {
  title?: string | CleanTranslation
  message: string | CleanTranslation
  actions: DialogAction[]
}
export interface MessageOptions {
  title?: string | CleanTranslation
  onClose?: () => void
  cancelButton?: boolean
  cancelLabel?: string | CleanTranslation
  okLabel?: string | CleanTranslation
}
export interface ConfirmOptions extends MessageOptions {
  onConfirm?: () => void
  onCancel?: () => void
  confirmLabel?: string | CleanTranslation
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
  let i18n: PluginsInjections = null
  try {
    i18n = useI18n()
  } catch (_) {
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
  const openDialog = (component: Component) => {
    state.value.component = markRaw(component)
    state.value.isOpen = true
  }
  const closeDialog = () => {
    state.value.isOpen = false
    setTimeout(() => {
      state.value.component = null
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
  const confirm = (message: string | CleanTranslation, options: ConfirmOptions = {}) => {
    return new Promise<boolean>((resolve) => {
      dialog({
        title: options.title,
        message,
        actions: [
          {
            type: 'cancel' as ActionType,
            label: options.cancelLabel || getLabel('cancel'),
            click: () => {
              if (options.onCancel) options.onCancel()
              resolve(false)
            },
            autoClose: true,
          },
          {
            type: options.variant || ('primary' as ActionType),
            label: options.confirmLabel || getLabel('confirm'),
            click: () => {
              if (options.onConfirm) options.onConfirm()
              resolve(true)
            },
            autoClose: true,
          },
        ],
      })
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
