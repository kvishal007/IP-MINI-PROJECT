# FitTrack — Viva Voce Question Bank

**CS2307 — Internet Programming Laboratory**
**Pavithran · III Year B.Tech AI&DS · Kamaraj College of Engineering and Technology**

Twenty questions an examiner is likely to ask, with short answers you can give
verbally, followed by the exact place in the code where each answer lives.

---

### 1. Why did you choose PHP for this project?

The laboratory permits only Servlet, JSP or PHP, and PHP was the most suitable of the three for this application. It runs on the XAMPP stack already installed in the lab, requires no compilation or deployment step — saving a file and refreshing the browser is the whole edit cycle — and its PDO extension gives clean, portable access to MySQL with prepared statements. Since FitTrack is a server-rendered database application with no need for a servlet container, PHP keeps the project to plain files that can be dropped into `htdocs`.

---

### 2. What is a prepared statement, and what does it prevent?

A prepared statement sends the SQL command and the data to the database **separately**. The query is first parsed and compiled with placeholders (`:phone`), and only then are the actual values bound to those placeholders. Because the database has already decided the structure of the query before it ever sees the data, the data can never be interpreted as SQL. This prevents **SQL injection**. If someone typed `' OR '1'='1` into the search box, a concatenated query would treat it as logic, but a prepared statement treats it as an ordinary string to search for. Every single query in FitTrack is a prepared statement.

> *Code:* `includes/functions.php` → `phone_exists()`; `members/register.php` → the INSERT.

---

### 3. Why is your database in 3NF? What would go wrong otherwise?

A table is in 3NF when every non-key attribute depends on the key, the whole key, and nothing but the key. In FitTrack, if the plan fee were stored on each member row instead of in the `plan` table, then `member_code → plan_name → fee` would be a **transitive dependency**. Three anomalies would follow: an **update anomaly** — changing the Annual fee would require editing every Annual member's row, and missing one would leave the data contradictory; a **deletion anomaly** — deleting the last Annual member would erase the existence of the Annual plan itself; and an **insertion anomaly** — a new plan could not be recorded until someone subscribed to it. Splitting plans and trainers into their own tables removes all three.

> *Code:* `sql/fittrack.sql` → the `plan`, `trainer` and `member` table definitions.

---

### 4. How exactly is the attendance percentage computed?

```
window_end   = LEAST(today, join_date + plan duration)
present_days = attendance rows for that member BETWEEN join_date AND window_end
window_days  = DATEDIFF(window_end, join_date) + 1
attendance % = ROUND(present_days / window_days × 100, 2)
```

The key point is that the numerator and the denominator cover the **same** window. An earlier version counted all-time attendance over a denominator capped at the plan duration, which produced percentages above 100%. Restricting the count to the same window fixes it, and because `UNIQUE(member_id, att_date)` allows at most one row per calendar day, the count can never exceed the number of days — so the result is mathematically guaranteed to stay within 0–100%.

> *Code:* `sql/fittrack.sql` → the `attendance_pct` column of the view `v_member_summary`.

---

### 5. How do you handle division by zero in that formula?

With `NULLIF(denominator, 0)`. If a member joined today and the window is somehow zero days wide, `NULLIF` converts the zero denominator to NULL, and division by NULL yields NULL rather than raising a division-by-zero error. The PHP side then renders NULL as 0.00% or as an em-dash. The PHP helper `attendance_pct()` does the same thing with an explicit `if ($divisor <= 0) return 0.0;` guard.

> *Code:* `sql/fittrack.sql` (view), `includes/functions.php` → `attendance_pct()`.

---

### 6. What is a session? How does your login use it?

HTTP is stateless — the server does not remember that the same browser sent an earlier request. A **session** solves this: on `session_start()` PHP issues the browser a session ID cookie and keeps a matching file of data on the server. On successful login FitTrack stores the user's ID, username, role and display name in `$_SESSION`, and every subsequent page reads the role from there. The data itself never travels to the browser — only the ID does. Calling `session_regenerate_id(true)` immediately after login issues a **new** ID, which defeats **session fixation** (an attacker who planted a known session ID beforehand finds it useless). `logout.php` calls `session_destroy()` to discard it.

