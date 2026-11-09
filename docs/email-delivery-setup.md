# Enable signup and password-reset email codes

The live site currently reports `email_verification: false`. The error “Email delivery is not configured on this deployment” means SMTP_HOST, SMTP_USER or SMTP_PASSWORD is missing. No sending account is configured in the local environment files either. Password reset and signup use the same mailer; changing frontend code cannot supply the missing account. After rehosting, restore the previous host's private environment settings: Git intentionally excludes `.env`, and hosting environment variables are not transferred by a repository deployment.

On a managed platform, restore these in the new application's environment-variable settings. On cPanel, edit the **live** `public_html/php-backend/.env`. Use the outgoing mail settings supplied by your mailbox or SMTP provider:

```dotenv
SMTP_HOST=<outgoing SMTP hostname>
SMTP_PORT=465
SMTP_USER=<SMTP login>
SMTP_PASSWORD=<mailbox password or SMTP key>
SMTP_SENDER_EMAIL=<verified sender address>
SMTP_SENDER_NAME=bet1x
EMAIL_VERIFICATION_REQUIRED=true
```

Port 465 uses implicit TLS. If the provider specifies port 587, use 587; the mailer then uses STARTTLS. Keep TLS certificate checks enabled. SMTP_SENDER_EMAIL must be permitted by the provider. For a cPanel mailbox, obtain the exact host, username and port from **Email Accounts → Connect Devices → Secure SSL/TLS Settings**. Use the mailbox password, not the cPanel account password. For Gmail use an App Password; for Brevo use its SMTP key, not its HTTP API key.

Do not commit this file, send credentials in chat, or put them into HTML/JavaScript. cPanel environment variables, if set, override `.env` and must agree with these settings.

After saving, confirm `/api/health` reports `email_verification: true`, refresh the site, and request a signup code for an address you control. Verify it and complete signup. Then test password reset for that account. A successful SMTP response proves provider acceptance; check the recipient inbox/spam folder to confirm actual delivery. If sending fails, the server error log identifies the failed SMTP stage without logging the OTP or password. The EmailOtp table must also exist (migration 004 on PostgreSQL; included in the MySQL schema).
