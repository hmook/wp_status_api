# Status API (WordPress plugin)

Deze plugin exposeert een REST API endpoint met status-/storingsinformatie.

## Installatie

- Plaats de plugin in `wp-content/plugins/wp_status_api/`
- Activeer de plugin in WordPress

## Authenticatie

De API ondersteunt Bearer-token authenticatie. In de admin-instellingenpagina kun je een token genereren op basis van een API key/secret.

## Endpoints

- `GET /wp-json/status-api/v1/status`

### Voorbeeld (curl)

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "https://example.com/wp-json/status-api/v1/status"
```

## Response (indicatief)

- `title` (string)
- `text` (string)
- `baseURL` (string)
- `status` (string)
- `timestamp` (int)
- `statusExpiryDate` (string|null)
- `statusExpiryTimestamp` (int|null)

## Development

- Composer (optioneel): `composer install`
- Vendor wordt niet gecommit (zie `.gitignore`).

## Licentie

Proprietary (zie `LICENSE`).

