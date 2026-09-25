# University LMS frontend

React + TypeScript + Vite. It talks to the Laravel API in `../backend` from the browser. The full description, what each role sees, and how it is deployed are in the [root README](../README.md#frontend).

```
npm install
npm run dev        # http://localhost:5173 with hot reload
npm run typecheck
npm test           # Vitest, with a fake API
npm run build      # production build in dist/
```

The API address comes from `public/config.js` (`window.__LMS_CONFIG__.apiUrl`), or `VITE_API_URL`. In the container it is generated at start-up from `LMS_API_URL`.

## Layout

| Path | What is there |
|---|---|
| `src/api/client.ts` | The only code that calls the API: token, timeouts, safe retries, `Idempotency-Key`, error messages, file downloads |
| `src/api/types.ts` | The shapes the API returns |
| `src/auth/` | Sign-in state and permission checks (`can`, `hasRole`) |
| `src/components/` | Layout, dialogs, toasts, form fields, loading / empty / error states |
| `src/pages/` | One file (or folder) per area: auth, account, messages, courses, admin |
| `src/lib/` | Formatting, notification wording, small hooks |
| `tests/` | Client, formatting and screen tests |

Every screen handles loading, empty and failed states, and shows the API's own validation messages next to the field they belong to.
