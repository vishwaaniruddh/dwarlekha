# 🚀 DwarLekha Production Operations: Cron & Email Setup Guide

> **Platform**: DwarLekha Multi-Tenant Housing Society ERP  
> **Production Target**: Hostinger Cloud / Shared Hosting (`dwarlekha.sarsspl.com`)  
> **PHP Version**: PHP 8.2 / 8.3 / 8.5  

---

## 📑 Table of Contents
1. [Overview](#1-overview)
2. [Recommended Schedule Cycles](#2-recommended-schedule-cycles)
3. [Hostinger cPanel / hPanel Cron Configuration](#3-hostinger-cpanel--hpanel-cron-configuration)
4. [Email & SMTP Gateway Setup](#4-email--smtp-gateway-setup)
5. [Automated Email Triggers](#5-automated-email-triggers)
6. [CLI Flags & Manual Testing](#6-cli-flags--manual-testing)
7. [Troubleshooting & Log Monitoring](#7-troubleshooting--log-monitoring)

---

## 1. Overview

The background automation engine is powered by a unified CLI runner located at:
```
backend/bin/cron.php
```

It autonomously handles:
* **Overdue Status Transitions**: Flags unpaid past-due invoices as `'Overdue'` and syncs `units.maintenance_status`.
* **Visitor Overstay Detection**: Auto-checks out visitors inside society for > 16 hours.
* **Monthly Recurring Billing**: Auto-generates maintenance bills for all occupied flats on the 1st of every month based on active `charge_masters` rules.
* **Automated Database Backups**: Gzips the entire database to `backend/backups/db_backup_*.sql.gz` and prunes backups older than 30 days.
* **Email Notifications**: Automatically dispatches invoice notices, payment receipts, and overdue reminders through each society's configured SMTP gateway.

---

## 2. Recommended Schedule Cycles

| Cycle | Frequency | Crontab Schedule | Task Executed | Command |
| :--- | :--- | :--- | :--- | :--- |
| **Daily** *(Essential)* | Every midnight at 00:00 | `0 0 * * *` | Overdue sync + Visitor cleanup + Nightly DB backup | `--task=daily` |
| **Monthly** *(Essential)* | 1st of month at 01:00 AM | `0 1 1 * *` | Recurring maintenance bill generation across all societies | `--task=monthly --send-emails` |
| **Weekly** *(Recommended)* | Sundays at 03:00 AM | `0 3 * * 0` | Deep backup rotation & cache directory cleanup | `--task=backup` |
| **Hourly** *(Optional)* | Every 2 hours | `0 */2 * * *` | High-security gate visitor overstay detection | `--task=visitors` |

---

## 3. Hostinger cPanel / hPanel Cron Configuration

### Step-by-Step Setup in Hostinger hPanel:
1. Log in to your **Hostinger Dashboard** ➔ Select your hosting plan.
2. In the left sidebar, navigate to **Advanced ➔ Cron Jobs**.
3. Choose **Custom** under *Type*.
4. Enter the schedules and commands below.

> [!IMPORTANT]
> Replace `uXXXXXXX` with your actual Hostinger Linux username.  
> Your document root path is typically `/home/uXXXXXXX/public_html/`.

---

### Cron Entry 1: Daily Maintenance & Overdue Sync (Midnight)
* **Schedule**: `0 0 * * *` (Once a day at midnight)
* **Command**:
  ```bash
  /usr/bin/php /home/uXXXXXXX/public_html/backend/bin/cron.php --task=daily >> /home/uXXXXXXX/cron_daily.log 2>&1
  ```

---

### Cron Entry 2: Monthly Recurring Bill Generation (1st of Every Month)
* **Schedule**: `0 1 1 * *` (At 01:00 AM on the 1st of every month)
* **Command**:
  ```bash
  /usr/bin/php /home/uXXXXXXX/public_html/backend/bin/cron.php --task=monthly --send-emails >> /home/uXXXXXXX/cron_billing.log 2>&1
  ```

---

### Cron Entry 3: Single Unified All-in-One Job (Alternative Setup)
If you prefer configuring only **one single cron job** in Hostinger, use the command below scheduled daily at 01:00 AM. It automatically detects if today is the 1st of the month; if yes, it generates the monthly bills, then performs the daily overdue sync and database backup.

* **Schedule**: `0 1 * * *` (Daily at 01:00 AM)
* **Command**:
  ```bash
  /usr/bin/php /home/uXXXXXXX/public_html/backend/bin/cron.php --task=all --send-emails >> /home/uXXXXXXX/cron_all.log 2>&1
  ```

---

### Webhook / HTTP Fallback (If SSH/CLI is unavailable):
If your hosting plan restricts CLI execution, trigger the runner via cURL:
```bash
curl -s "https://dwarlekha.sarsspl.com/backend/bin/cron.php?token=dwarlekha_cron_secure_token_2026&task=daily"
```

---

## 4. Email & SMTP Gateway Setup

DwarLekha supports **Multi-Tenant SMTP Isolation**: Each housing society can send emails from their own dedicated domain address (e.g. `office@emeraldheights.in`), with automatic fallback to the platform master gateway.

### Setup Instructions in UI:
1. Log in as an Administrator (Society Admin or SAR Platform Admin).
2. Go to **Sidebar ➔ Admin ➔ Email & SMTP Gateway** (or visit `/smtp`).
3. Select your society (if SAR platform admin).
4. Enter your SMTP credentials:

#### Standard Provider Configurations:

| Setting | Hostinger Titan Mail | Google Workspace / Gmail | Private cPanel / VPS |
| :--- | :--- | :--- | :--- |
| **SMTP Host** | `smtp.titan.email` | `smtp.gmail.com` | `mail.yourdomain.com` |
| **Port** | `587` (or `465`) | `587` | `587` (or `465`) |
| **Encryption** | `TLS` (or `SSL`) | `TLS` | `TLS` / `SSL` |
| **Username** | `office@yourdomain.com` | `admin@society.org` | `noreply@yourdomain.com` |
| **Password** | Mailbox password | Google App Password (16-char) | Mailbox password |
| **Sender Name** | `Emerald Heights Office` | `Society Management Office` | `Society Office` |

5. Click **"Test Connection"** to verify authentication and send a live verification email.
6. Click **"Save Configuration"** to activate the gateway.

---

## 5. Automated Email Triggers

The system dispatches emails for the following operational workflows:

```
┌────────────────────────────────────────────────────────────────────────────┐
│                        AUTOMATED EMAIL NOTIFICATIONS                       │
├────────────────────────────┬────────────────────────┬──────────────────────┤
│ Event Trigger              │ Recipient              │ Template             │
├────────────────────────────┼────────────────────────┼──────────────────────┤
│ Monthly Bill Generation    │ Flat Owner & Tenant    │ Detailed Bill PDF    │
│ Payment Succeeded (Online) │ Resident               │ Verified Receipt     │
│ Overdue Status (> Due Date)│ Defaulter Resident     │ Urgent Dues Notice   │
│ Notice Broadcast           │ All Society Residents  │ Announcement Letter  │
│ Security Gate Overstay     │ Resident / Committee   │ Gate Security Alert  │
└────────────────────────────┴────────────────────────┴──────────────────────┘
```

* **Fallback Engine**: If an individual society has not configured custom SMTP, emails automatically route through the SAR Global Platform gateway (`society_id = 0`), ensuring zero missed communications.
* **Audit Trail**: Every outgoing email is logged in the `email_logs` table with delivery status (`sent`, `failed`), recipient email, subject, and timestamp.

---

## 6. CLI Flags & Manual Testing

You can test any cron operation manually via SSH:

```bash
# 1. Run dry-run simulation (verifies logic without database alterations):
php backend/bin/cron.php --dry-run

# 2. Test overdue invoice status synchronization only:
php backend/bin/cron.php --task=overdue

# 3. Test visitor overstay check-out:
php backend/bin/cron.php --task=visitors

# 4. Force monthly bill generation (even if today is not the 1st):
php backend/bin/cron.php --task=monthly --force

# 5. Generate bills and dispatch emails to residents:
php backend/bin/cron.php --task=monthly --force --send-emails

# 6. Target a specific society only (e.g. Society ID #1):
php backend/bin/cron.php --task=daily --society_id=1

# 7. Take an immediate database backup:
php backend/bin/cron.php --task=backup
```

---

## 7. Troubleshooting & Log Monitoring

### Log Files:
* Output logs: `cron_daily.log`, `cron_billing.log` (in `/home/uXXXXXXX/`).
* Database backups: `backend/backups/db_backup_YYYY-MM-DD_His.sql.gz`.
* Email dispatch history: Viewable in UI under **Admin ➔ Email & SMTP ➔ Delivery Logs**.

### Directory Permissions:
Ensure the backups directory has secure write permissions:
```bash
chmod 0750 backend/backups
```
*(The directory contains an automated `.htaccess` preventing direct web downloads of database dumps).*
