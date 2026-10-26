# Enecom IT Solutions GH Platform (Starter)

A PHP + MySQL foundation for XAMPP, designed to deploy online later. Includes:
- Responsive dashboard UI and installable PWA shell
- PDO database connection and setup SQL
- Login/logout with password hashing and session regeneration
- Role-aware dashboard: admin sees internal service prices; customers do not
- Starter customer/service/request schema and secure service listing API
- WhatsApp click-to-chat fallback (recommended first step)
- Customer self-registration with email uniqueness and password hashing
- Customer service request submission with generated reference code, priority and activity log
- Unified website support inbox with customer/staff conversations, auto-refresh, browser desktop notifications and WhatsApp Cloud API webhook/send integration (provider credentials required)
- Enecom company logo applied to dashboard, sign-in and registration pages

## 1. Install on XAMPP
1. Copy the `enecom_platform` folder to `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Open `http://localhost/phpmyadmin`.
4. Import `database/schema.sql`.
5. Edit `config/config.php` if your MySQL username/password differs.
6. Visit `http://localhost/enecom_platform/public/seed_admin.php` ONCE to create the first admin.
7. Sign in at `http://localhost/enecom_platform/public/login.php`.
8. Delete `public/seed_admin.php` immediately after successful setup.
9. Customers can register at `http://localhost/enecom_platform/public/register.php`.
10. Signed-in customers can submit requests from their dashboard or at `request-create.php`.
11. Open `http://localhost/enecom_platform/public/support.php` to use the website support centre.

Default initial admin credentials:
- Email: admin@enecom.local
- Password: ChangeMe!2026

Change this password immediately after first login. The seeder refuses to create another admin if one already exists.

## 2. WhatsApp Cloud API setup
This project uses the official WhatsApp Business Platform Cloud API. WhatsApp will not receive or send live messages until Meta credentials and a public HTTPS webhook are configured.

1. Deploy the project to a publicly reachable HTTPS domain. A localhost XAMPP URL cannot receive Meta webhooks.
2. In Meta for Developers, create/configure a Business app and add WhatsApp. Obtain the phone number ID, access token, and app secret. Subscribe the app to the `messages` webhook field.
3. Configure server environment variables (do not place secrets in public PHP/JS files):
   - `ENE_WA_ACCESS_TOKEN` — Meta access token
   - `ENE_WA_PHONE_NUMBER_ID` — WhatsApp phone number ID, not the phone number itself
   - `ENE_WA_VERIFY_TOKEN` — a random secret you choose for webhook verification
   - `ENE_WA_APP_SECRET` — Meta app secret, used to verify webhook signatures
   - `ENE_WA_GRAPH_VERSION` — Graph API version supported by your Meta app (defaults to `v23.0`; update as Meta versions change)
4. Set the webhook callback URL to `https://YOUR-DOMAIN/enecom_platform/public/whatsapp-webhook.php` (adjust folder path if changed) and enter the same `ENE_WA_VERIFY_TOKEN` in Meta. Verify the webhook and subscribe to `messages`.
5. Restart Apache/PHP after setting environment variables. Test with Meta's test number first.
6. Store customer phone numbers in international format, e.g. Ghana `233501234567`, to improve customer matching.

