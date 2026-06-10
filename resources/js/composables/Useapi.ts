import { ref } from 'vue';

interface ApiOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
    body?: Record<string, unknown>;
}

export function useApi() {
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function request<T>(url: string, options: ApiOptions = {}): Promise<T | null> {
        loading.value = true;
        error.value = null;

        try {
            const res = await fetch(url, {
                method: options.method ?? 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
                    ),
                },
                body: options.body ? JSON.stringify(options.body) : undefined,
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                error.value = data.message ?? `Error ${res.status}`;
                return null;
            }

            return await res.json() as T;
        } catch (e) {
            error.value = 'Error de conexión';
            return null;
        } finally {
            loading.value = false;
        }
    }

    const get  = <T>(url: string) => request<T>(url);
    const post = <T>(url: string, body: Record<string, unknown>) => request<T>(url, { method: 'POST', body });
    const put  = <T>(url: string, body: Record<string, unknown>) => request<T>(url, { method: 'PUT', body });
    const del  = <T>(url: string) => request<T>(url, { method: 'DELETE' });

    return { loading, error, get, post, put, del };
}