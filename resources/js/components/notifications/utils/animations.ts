/**
 * Noms de transitions Vue réutilisables.
 * Classes CSS définies dans styles/notification-center.css
 */

export const TRANSITION_FADE = 'nc-fade'
export const TRANSITION_SLIDE = 'nc-slide'
export const TRANSITION_SCALE = 'nc-scale'

export const animationClasses = {
    fade: TRANSITION_FADE,
    slide: TRANSITION_SLIDE,
    scale: TRANSITION_SCALE,
    drawerBackdrop: 'nc-drawer-fade',
    drawerPanel: 'nc-drawer-slide',
    toast: 'nc-toast',
    listItem: 'nc-item-in',
} as const

export type AnimationName = keyof typeof animationClasses
