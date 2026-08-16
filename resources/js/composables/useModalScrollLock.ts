let lockCount = 0
let previousBodyOverflow = ''
let previousBodyPaddingRight = ''
let previousHtmlOverflow = ''

export function lockModalScroll(): void {
    if (lockCount === 0) {
        previousBodyOverflow = document.body.style.overflow
        previousBodyPaddingRight = document.body.style.paddingRight
        previousHtmlOverflow = document.documentElement.style.overflow

        document.body.classList.add('modal-open')
        document.body.style.overflow = 'hidden'
        document.documentElement.style.overflow = 'hidden'
    }

    lockCount++
}

export function unlockModalScroll(): void {
    if (lockCount <= 0) {
        return
    }

    lockCount--

    if (lockCount === 0) {
        document.body.classList.remove('modal-open')
        document.body.style.overflow = previousBodyOverflow
        document.body.style.paddingRight = previousBodyPaddingRight
        document.documentElement.style.overflow = previousHtmlOverflow
    }
}

export function forceUnlockModalScroll(): void {
    lockCount = 0
    document.body.classList.remove('modal-open')
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
    document.documentElement.style.removeProperty('overflow')
}