> *Code:* `login.php`, `logout.php`, `includes/auth.php`.

---

### 7. What is the difference between GET and POST? Where did you use each?

**GET** appends data to the URL, is limited in length, is bookmarkable and cached, and appears in browser history and server logs — so it is appropriate for requests that only *read* data. **POST** carries data in the request body, has no practical size limit, and is not cached or bookmarked — so it is appropriate for requests that *change* data. In FitTrack, searching, filtering, pagination and report parameters use GET, which is why a filtered analyzer view can be bookmarked and shared. Registration, renewal, attendance marking, cancellation and deletion all use POST, because each one modifies the database.

> *Code:* GET → `members/list.php` filter form; POST → `members/register.php`.

---

### 8. What does ON DELETE CASCADE do? Why did you use it here?

It is a referential action on a foreign key: when the parent row is deleted, the database automatically deletes every child row that referenced it. In FitTrack, `attendance.member_id` and `payment.member_id` both declare `ON DELETE CASCADE`, because an attendance record or a payment has no meaning without the member it belongs to — leaving them behind would create **orphan rows** whose foreign key points to nothing. Note that `member.trainer_id` deliberately uses `ON DELETE SET NULL` instead: if a trainer resigns, their members must survive and simply become unassigned.

> *Code:* `sql/fittrack.sql` → the FK definitions on `attendance`, `payment` and `member`.

---

### 9. What is the difference between soft delete and hard delete? Which is the default here?

A **soft delete** marks a row as inactive while keeping it in the table; a **hard delete** physically removes it. FitTrack's default is a soft delete: cancelling a membership sets `status = 'Cancelled'` and records `cancel_date` and `cancel_reason`, so the member's attendance history and payment history — which are financial and operational records — remain intact and the member can be restored with one click. The hard delete is available only to an administrator, is confirmed twice, and is used only when a record must genuinely disappear, for example a duplicate created by mistake.

> *Code:* soft → `members/cancel.php`; hard → `members/delete.php`.

---

### 10. Why did you use a transaction? Show me where.

A transaction groups several statements so they either **all** succeed or **none** do. Registration inserts a member row and an opening payment row; without a transaction, a failure between the two would leave a member with no payment record. `beginTransaction()` opens it, `commit()` makes both permanent, and `rollBack()` in the `catch` block undoes the first insert if the second fails. The same pattern protects renewal (update member + insert payment), bulk attendance marking, and permanent deletion (delete attendance + payments + member).

> *Code:* `members/register.php`, `members/edit.php` (renew action), `attendance/bulk.php`, `members/delete.php`.

---

### 11. How are passwords stored? Why not encrypt them?

They are stored as **bcrypt hashes** produced by `password_hash($password, PASSWORD_BCRYPT)`, never as plain text. Hashing is deliberately *one-way* — unlike encryption, there is no key that turns the hash back into the password. At login, `password_verify()` hashes the submitted password with the same salt (which bcrypt stores inside the hash string itself) and compares the results. Encryption would be the wrong tool because it is reversible: anyone who obtained the key would recover every password. bcrypt is also deliberately **slow**, which makes brute-force attacks expensive.

> *Code:* `login.php` → `password_verify()`; `sql/fittrack.sql` → the seeded `$2y$10$…` hashes.

---

### 12. What is CSRF and how did you protect against it?

Cross-Site Request Forgery is an attack where a malicious page causes a logged-in user's browser to submit a request to your site — the browser helpfully attaches the session cookie, so the request looks authentic. The defence is a secret the attacker cannot read: on first use, FitTrack generates a random 32-byte token, stores it in the session, and embeds it as a hidden field in every POST form. `validate_csrf()` compares the submitted token with the session copy using `hash_equals()`, a timing-safe comparison, and aborts with HTTP 403 if they differ. An attacker's forged form cannot contain the token because same-origin policy prevents them from reading it.

