import { usePage } from '@inertiajs/vue3'

export function getCsrfTokenFromMeta(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

export function updateCsrfMetaToken(token: string): void {
    if (!token) {
        return
    }

    const meta = document.querySelector('meta[name="csrf-token"]')
    if (meta) {
        meta.setAttribute('content', token)
    }
}

export function useCsrfToken() {
    const page = usePage()

    const getCsrfToken = (): string => {
        const token = (page.props as { csrf_token?: string }).csrf_token
        return token || getCsrfTokenFromMeta()
    }

    return { getCsrfToken }
}
