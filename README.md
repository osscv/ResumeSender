<div align="center">

# 📨 Resume Sender

### *A self-hosted bulk email platform — send personalised job applications, marketing campaigns, newsletters and outreach at scale.*

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-3.x-38B2AC?logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![No Composer](https://img.shields.io/badge/Composer-Not%20Required-success)](#)
[![Self-Hosted](https://img.shields.io/badge/Self--Hosted-Yes-blue)](#)
[![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen)](#)
[![License](https://img.shields.io/badge/License-Proprietary-red)](#-license)
[![Author](https://img.shields.io/badge/By-Lay%20Yang%20(DKLY)-181717?logo=github&logoColor=white)](https://www.dkly.net)

**Use any SMTP as a sending channel — and stack multiple channels for higher throughput:**

[![Gmail](https://img.shields.io/badge/Gmail-EA4335?logo=gmail&logoColor=white)](#-smtp-channels)
[![Outlook](https://img.shields.io/badge/Outlook-0078D4?logo=microsoftoutlook&logoColor=white)](#-smtp-channels)
[![Yahoo Mail](https://img.shields.io/badge/Yahoo_Mail-6001D2?logo=yahoo&logoColor=white)](#-smtp-channels)
[![Zoho Mail](https://img.shields.io/badge/Zoho_Mail-DC2626?logo=zoho&logoColor=white)](#-smtp-channels)
[![iCloud](https://img.shields.io/badge/iCloud_Mail-3693F3?logo=icloud&logoColor=white)](#-smtp-channels)
[![Self-Hosted](https://img.shields.io/badge/Self--Hosted_SMTP-444?logo=postfix&logoColor=white)](#-smtp-channels)
[![Custom](https://img.shields.io/badge/Custom_SMTP-Any%20Provider-success)](#-smtp-channels)
[![Multi-Channel](https://img.shields.io/badge/Multi--Channel-Stack%20%26%20Rotate-blueviolet)](#-smtp-channels)

**[What is it?](#-what-is-it)** • **[Use Cases](#-use-cases)** • **[SMTP Channels](#-smtp-channels)** • **[Quick Start](#-quick-start)** • **[How to Use](#-how-to-use)** • **[Why It's Fast](#-why-its-fast)**

</div>

---

## 🤔 What is it?

Resume Sender is a **self-hosted, all-purpose bulk email platform** built in PHP + MySQL.

Despite its name, it's **not just for sending resumes**. It's a general-purpose engine for any one-to-many email scenario where you want:

- 📧 **Email marketing campaigns** with personalised content
- 💼 **Job application outreach** with attached CV / cover letter
- 📣 **Newsletters** to your community or customers
- 🤝 **Cold outreach** for sales, partnerships or PR
- 📮 **Transactional batches** (event invites, announcements, follow-ups)

It connects to **any SMTP server** as a sending channel — your Gmail, Outlook, Yahoo, Zoho, iCloud, your company mail server, your own self-hosted Postfix box, anything that speaks SMTP.

You can **stack multiple SMTP channels** in one account — each with its own daily quota — and the app will track quota per channel and auto-block any channel that hits its limit, so your campaign keeps running on the other channels.

- ✅ Personalises every email (`{company_name}`, `{position}`, `{email}` placeholders)
- ✅ Multiple file attachments per send (CV, brochure, invoice, etc.)
- ✅ Tracks who you've contacted, when, with which result
- ✅ **Multi-channel SMTP** — add as many channels as you want, each with its own quota
- ✅ Sends 100+ emails in under a minute (see [Why It's Fast](#-why-its-fast))
- ✅ Fully self-hosted — your contact list never leaves your server

> **No Composer. No Node. No build step.** Just PHP + MySQL + Tailwind via CDN.

---

## 🎯 Use Cases

Anywhere you'd normally reach for a SaaS email tool — or "let me just BCC 50 people in Outlook" — Resume Sender does it, on your own server, using your own SMTP channels.

| You are... | You'll use it to... |
|---|---|
| 🧑‍💼 **A job seeker** | Send your CV to 80 companies tonight, with personalised cover letters |
| 📣 **A marketer** | Run email marketing campaigns — product launches, promos, drip sequences |
| 📰 **A newsletter author** | Send your weekly digest to subscribers with rich HTML and attachments |
| 💼 **A freelancer / consultant** | Cold-pitch prospects without SaaS quotas eating your margin |
| 🛍 **A small business** | Send invoices, receipts, order updates, customer announcements |
| 👔 **A small recruiter** | Manage candidate outreach with multi-user accounts and per-recruiter lists |
| 🎟 **An event organiser** | Send invitations, reminders, follow-up surveys to attendees |
| 🤝 **A sales team** | Cold outreach with templates, follow-up tracking, multi-SMTP rotation |
| 📢 **A PR / community manager** | Press releases, member updates, partner communications |
| 🛠 **A developer** | Hack on a clean, dependency-light PHP/MySQL outreach base |

---

## 📮 SMTP Channels

Resume Sender treats every SMTP server you add as a **sending channel**. You can add as many channels as you want, each with its own daily quota — no third-party SaaS, no API keys, just plain SMTP.

### 🧩 The multi-channel concept

> **One channel = one SMTP account.** Stack multiple channels to multiply your daily sending capacity and survive any single-channel outage.

```
   ┌──────────────────────────────────────────────────────────┐
   │                    Your Campaign                         │
   │                  (e.g. 1,500 emails)                     │
   └─────────────┬─────────────┬─────────────┬────────────────┘
                 │             │             │
            ┌────▼────┐   ┌────▼────┐   ┌────▼────┐
            │ Channel │   │ Channel │   │ Channel │
            │   #1    │   │   #2    │   │   #3    │
            │ Gmail A │   │ Gmail B │   │ Outlook │
            │ 500/day │   │ 500/day │   │ 300/day │
            └─────────┘   └─────────┘   └─────────┘
                 │             │             │
                 └──── Auto-block on quota ──┘
                      Continue on the rest
```

If one channel hits its quota mid-campaign, the app **auto-blocks that channel for the day** and keeps sending through the others.

### ☁️ Common channel setups

| Channel type | Host | Port | Encryption | Notes |
|---|---|---|---|---|
| 📧 **Gmail / Google Workspace** | `smtp.gmail.com` | `587` | TLS | Use an [App Password](https://myaccount.google.com/apppasswords) — quota ~500/day |
| 📨 **Outlook / Hotmail / Microsoft 365** | `smtp-mail.outlook.com` / `smtp.office365.com` | `587` | TLS | Quota ~300 (consumer) / ~10,000 (M365) per day |
| 💌 **Yahoo Mail** | `smtp.mail.yahoo.com` | `465` | SSL | App password required — quota ~500/day |
| 📬 **iCloud Mail** | `smtp.mail.me.com` | `587` | TLS | App-specific password required |
| 🌐 **Zoho Mail** | `smtp.zoho.com` | `465` | SSL | Quota varies by plan |
| 🇨🇳 **QQ / 163 / Sina** | provider-specific | `465` | SSL | Authorisation code required |
| 🏢 **Your company mail server** | your hostname | varies | varies | Use the SMTP creds your IT team gave you |
| 🐧 **Self-hosted Postfix / Mailcow / iRedMail** | your hostname | `587` | TLS | Full control, unlimited quota |
| 🔧 **Any custom SMTP** | anything | anything | anything | If it speaks SMTP, it works |

### ⚙️ Adding a channel

In **Settings → Add SMTP Server**, you only need:

```
Host:        smtp.your-provider.com
Port:        587 (TLS) or 465 (SSL) or 25 (none)
Username:    your-account@example.com
Password:    your-password / app-password
Encryption:  TLS / SSL / None
From email:  sender@example.com
From name:   Your Name
Daily quota: 500          ← matches your channel's limit
```

That's it — Resume Sender handles the SMTP handshake, AUTH LOGIN, MIME assembly, attachments and connection pooling automatically.

### 💡 Why multiple channels?

| Benefit | What it means in practice |
|---|---|
| 🚀 **Higher throughput** | 3 Gmail channels × 500/day = 1,500 emails/day instead of 500 |
| 🛡 **Quota safety** | One channel hitting its limit doesn't kill the campaign |
| 🎭 **Multiple identities** | Send from different from-addresses for different campaigns |
| 🌍 **Provider diversity** | Mix Gmail + Outlook + your own server — no single point of failure |
| 💰 **Zero SaaS cost** | Reuse free SMTP accounts you already have |

---

## ✨ Features at a Glance

<table>
<tr>
<td width="33%" valign="top">

#### 📧 Sending
- Personalised placeholders
- Multiple SMTP servers
- Daily quota enforcement
- Auto-block on quota exceeded
- File attachments (10MB)
- Test send before batch
- Live progress modal

</td>
<td width="33%" valign="top">

#### 👥 Multi-User
- Admin & user roles
- Account status (active / banned)
- Last-login tracking (time + IP)
- Per-user data isolation
- User profiles
- Admin dashboard
- User management

</td>
<td width="33%" valign="top">

#### 📊 Data
- Recipient list (manual + CSV)
- Email templates
- Full send history
- Per-recipient analytics
- Success/failure logs
- Attachment audit trail

</td>
</tr>
</table>

---

## 🚀 Quick Start

> **Requirements:** PHP 7.4+, MySQL 5.7+ (or MariaDB 10.3+), Apache or Nginx.

### 1. Configure DB credentials

Edit `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'resumesender');
define('DB_USER', 'resumesender');
define('DB_PASS', 'your-strong-password');
```

### 2. Create the database

```sql
CREATE DATABASE resumesender CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'resumesender'@'localhost' IDENTIFIED BY 'your-strong-password';
GRANT ALL PRIVILEGES ON resumesender.* TO 'resumesender'@'localhost';
```

### 3. Run setup (creates tables + seeds admin)

```bash
php setup_db.php
```

### 4. Set permissions

```bash
chmod -R 755 uploads
```

### 5. Open in browser & log in

```
URL:      http://localhost/
Username: admin
Password: admin123
```

> ⚠️ **Change the admin password immediately after logging in.**

---

## 📚 How to Use

<details open>
<summary><strong>Step 1 — Add an SMTP channel</strong></summary>

Go to **Settings → Add SMTP Server** and add your first channel — Gmail, Outlook, Yahoo, your company mail server, your own Postfix box, anything that speaks SMTP.

See **[📮 SMTP Channels](#-smtp-channels)** for host / port reference for the most common channels.

> 💡 **Gmail tip:** use an [App Password](https://myaccount.google.com/apppasswords), not your real password. Daily quota = 500.
>
> 💡 **Need higher throughput?** Add **multiple SMTP channels** — e.g. 3× Gmail = 1,500 emails/day. The app rotates between them automatically and auto-blocks any channel that hits its quota.

</details>

<details open>
<summary><strong>Step 2 — Add recipients</strong></summary>

- **Manually:** Recipients → Add Recipient
- **Bulk CSV:** Recipients → Upload CSV

CSV format:

```csv
email,company_name,position
john@example.com,Acme Inc,Software Engineer
jane@example.com,Tech Corp,Marketing Manager
```

</details>

<details open>
<summary><strong>Step 3 — (Optional) Save a template</strong></summary>

**Templates → New Template.** Loadable on the Send page with one click.

</details>

<details open>
<summary><strong>Step 4 — Send</strong></summary>

Go to **Send**, then:

1. Pick an SMTP server
2. Choose audience: **All / New / Follow-up / Specific**
3. Write subject + body using `{company_name}`, `{position}`, `{email}`
4. Attach files (uploaded **once** per batch — see below)
5. Click **Send Emails**

Watch the progress modal stream per-recipient results in real time.

</details>

---

## ⚡ Why It's Fast

The send pipeline was rebuilt for batch performance. Here's the difference for **100 recipients with a 2MB attachment**:

| | Old pipeline | New pipeline |
|---|---|---|
| ⏱ Wall clock | ~250–400 s | **~25–40 s** |
| 📤 Upload | ~200 MB | **~2 MB** |
| 🔌 SMTP handshakes | 100 | **~10** |

### What changed

- 📎 **Upload attachments once** per batch, not once per recipient
- 🔌 **Pool SMTP connections** — one TCP+TLS+AUTH handshake per chunk of 10 emails
- ⚡ **Parallel chunks** — browser sends 3 chunks concurrently
- 💾 **Pre-encode attachments** — base64 once, reuse N times
- 🛑 **Smart abort** — quota errors auto-block the SMTP and skip remaining recipients

---

## 🔐 Roles

| Role | Can do |
|---|---|
| 👤 **User** | Manage own SMTP servers, recipients, templates, send history |
| 👑 **Admin** | Everything a user can do, **plus** Admin Dashboard, system-wide stats, user management (create / suspend / ban) |

---

## 📂 Project Structure

<details>
<summary><strong>Click to expand file tree</strong></summary>

```
resume-sender/
├── api/
│   ├── recipients_api.php          # CRUD for recipients
│   ├── send_email_api.php          # Single-recipient send (legacy)
│   ├── send_batch_api.php          # Batched send (reused SMTP connection)
│   ├── smtp_api.php                # CRUD for SMTP configs
│   └── upload_attachments_api.php  # Upload-once endpoint
├── pages/
│   ├── admin/
│   │   ├── dashboard.php
│   │   └── users.php
│   ├── home.php                    # User dashboard
│   ├── login.php / logout.php
│   ├── profile.php
│   ├── recipients.php
│   ├── recipient_details.php
│   ├── send.php                    # Compose + send UI
│   ├── settings.php                # SMTP config
│   ├── templates.php
│   └── template_edit.php
├── utils/
│   ├── auth.php                    # Sessions, roles, IP detection
│   ├── csv_parser.php
│   └── email_sender.php            # SMTPMailer
├── uploads/
│   ├── attachments/
│   └── csv/
├── config.php
├── database_schema.sql
├── setup_db.php                    # Schema + admin seeder
├── index.php                       # Auth gate
├── layout.php                      # Sidebar layout
└── README.md
```

</details>

---

## 🗄 Database Schema

6 tables, InnoDB, `utf8mb4_unicode_ci`:

| Table | What it stores |
|---|---|
| `users` | Accounts, roles, status, login tracking, profile |
| `smtp_configurations` | Per-user SMTP servers + quota state |
| `recipients` | Per-user recipient list |
| `email_templates` | Per-user saved templates |
| `email_logs` | Every send attempt with status & error |
| `email_attachments` | Files attached to each successful send |

> See `database_schema.sql` for the authoritative definition.

---

## 🛡 Security

- ✅ All DB queries use **PDO prepared statements**
- ✅ All pages gated by `requireAuth()` / `requireAdmin()`
- ✅ Per-user data scoping via `getUserFilter()`
- ✅ File uploads validated by extension + size (10MB)
- ✅ Attachment references are **opaque tokens** — server reconstructs paths and verifies they resolve inside `ATTACHMENT_DIR` (path-traversal protected)

> ⚠️ **Before production:** change the seeded admin password, change the DB password, serve over **HTTPS**.

---

## 📸 Screenshots

<details>
<summary><strong>Click to view screenshots</strong></summary>

**Dashboard Home**
<img width="1910" height="903" alt="dashboard" src="https://github.com/user-attachments/assets/c81631fa-6dba-4c5a-b36c-7ce3722f0ba5" />

**Send Email**
<img width="1895" height="904" alt="send" src="https://github.com/user-attachments/assets/aea4d9c9-26b7-4c59-b1a9-85724eee3128" />

**SMTP Configuration**
<img width="1919" height="905" alt="smtp" src="https://github.com/user-attachments/assets/8e421fa7-dd11-41b0-bae5-f8e2d0907590" />

**Recipients List**
<img width="1919" height="905" alt="recipients" src="https://github.com/user-attachments/assets/450d617f-4c9f-42e3-b182-3ebc10a8b01a" />

**Recipient Analytics**
<img width="1897" height="900" alt="recipient details" src="https://github.com/user-attachments/assets/d2239791-f0b4-4df5-81d4-169da6b016fb" />

</details>

---

## 📜 License

Proprietary — All rights reserved.

---

<div align="center">

## 👤 Author

### **Lay Yang** *(DKLY)*

[![Website](https://img.shields.io/badge/🌐_www.dkly.net-blue?style=for-the-badge)](https://www.dkly.net)

*Built in PHP — because the simplest stack is often the best one.*

</div>