> *Code:* `includes/auth.php` → `csrf_token()` and `validate_csrf()`.

---

### 13. What is XSS and how did you prevent it?

Cross-Site Scripting is the injection of script into a page through data. If a member were registered with the name `<script>alert(1)</script>` and that name were printed raw, the script would execute in every staff member's browser. FitTrack routes **every** echoed value through the helper `h()`, which wraps `htmlspecialchars()` with `ENT_QUOTES` and UTF-8, converting `<`, `>`, `&`, `"` and `'` into HTML entities so the browser renders them as text rather than markup. Chart data is emitted with `json_encode()`, which escapes it correctly for a JavaScript context.

> *Code:* `includes/functions.php` → `h()`; used in every page.

---

### 14. How do you stop the same member checking in twice on one day?

With a composite constraint in the schema: `UNIQUE KEY uq_member_date (member_id, att_date)`. The database itself refuses a second row for the same member on the same date, so the rule holds even if the application logic were bypassed or two requests arrived simultaneously. The application also checks first, so the user sees a clear message rather than an error: if a row already exists without a check-out, the action becomes a **check-out**; if the row is already complete, it reports that the member is done for the day. The bulk screen uses `INSERT IGNORE`, so already-marked members are silently skipped rather than aborting the batch.

> *Code:* `sql/fittrack.sql` (the UNIQUE key); `attendance/mark.php`; `attendance/bulk.php`.

---

### 15. What is a VIEW? Why did you create `v_member_summary`?

A view is a stored SELECT statement that behaves like a virtual table — it holds no data of its own but runs its query whenever it is referenced. `v_member_summary` joins `member`, `plan` and `trainer` and adds three derived columns: `attendance_pct`, `last_visit` and `days_since_visit`. Without it, the attendance-percentage formula would be duplicated in the member list, the analyzer, the report card, the trainer page and the CSV export — five copies to keep in step. With it, the formula is defined **once**, and every report reads the same number, so the figures can never disagree.

> *Code:* `sql/fittrack.sql` → `CREATE OR REPLACE VIEW v_member_summary`.

---

### 16. What is the difference between WHERE and HAVING? Where did that matter?

`WHERE` filters **individual rows before** grouping and aggregation; `HAVING` filters **groups after** aggregation, so only `HAVING` can reference an aggregate or a derived column. This mattered for the attendance-band filter in the analyzer: `attendance_pct` is a computed column, so `WHERE attendance_pct >= 75` is rejected — the filter must be written as `HAVING v.attendance_pct >= 75`. Conversely, the plan and trainer filters are ordinary column comparisons and correctly use `WHERE`, which is also faster because it reduces the rows before any grouping work is done.

> *Code:* `reports/analyzer.php` → `$band_having` versus `$mem_where`.

---

### 17. How does the dropout-risk flag work?

It uses `DATEDIFF(CURDATE(), MAX(att_date))` — the number of days between today and the member's most recent visit. The rule is applied only to members whose status is **Active**, because an expired or cancelled member is not "at risk of leaving"; they have already stopped. A gap of 10 to 20 days flags the member **At Risk**; 21 days or more, or no recorded visit at all, flags them **Likely Dropout**. The list is sorted with the longest absences first so reception staff call the most urgent cases first. The NULL case — a member who has never attended — is handled explicitly with `days_since_visit IS NULL`, because in SQL any comparison against NULL yields NULL rather than true.

> *Code:* `reports/analyzer.php` (the risk query); `includes/functions.php` → `dropout_risk()`.

---

### 18. How is the renewal expiry date calculated, and why that rule?

`new expiry = GREATEST(current_expiry, today) + plan duration`. The `GREATEST` is the important part. If a member renews **early**, while their current plan still has days left, the new period is added to the existing expiry so those paid-for days are not forfeited. If a member renews **after** lapsing, the current expiry is in the past, so `GREATEST` picks today and the new period starts from today rather than retroactively consuming months in which the member had no access. The same operation also restores the status to Active, clears any cancellation fields and inserts the payment row — all in one transaction.

> *Code:* `includes/functions.php` → `compute_renewal_expiry()`; `members/edit.php` → the `renew` action.

---

### 19. Why did you compute plan-wise revenue with subqueries instead of a JOIN?

Because joining two one-to-many relationships in the same query multiplies them. `plan` has many `member` rows and also many `payment` rows; joining all three produces one row for every *combination* of a member and a payment on that plan, so `COUNT(member_id)` and `SUM(amount)` both come out inflated — during testing this reported 132 members on a plan that had only 11. The fix is to compute each figure independently, as its own correlated subquery in the SELECT list, so neither aggregate can be multiplied by the other's row count.

> *Code:* `reports/analyzer.php` → the plan-wise query with `(SELECT COUNT(*) …)` and `(SELECT SUM(amount) …)`.

---

### 20. What is `ONLY_FULL_GROUP_BY` and did it affect your queries?

It is a MySQL SQL mode, enabled by default since MySQL 5.7, which rejects a query that selects a non-aggregated column not listed in the `GROUP BY` clause — because such a column would have to pick arbitrarily from many rows in the group. It did affect the monthly-trend query: the original wrote `SELECT DATE_FORMAT(att_date, '%b %Y') … GROUP BY YEAR(att_date), MONTH(att_date)`, and MySQL refused it because `att_date` is not functionally dependent on the grouping expressions. The fix was `DATE_FORMAT(MIN(att_date), '%b %Y')` — wrapping the column in an aggregate makes the expression legal and, since every row in the group falls in the same month, produces exactly the same label.

> *Code:* `index.php` and `reports/analyzer.php` → the monthly-trend query.

---

## Rapid-fire extras

| Question | Short answer |
|---|---|
| Why `PDO::ATTR_EMULATE_PREPARES => false`? | It forces *real* server-side prepared statements instead of PHP faking them by escaping and interpolating locally. |
| Why `utf8mb4` and not `utf8`? | MySQL's `utf8` stores only 3 bytes per character; `utf8mb4` is true 4-byte UTF-8 and handles the full Unicode range. |
| What does `AUTO_INCREMENT` give you? | A guaranteed-unique surrogate primary key, so the table never depends on a natural key that might change. |
| Why both `member_id` and `member_code`? | `member_id` is the internal surrogate key used by foreign keys; `member_code` (FT-2026-001) is the human-readable identifier printed on the card. |
| Why is `amount` stored on `payment` when `plan.fee` exists? | `amount` is a historical fact — what was actually collected that day. Revising a plan's fee must not rewrite past receipts. |
| What does `LEFT JOIN` do that `JOIN` does not? | It keeps rows from the left table even when there is no match on the right — used so members with no trainer, and trainers with no members, still appear. |
| Why validate on the server when JavaScript already validates? | Client-side validation is only a convenience; it can be disabled or bypassed entirely. The server is the only trustworthy gate. |
| What is `htmlspecialchars` with `ENT_QUOTES`? | It escapes both double and single quotes in addition to `<`, `>` and `&`, which matters when the value lands inside a single-quoted HTML attribute. |
| How does the CSV export work? | `reports/export_csv.php` sends a `Content-Disposition: attachment` header, writes a UTF-8 BOM so Excel reads Indian names correctly, then `fputcsv()` handles quoting, and the script exits so no HTML follows. |
| How does the print view work? | An `@media print` block in the single stylesheet hides the sidebar, buttons and filters, forces every tab pane visible so the whole report prints, and renders badges as outlined text so it stays legible in greyscale. |

---

FitTrack © 2026 | Developed by Pavithran | III AI&DS | KCET
