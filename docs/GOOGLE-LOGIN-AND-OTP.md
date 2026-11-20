# Google sign-in and OTP: set-up guide

Since this update (called "Module 7A"):

- **Everyone signs in with their institute Google account.** Students use `@stu.manit.ac.in`. Faculty, non-teaching
  staff and authorities use `@manit.ac.in`. A private Gmail account is always refused.
- The **admin** also keeps a username + password login as an emergency backup. It is under "Administrator login" on
  the sign-in page. Authorities no longer have passwords.
- Before **every grievance**, the person asks for a **one-time password (OTP)**. One 6-digit code goes to each
enabled channel: institute e-mail and, when configured, the mobile number on the master list. Typing the code
from either one verifies them.
  The contact number on the form can still be changed, but it is only saved with that grievance.
- **My grievances** replaces "Track your grievance". After signing in, a person sees all their own grievances, reads
  the remarks and answers questions. The reference number + captcha page is gone.

## 1. Upgrade the database

```
php spark migrate
php spark portal:test        (should end with "Failed: 0")
```

The migration does the following:
- adds the e-mail column to the master list;
- creates the OTP table;
- removes the old captcha setting;
- adds the OTP settings (Admin → Settings → Security);
- clears any "must change password" flag on authority accounts.

## 2. Fill in the e-mail addresses (nobody can sign in without one)

- **Master list:** download the new template from Admin → Master list → Bulk upload. It has an extra **Email** column at
  the end. Upload the file again with the e-mails filled in. A blank Email cell never erases a stored e-mail. Files made
  from the old template (without the Email column) are still accepted. You can also edit one person at a time.
  People without an e-mail show a yellow "none: cannot sign in" badge.
- **Mobile numbers:** the **Contact** column of the master list is where the mobile OTP goes, so keep it correct.
- **Authority accounts:** every account now needs its `@manit.ac.in` e-mail (Admin → Authorities → Edit). Accounts
  without one show "No e-mail: cannot sign in". The bulk-upload template no longer has a password column.
- **The admin (optional):** to let the admin also use Google, run
  `php spark portal:admin-email name@manit.ac.in` (`--clear` removes it). The password login keeps working.

A staff member who is on the master list AND holds an authority post uses one Google sign-in. They get both
"My grievances" and the "Authority dashboard" in the top menu.

## 3. Create the Google sign-in credentials (institute IT / Google Workspace admin)

1. Open https://console.cloud.google.com and sign in with an institute admin account. Create a project, e.g.
   "MANIT Grievance Portal".
2. Open **Google Auth Platform** (in older consoles: "APIs & Services → OAuth consent screen"):
   - **Branding:** app name "MANIT Grievance Portal", a support e-mail, and the portal's domain.
   - **Audience:**
     - Choose **Internal** if `stu.manit.ac.in` and `manit.ac.in` are in the **same** Google Workspace organisation.
     - Choose **External** if the student domain is a separate Workspace, then press **Publish app**. The portal only
       asks for `openid email profile`, which needs no Google verification.
     - With External, any Google account could reach the consent screen, but the portal itself refuses every account
       that is not an institute Workspace account of the two domains.
3. **Clients → Create client → Web application.** Add these under **Authorised redirect URIs**:
   - `https://<your portal address>/auth/google/callback`
   - `http://localhost:8080/auth/google/callback` (only if you want to try real Google sign-in on your own computer)
4. Copy the **Client ID** and **Client secret** into `.env`:
   ```
   google.clientId     = '1234-abc.apps.googleusercontent.com'
   google.clientSecret = 'GOCSPX-...'
   ```
   The secret must never be put in any file other than `.env`.

**Windows / XAMPP:** if sign-in fails with "The portal could not reach Google", PHP cannot check HTTPS certificates. In
`C:\xampp\php\php.ini`, set
`curl.cainfo = "C:\xampp\apache\bin\curl-ca-bundle.crt"` and `openssl.cafile = "C:\xampp\apache\bin\curl-ca-bundle.crt"`,
then restart. Details of the failure are in `writable/logs/log-<date>.log`.

## 4. E-mail for the OTP (Google Workspace)

Create a mailbox such as `grievance-noreply@manit.ac.in`, turn on 2-Step Verification for it, and create an
**App password** (Google Account → Security → App passwords). Then in `.env`:

```
otp.emailMode    = 'smtp'
email.protocol   = 'smtp'
email.SMTPHost   = 'smtp.gmail.com'
email.SMTPPort   = 587
email.SMTPCrypto = 'tls'
email.SMTPUser   = 'grievance-noreply@manit.ac.in'
email.SMTPPass   = '<16-letter app password>'
email.fromEmail  = 'grievance-noreply@manit.ac.in'
email.SMTPTimeout = 15
```

A Workspace mailbox can send about 2,000 messages a day. For more, ask the Workspace admin to set up the
**SMTP relay service** (`smtp-relay.gmail.com`) instead. If an e-mail fails, the reason is written to
`writable/logs/log-<date>.log`.

## 5. SMS (not connected yet)

For now the SMS text is written to `writable/logs/otp-<date>.log`, as decided. On the live portal
(`CI_ENVIRONMENT = production`), the person is therefore only told about the e-mail, and the e-mail must work.
Set `otp.smsDriver = ''` in `.env` to disable SMS delivery entirely; OTPs will then be sent only by e-mail.

To add a real SMS gateway later:
- Register the institute's sender ID and an OTP message template on DLT (a TRAI rule in India).
- Choose a gateway such as MSG91 or Fast2SMS.
- Write a small class in `app/Libraries/Sms/` that implements `SmsSender`, add it to `OtpService::smsSender()`, and set
  `otp.smsDriver` in `.env`.

The log file contains working codes, so do not share it, and delete old `otp-*.log` files from time to time.

## 6. OTP rules (Admin → Settings → Security)

| Setting | Default |
|---|---|
| OTP valid for | 10 minutes |
| Wrong entries allowed (then a new OTP is needed) | 5 |
| Wait before a new OTP can be sent | 60 seconds |
| OTPs per person per hour | 5 |

Asking for a new OTP replaces the old one. An OTP works for one grievance only. If the form has an error
(e.g. a missing subject), the same OTP can be used again for that grievance. Only a hash of the code is stored.

"Send OTP" works in the background: the page does not reload, so the typed text and the chosen files stay in place.
The button then counts down until a new OTP may be requested. (Only in a browser with JavaScript switched off does the
page reload, keeping the text but not the files.)

## 7. Testing on your own computer (no Google needed)

In `.env`:

```
CI_ENVIRONMENT  = development
google.devLogin = true
otp.emailMode   = 'log'
```

The sign-in page then shows "Test sign-in (development only)". Type any institute e-mail that is on the master list or
belongs to an authority account. OTPs (e-mail and SMS) appear in `writable/logs/otp-<date>.log`. All the test scripts
in `tests/manual/` use this test sign-in. The test sign-in can **never** work when `CI_ENVIRONMENT = production`.

Order of the test scripts is unchanged: re-create the throw-away database before each one (setup guide, section 13).
`auth_test.sh` now creates its own `hod.cs` account, so the manual SQL step for it is no longer needed.
