# Fast2SMS phone verification

The site supports phone verification for signup and password recovery using the existing Fast2SMS bulkV2 API transport. The account's full API key is required; the masked screenshot is not a usable credential. No key belongs in source code, Git, JavaScript, screenshots or chat.

Local secret file: `E:\All Here\Wormhole BWA59na\php-backend\.env`.

Production: put the same private settings in the new hosting platform's application environment or the deployed `php-backend/.env`. Uploading code or pulling Git does not copy private environment settings.

```dotenv
FAST2SMS_API_KEY=<paste the full authorization key privately>
FAST2SMS_ROUTE=otp
PHONE_VERIFICATION_REQUIRED=true
EMAIL_VERIFICATION_REQUIRED=false
```

`otp` uses the existing `/dev/bulkV2` endpoint and sends the code in `variables_values`. API access alone does not guarantee that the account allows this route. Provider rejection is returned as a failed send, never as success. There is no automatic switch to Quick SMS (`q`), so an unavailable OTP route cannot silently select a more expensive route. Only configure `dlt` or `q` deliberately for an account that permits that route. Smart OTP's `/dev/otp/send` is a different service requiring an OTP template ID and is not selected by these settings.

The existing schema must include User.phone, User.phone_verified and PhoneOtp (PostgreSQL migration 003; included in the MySQL schema). Signup requires server-recorded phone verification, records phone_verified=1 and consumes the code. Both `/api/auth.php?action=signup` and `/api/auth/signup` enforce it. SMS recovery accepts only a unique account with a previously verified phone; old unverified contact fields cannot reset an account. Recovery codes use a separate namespace and are consumed atomically with the password change. Other recovery remains available through the existing email endpoints if SMTP is configured.

Local tests send only to a fake loopback provider with a dummy API key; they do not spend Fast2SMS balance:

```text
node backend/test_sms_signup.cjs
node backend/test_sms_signup.cjs --serve
```

While the fake provider is serving, run the browser test with BASE_URL=http://127.0.0.1:5261 and SMS_MOCK=1:

```text
npx playwright test e2e/sms-otp.spec.js
```

After the real key is privately configured and the changes deployed, a delivery test needs an explicitly chosen recipient number. Provider acceptance alone does not prove handset delivery; check both the recipient and Fast2SMS delivery reports. These tests do not establish the account's production route permissions.
