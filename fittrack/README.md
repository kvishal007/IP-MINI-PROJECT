# FitTrack — Gym Membership & Attendance Analyzer

A PHP + MySQL web application that replaces a gym's paper register with a
relational database, and then **analyzes** that data: attendance percentage,
visit streaks, dropout risk, peak-hour load, plan-wise revenue and renewal
alerts.

| | |
|---|---|
| **Student** | Pavithran |
| **Class** | III Year B.Tech — Artificial Intelligence and Data Science |
| **Register Number** | 21UAD___ |
| **College** | Kamaraj College of Engineering and Technology<br>(An Autonomous Institution — Affiliated to Anna University, Chennai)<br>K. Vellakulam, Virudhunagar - 625 701 |
| **Department** | Department of Artificial Intelligence and Data Science |
| **Course** | CS2307 - Internet Programming Laboratory |
| **Submission** | October, 2026 |

---

## 1. Technology Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, vanilla JavaScript, Bootstrap 5 (CDN), Chart.js 4 (CDN) |
| Backend | PHP 8.x — procedural / light-OOP, **no framework, no Composer** |
| Database | MySQL / MariaDB (`fittrack_db`) |
| Server | Apache via XAMPP |
| DB access | PDO with **prepared statements only** |
| Auth | PHP `$_SESSION`, `password_hash()` / `password_verify()` |

No Node, no npm, no build step. Drop the folder into `htdocs`, import one
`.sql` file, and every page works.

---

## 2. Setup (Deployment Procedure)

