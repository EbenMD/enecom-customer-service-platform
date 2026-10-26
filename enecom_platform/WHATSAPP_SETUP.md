# Enecom WhatsApp setup checklist

## Local XAMPP
- Website chat works at `http://localhost/enecom_platform/public/support.php`.
- Meta cannot send webhook requests to localhost directly. Use an HTTPS staging domain or secure tunnel for webhook tests.

## Production/staging
- Public HTTPS callback: `/enecom_platform/public/whatsapp-webhook.php`
- Configure environment variables: `ENE_WA_ACCESS_TOKEN`, `ENE_WA_PHONE_NUMBER_ID`, `ENE_WA_VERIFY_TOKEN`, `ENE_WA_APP_SECRET`, and optionally `ENE_WA_GRAPH_VERSION`.
- Subscribe the Meta app to the WhatsApp `messages` webhook field.
- Set the callback verify token to exactly the value of `ENE_WA_VERIFY_TOKEN`.
- Keep tokens out of the repository and web root.
- Test with Meta's test phone number before switching to the business number.
- Ensure the customer-service window/template policy is followed.

## Existing database
The current schema already has `conversations`, `messages`, `external_contact`, `provider_message_id`, and `delivery_status`. Optional indexes are provided in `database/migration_live_support_whatsapp.sql`. Run it only if the indexes do not already exist; MySQL versions that don't support `CREATE INDEX IF NOT EXISTS` will return a duplicate-index error if rerun. No table data needs to be dropped or recreated.
