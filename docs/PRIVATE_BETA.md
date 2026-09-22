# NileBit POS for Retail private beta

Public self-registration is disabled in production. Existing invited users can
continue to sign in and access only the shops assigned to them.

## Production environment

Set these values in the backend service environment. Do not commit real
credentials, database URLs, or application keys.

```dotenv
APP_URL=https://api.nilebitlabs.com
CORS_ALLOWED_ORIGINS=https://pos.nilebitlabs.com,https://nilebitlabs.com
PUBLIC_REGISTRATION=false
```

`CORS_ALLOWED_ORIGINS` is a comma-separated allow-list of exact browser
origins. Do not use `*` in production.

## Invite an owner

Run this from the backend release environment after migrations have completed:

```bash
php artisan beta:invite-owner \
  --name="Owner Name" \
  --email="owner@example.com" \
  --organization="Business Name" \
  --shop="Main Shop"
```

The command securely prompts for the temporary password; it cannot accept one
as a command-line argument, does not print it, and never issues an API token.
It creates the user, organisation, first shop, and owner membership in one
database transaction. Share the temporary password only through an approved
secure channel.
