# Smart Campus Kiosk · STI Global City

A responsive visitor-management website based on **Revised Chapter 1: Smart Campus Kiosk — RFID-Authenticated Visitor Management with a Web-Based Monitoring Dashboard**.

## Open in XAMPP

1. Start **Apache** in the XAMPP Control Panel.
2. Open **http://localhost/SCK-STI%20GLOBAL%20CITY/**.
3. Click **Explore the demo dashboard**, or sign in with an account below.

No npm install, frontend build, or MySQL setup is required. PHP 8.2 and PDO SQLite are included in this XAMPP installation. The relational database initializes automatically at **data/campus.sqlite** when the application first needs records.

| Role | Email | Password |
| --- | --- | --- |
| Administrator | admin@sti.local | CampusAdmin!2026 |
| Security personnel | security@sti.local | CampusSecurity!2026 |

**These are demonstration accounts; seeded visitor records are fictional.** Before recording actual visitor details, create institution-controlled accounts, sign in with the new administrator, and deactivate both demonstration accounts through **User accounts**. The demo button uses the initial administrator account and ceases working after that account is deactivated.

## Features

- Live overview: active visitors, today's visits, visit duration, badge availability, recent activity, and hourly visitor flow.
- Registration with validated visitor information and an available temporary badge.
- Automatic server timestamps in Asia/Manila for check-in and check-out.
- Gate kiosk accepting manual UIDs or keyboard input from compatible RFID readers.
- History search by visitor, organization, badge code or UID, date, and entry or exit time.
- Administrator editing of visitor details while preserving timestamps and badge assignment.
- Badge activation/deactivation; assigned badges cannot be deactivated.
- Date-based reports, daily counts, visit-purpose breakdowns, and filtered CSV downloads.
- Administrator/security roles and account creation/deactivation.
- Responsive navigation, labeled forms, accessible dialogs, keyboard controls, and reduced-motion support.

## RFID workflow

1. Security staff verify identification and enter the visitor's information.
2. Scan an available badge into the registration form or select it from the list, then click **Register & check in**. The server saves the visit and entry timestamp.
3. At departure, open **Gate kiosk** and scan the assigned physical badge. A compatible reader should type its UID and send Enter. Manual UID input works for evaluation.
4. The server records checkout and releases the badge for reuse.

Sample UIDs follow **STI00000001**, **STI00000002**, etc. Check **RFID badges** for the exact badge identifiers. Reader output must match the registered UID; other reader formats need configuration or an adapter. Phones do not inherently read these badges. RFID links an identifier to a visit and does not independently establish identity. Immediately repeated scans after registration are briefly blocked to prevent accidental checkout.

## Optional PHP server

From the project folder:

~~~powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8765 router.php
~~~

Open **http://127.0.0.1:8765/**. Keep **router.php** in the command to protect the database, library, test, and hidden files.

## Validation

~~~powershell
& 'C:\xampp\php\php.exe' tests/backend-smoke.php
node --check assets/app.js
node tests/browser-smoke.mjs
~~~

The backend test starts a temporary PHP server and isolated database. It covers permissions, CSRF, registration, duplicate assignments, checkout, history filtering, reports, CSV protection, account controls, and login rate limits. The browser smoke test uses locally installed Chrome, Node 22+, and PHP without third-party dependencies. Both tests leave saved campus records unchanged.

## Structure and safeguards

- **index.php**: application shell and sign-in screen.
- **assets/**: local CSS, JavaScript, and favicon; no third-party runtime assets.
- **api.php**: authenticated API and CSV exports.
- **lib/bootstrap.php**: relational schema, seeds, validation, sessions, and database helpers.
- **data/**: persistent SQLite records, excluded from version control.
- **router.php**: protected development-server routing.
- **tests/**: isolated integration and browser checks.

Passwords are hashed, database queries use prepared statements, mutations require CSRF tokens, and sessions expire after 30 minutes without deliberate activity. Automatic background refreshes do not extend an unattended session. CSV cells are protected against spreadsheet formula injection. Apache access rules protect data, libraries, and tests; verify these rules when moving servers.

This is a local project prototype. Evaluate actual reader integration, institution-specific data retention, backups, and HTTPS hosting before operational use. A server connection is required; offline synchronization is not guaranteed. SQLite makes local evaluation straightforward; moving to MySQL/PostgreSQL requires adapting the schema and queries. This system manages visitor visits and does not record daily student or staff attendance.


## Archiving and deleting records

Sign in as an administrator. Completed visits have **Archive** actions in Visitor history and Visitor details; unassigned RFID badge cards have an **Archive** action.

Open **Archives** in the sidebar and choose **Visitors** or **RFID badges**. Use row checkboxes or **Select all on page**, then choose **Restore selected** or **Delete selected**. Up to 100 records can be selected at once. Permanent deletion requires an explicit confirmation in the application.

Active visitors must be checked out before archiving, and assigned badges cannot be archived. Archived badges are excluded from registration and scans. Restored badges remain inactive until activated in RFID badges. Archived completed visits still contribute to reports until permanently deleted. Deleting a badge preserves its recorded code and UID in past visits.

The existing SQLite database upgrades automatically on the next authenticated request without resetting visitor records.


## Names and personal account settings

Visitor registration, visitor editing, and account creation use **First name**, optional **Middle initial**, and **Last name**. Display names are composed automatically. Existing saved display names are retained during the database upgrade; their editable name parts are initially inferred, so confirm multi-part family names when editing older records.

Every signed-in administrator and security user can open **My account** in the sidebar, or click their top-right account button and choose **Edit profile**. Account settings let users update their own name and email, change their password, and upload or remove a profile picture. Changing email or password requires the current password; role and account activation remain administrator controls.

Profile pictures accept **JPG, PNG, or WebP**, up to **2 MB** and **4096 pixels per side**. Files are stored under the protected data directory and served through an authenticated endpoint. Test runs use separate temporary databases and photo directories.