1. **Install XAMPP** (PHP 8.x bundle) from <https://www.apachefriends.org>.
2. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
3. Copy the whole `fittrack` folder into `C:\xampp\htdocs\`
   so the path is `C:\xampp\htdocs\fittrack\`.
4. Open **phpMyAdmin** at <http://localhost/phpmyadmin>.
5. Click **Import → Choose File**, select `fittrack/sql/fittrack.sql`, and press **Go**.
   The script creates the `fittrack_db` database, all six tables, the
   `v_member_summary` view, and the seed data — you do **not** need to create
   the database by hand first.
6. If your MySQL uses a password, edit `config/db.php` and set `DB_PASS`
   (everything is configured in that one file).
7. Browse to <http://localhost/fittrack>.
8. Log in with **admin / admin123**.

### Login Credentials

| Username | Password | Role | Can do |
|---|---|---|---|
| `admin` | `admin123` | admin | Everything, including permanent delete |
| `staff` | `staff123` | staff | Registration, attendance, reports — **no** permanent delete |

---

## 3. The Five Modules

### Module 1 — Registration (INSERT)
`members/register.php`, `plans/manage.php`, `trainers/manage.php`

Registers a member with full server-side validation (required fields, email
format, 10-digit phone, age 12–80 from DOB, duplicate phone/email rejected).
Auto-generates the member code `FT-2026-001`, computes
`expiry_date = join_date + plan duration`, inserts the first payment row, and
sets status `Active` — all inside one **transaction**. New plans and trainers
are registered from their own admin screens.

### Module 2 — Attendance Marking (INSERT + UPDATE)
`attendance/mark.php`, `today.php`, `bulk.php`, `history.php`

Search a member by code / name / phone, then **check in**. A second click the
same day records **check-out** and computes the workout duration. A second
check-in on the same date is blocked by `UNIQUE(member_id, att_date)`.
Expired or Cancelled members are refused with a **"Renew now"** link into
Module 3. "Today's Register" lists everyone present today; Bulk Mark marks a
batch of members for a chosen date in one transaction.

### Module 3 — Update / Renewal (UPDATE)
`members/edit.php`

Edit personal details (form pre-filled from the DB), reassign the trainer, and
**renew** a membership: pick a plan, record the payment (amount, mode, paid
date), extend `expiry = GREATEST(current expiry, today) + duration_months`,
flip status back to `Active`, and insert a new payment row — in one
transaction. Plan fee and duration are edited in `plans/manage.php`; a wrong
attendance entry is corrected in `attendance/delete_entry.php`.

### Module 4 — Analyzer / Reports (SELECT with aggregation)
`index.php`, `reports/analyzer.php`, `member_report.php`, `revenue.php`, `export_csv.php`

The core of the project. Every metric is real SQL aggregation — `GROUP BY`,
`COUNT`, `SUM`, `AVG`, `DATEDIFF`, `HAVING`, `JOIN` — not PHP loops over full
tables:

- KPI cards: total / active / expired members, present today, month revenue, average attendance %
- Attendance % per member, banded **Excellent ≥75 · Good 50–74 · Poor 25–49 · Critical <25**
- Current and longest visit streaks (member report card)
- **Dropout risk**: no visit in 10+ days → *At Risk*; 21+ days or never → *Likely Dropout*
- Peak-hour analysis (bar chart) with the busiest hour named in words
- Weekday footfall, Mon–Sun (bar chart)
- Monthly attendance trend, last 6 months (line chart)
- Plan-wise distribution and revenue (doughnut chart)
- Trainer load and each trainer's members' average attendance %
- Renewal alerts: expiring in 7 days, and already expired
- Individual member report card with a 30-day attendance heat-strip
- Filters: date range, plan, trainer, attendance band
- **CSV export** of any report table, and a **print stylesheet** (`@media print`)

### Module 5 — Cancellation / Deletion (DELETE)
`members/cancel.php`, `members/delete.php`, `attendance/delete_entry.php`, `plans/manage.php`

- **Soft delete (default)**: cancel a membership with a reason (dropdown +
  notes) — sets status `Cancelled` with `cancel_date` and `cancel_reason`,
  keeping all history. Cancelled members are listed with a **Restore** action.
- **Hard delete (admin only)**: permanently removes the member and cascades
  their attendance and payment rows inside a **transaction**
  (`ON DELETE CASCADE` is also declared in the schema).
- Delete a single wrong attendance record.
- A plan can only be deleted when **no member and no payment** reference it —
  otherwise the app refuses with an explanation.

### Login Module (supporting)
`login.php`, `logout.php`, `includes/auth.php`

Role-based sessions (`admin` / `staff`). Every page except `login.php` starts
with `require_login()`; admin-only pages call `require_admin()`.
`logout.php` destroys the session.

---

## 4. Folder Structure

```
fittrack/
├── config/
│   └── db.php                  PDO connection — the one file to edit
├── includes/
│   ├── auth.php                session guard, role check, CSRF helpers
│   ├── header.php              shared layout: sidebar + topbar
│   ├── footer.php              shared closing markup + scripts
│   └── functions.php           validation, member code, attendance %,
│                               streaks, expiry, flash messages, CSV export
├── login.php  logout.php
├── index.php                   dashboard: KPI cards + 4 charts + alerts
├── members/
│   ├── register.php            Module 1 — INSERT
│   ├── list.php                searchable, sortable, paginated list
│   ├── view.php                profile, payments, recent attendance
│   ├── edit.php                Module 3 — details / renew / trainer / restore
│   ├── cancel.php              Module 5 — soft delete with reason
│   └── delete.php              Module 5 — hard delete (admin only)
├── attendance/
│   ├── mark.php                Module 2 — check-in / check-out
│   ├── today.php               today's register
│   ├── bulk.php                batch marking in a transaction
│   ├── history.php             filtered history + CSV
│   └── delete_entry.php        delete one wrong entry
├── plans/manage.php            plan master (add / edit fee+duration / guarded delete)
├── trainers/manage.php         trainer master + load analysis
├── reports/
│   ├── analyzer.php            Module 4 — the analyzer
│   ├── member_report.php       per-member report card + heat-strip
│   ├── revenue.php             revenue by month / plan / mode + ledger
│   └── export_csv.php          CSV download endpoint (8 report types)
├── assets/
│   ├── css/style.css           one stylesheet (incl. print styles)
│   ├── js/script.js            one script file (incl. all Chart.js charts)
│   └── img/logo.svg, logo.png, favicon.png
├── sql/fittrack.sql            schema + view + seed data, single import
└── README.md
```

---

## 5. Database Design

Six tables in 3NF plus one view.

| Table | Purpose | Key constraints |
|---|---|---|
| `plan` | Membership plans | `plan_name` UNIQUE |
| `trainer` | Trainer master | — |
| `member` | Core member record | `member_code`, `phone`, `email` UNIQUE; FK → `plan`, FK → `trainer` `ON DELETE SET NULL` |
| `attendance` | Daily check-in / check-out | **`UNIQUE(member_id, att_date)`**; FK → `member` `ON DELETE CASCADE` |
| `payment` | One row per payment / renewal | FK → `member` `ON DELETE CASCADE`, FK → `plan` |
| `login` | admin / staff accounts | `username` UNIQUE; bcrypt password |

**View `v_member_summary`** joins member + plan + trainer and computes
`attendance_pct`, `last_visit` and `days_since_visit`, so every report reads
those figures from one place.

### How attendance % is computed

```
window_end    = LEAST(today, join_date + plan duration)
present_days  = attendance rows for that member BETWEEN join_date AND window_end
window_days   = DATEDIFF(window_end, join_date) + 1
attendance %  = ROUND(present_days / window_days * 100, 2)
```

Numerator and denominator cover the **same** window, so the result can never
exceed 100%. `NULLIF(..., 0)` guards against division by zero for a member who
joined today.

### Seed data

5 plans, 4 trainers, 2 login users, 30 members (mixed Active / Expired /
Cancelled, joined across the last 8 months), ~1,900 attendance rows and the
matching payment rows. The data is deliberately shaped so every report is
non-empty: some members near 85% attendance and some near 20%, several with a
2–4 week gap so the dropout list has entries, check-ins clustered at 6–8 AM and
6–9 PM so peak hours are visible, and weekends lighter than weekdays.

---

## 6. Security and Code Quality

- **100% prepared statements** — no string-concatenated SQL anywhere.
- All output escaped with `htmlspecialchars()` (via the `h()` helper).
- **CSRF token** on every POST form, validated server-side.
- Passwords stored with `password_hash()`, checked with `password_verify()`;
  `session_regenerate_id(true)` on login prevents session fixation.
- **Transactions** for every multi-table write (registration, renewal, bulk
  marking, permanent delete).
- Session guard included on every page except `login.php`.
- Client-side validation is a convenience only — PHP is the real gate.
- Edge cases handled: zero members, member with zero attendance,
  division-by-zero in the percentage formula, expiry in the past, NULL
  `check_out`, deleting a plan that is in use.

---

## 7. Troubleshooting

| Problem | Fix |
|---|---|
| "Database Connection Failed" | Start MySQL in the XAMPP panel; check `DB_USER` / `DB_PASS` in `config/db.php`. |
| Import error in phpMyAdmin | Import `sql/fittrack.sql` as a whole file — it contains a `DELIMITER` block for the attendance-seeding procedure and must not be pasted in pieces. |
| Page shows "404 Not Found" | The folder must be named exactly `fittrack` inside `htdocs`. |
| Charts are blank | The CDN links need an internet connection the first time; check the browser console. |
| Times look wrong by a few hours | The app pins the timezone to `Asia/Kolkata` in `config/db.php`. |

---

FitTrack © 2026 | Developed by Pavithran | III AI&DS | KCET