**Important WhatsApp rules:** Free-form replies are generally allowed within the customer-service window (24 hours after a customer's message). Outside that window, use an approved message template. The current UI sends text replies only; media downloads and template-message composer are not implemented. Meta may reject messages for policy, recipient, permission or token issues, and the UI will report send errors rather than falsely marking them sent.

For local development, website chat continues to work on XAMPP. Use a secure tunnel or deployed HTTPS staging site to test incoming WhatsApp webhooks; never expose a local development server without appropriate security.

## 3. PWA notes
PWA install and service-worker features generally require HTTPS, except localhost. Installability and push notifications vary by browser/platform. The current service worker caches only the app shell; do not cache private customer data or authenticated API responses.

## 4. Security before production
- Use HTTPS and secure cookie settings.
- Change the admin password and remove the seeder.
- Add CSRF tokens to all state-changing forms and rate-limit login.
- Configure secrets through environment variables, not public files. Set `ENE_WA_APP_SECRET` in production so webhook signatures are checked.
- Add backups, audit logs, access controls, retention rules and consent notices.
- Connect real WhatsApp/SMS/telephony providers; the starter does not pretend these channels are live without provider setup.

## 5. Live support scope and limitations
The support inbox polls for website messages and supports browser desktop notifications after the user enables them. WhatsApp inbound webhooks are handled at `public/whatsapp-webhook.php`; staff replies in WhatsApp conversations are sent through the Cloud API when configured. SMS, telephone/VoIP, WhatsApp media download, approved-template composer, Web Push when the app is closed, and WebSocket transport are not implemented yet. Polling is near-real-time, not a WebSocket connection.


## Ticket replies and MoMo payment notices upgrade

For an existing installation, back up the project and database first, then import `database/migration_tickets_momo.sql` in phpMyAdmin after selecting the `enecom_platform` database. Do not re-import `schema.sql` into a live database.

- Staff can open a ticket by selecting its reference in the dashboard, reply in the ticket thread, call the customer's saved phone number via `tel:`, open WhatsApp, assign the ticket, and update its status.
- Customers can open their own tickets, reply, and view the conversation history.
- Customer dashboard includes company MoMo number **0242288811**, payment instructions, and a form to submit amount, transaction reference and purpose.
- Payment notices are stored as `pending` for manual verification. This is not a live payment gateway and does not automatically confirm transfers. Verify in the company MoMo account before marking a payment as received in your operational records.
- New ticket replies and payment notices require the migration table to exist before use.


## Automatic ticket notifications upgrade

For an existing Enecom installation, back up the project and database, then import `database/migration_automatic_notifications.sql` in phpMyAdmin after clicking/selecting the existing `enecom_platform` database in the left sidebar. This creates the `user_notifications` table and preserves existing users and requests. Do not re-import `schema.sql` into a live database.

- When an admin/staff member assigns a request to a worker, the selected worker receives an in-app notification.
- When a request status changes, the customer who submitted it receives an in-app notification.
- The dashboard and ticket page refresh notifications about every 20 seconds while open. Users can mark one or all notifications as read.
- Staff/customers can choose **Enable desktop notifications** to allow browser notifications while the relevant Enecom page is open. Browser permissions generally require HTTPS (localhost is an exception).
- This version uses authenticated polling, not Web Push. Notifications will not arrive on a closed browser or a powered-off device. WhatsApp/SMS/email notification delivery is not included in this upgrade.

Install the updated `public/dashboard.php`, `public/request-view.php`, `public/assets/css/app.css`, `public/service-worker.js`, and the new `public/notifications-api.php` and `public/assets/js/notifications.js` from the package. Preserve all existing configuration files and credentials; this upgrade does not require replacing `config/config.php` or `config/whatsapp.php`. After importing the migration, sign out and back in, then test assigning a request and changing its status using separate staff and customer accounts.

## Admin-created staff accounts and live activity monitor

For an existing database, back up the database first, select `enecom_platform` in phpMyAdmin, then import `database/migration_staff_accounts_live_activity.sql` ONCE. This adds staff usernames/codes, department and last-seen tracking. Do not rerun the migration after it succeeds.

- Admins can create staff accounts from the dashboard's **Staff Accounts** section. Choose the department, enter the worker's first name, last name and contact number; the generated username/code follows `EITS/DEPARTMENTCODE-FIRST4-LAST4`, for example `EITS/SD-EBEN-7221`.
- The generated temporary password is displayed once after creation. Share it privately. The generated username and temporary password can be used at sign-in; customers continue to sign in with email.
- When work email is omitted, an internal placeholder email under `staff.enecom.local` is generated. It is not a deliverable mailbox. Add a real work email if email notifications are needed later.
- Admins see worker account records, online/offline presence and recent recorded activity on the dashboard. Presence is based on an authenticated page heartbeat (20-second interval; online if seen in the last 90 seconds). The activity feed refreshes every 15 seconds.
- Activity monitoring records application events such as login/logout, ticket changes, request submissions, support actions and account creation where those actions call the audit logger. It does not record screens, keystrokes or activity outside the Enecom application.
- This version does not yet force a password change on first login. Treat the displayed password as temporary and require staff to change it through a future password-change module before production use.
