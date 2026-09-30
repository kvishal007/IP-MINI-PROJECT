-- =============================================================
-- File        : sql/fittrack.sql
-- Purpose     : Complete schema, view, and seed data for FitTrack
-- Module      : All Modules (Database Layer)
-- Author      : Pavithran
-- College     : Kamaraj College of Engineering and Technology
-- Course      : CS2307 - Internet Programming Laboratory
-- =============================================================

-- Create and select the database
CREATE DATABASE IF NOT EXISTS fittrack_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fittrack_db;

-- -------------------------------------------------------
-- TABLE: plan  (stores membership plans)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS plan (
    plan_id          INT            AUTO_INCREMENT PRIMARY KEY,
    plan_name        VARCHAR(30)    NOT NULL UNIQUE,
    duration_months  INT            NOT NULL,
    fee              DECIMAL(10,2)  NOT NULL,
    sessions_per_week INT           DEFAULT 6,
    description      VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- TABLE: trainer  (stores trainer details)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS trainer (
    trainer_id       INT            AUTO_INCREMENT PRIMARY KEY,
    trainer_name     VARCHAR(40)    NOT NULL,
    specialization   VARCHAR(30),
    phone            VARCHAR(10),
    shift            VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- TABLE: login  (admin and staff accounts)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS login (
    login_id   INT          AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(20)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('admin','staff') NOT NULL,
    full_name  VARCHAR(40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- TABLE: member  (core member information)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS member (
    member_id         INT            AUTO_INCREMENT PRIMARY KEY,
    member_code       VARCHAR(15)    NOT NULL UNIQUE,
    member_name       VARCHAR(50)    NOT NULL,
    gender            ENUM('Male','Female','Other') NOT NULL,
    dob               DATE           NOT NULL,
    phone             VARCHAR(10)    NOT NULL UNIQUE,
    email             VARCHAR(60)    UNIQUE,
    address           VARCHAR(120),
    emergency_contact VARCHAR(10),
    join_date         DATE           NOT NULL,
    expiry_date       DATE           NOT NULL,
    plan_id           INT            NOT NULL,
    trainer_id        INT            NULL,
    status            ENUM('Active','Expired','Cancelled') DEFAULT 'Active',
    cancel_date       DATE           NULL,
    cancel_reason     VARCHAR(100)   NULL,
    CONSTRAINT fk_member_plan    FOREIGN KEY (plan_id)    REFERENCES plan(plan_id),
    CONSTRAINT fk_member_trainer FOREIGN KEY (trainer_id) REFERENCES trainer(trainer_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- TABLE: attendance  (daily check-in / check-out)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    att_id       INT  AUTO_INCREMENT PRIMARY KEY,
    member_id    INT  NOT NULL,
    att_date     DATE NOT NULL,
    check_in     TIME NOT NULL,
    check_out    TIME NULL,
    duration_min INT  NULL,
    UNIQUE KEY uq_member_date (member_id, att_date),
    CONSTRAINT fk_att_member FOREIGN KEY (member_id) REFERENCES member(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- TABLE: payment  (payment records per renewal)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment (
    payment_id   INT            AUTO_INCREMENT PRIMARY KEY,
    member_id    INT            NOT NULL,
    plan_id      INT            NOT NULL,
    amount       DECIMAL(10,2)  NOT NULL,
    paid_date    DATE           NOT NULL,
    mode         ENUM('Cash','UPI','Card') NOT NULL,
    next_due_date DATE          NOT NULL,
    CONSTRAINT fk_pay_member FOREIGN KEY (member_id) REFERENCES member(member_id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_plan   FOREIGN KEY (plan_id)   REFERENCES plan(plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- VIEW: v_member_summary
-- Joins member + plan + trainer + last attendance + payment
-- Computes attendance % using days since join vs present days
-- -------------------------------------------------------
CREATE OR REPLACE VIEW v_member_summary AS
SELECT
    m.member_id,
    m.member_code,
    m.member_name,
    m.gender,
    m.dob,
    m.phone,
    m.email,
    m.join_date,
    m.expiry_date,
    m.status,
    m.cancel_date,
    m.cancel_reason,
    p.plan_id,
    p.plan_name,
    p.duration_months,
    p.fee,
    t.trainer_id,
    t.trainer_name,
    t.specialization,
    -- Attendance percentage = present_days / window_days * 100
    -- The window is join_date .. LEAST(today, join_date + plan_duration),
    -- i.e. capped at the plan's duration so a member is only judged over
    -- the period their current plan actually covers. present_days is
    -- counted over that SAME window (not all-time), which also guarantees
    -- the ratio can never exceed 100% (UNIQUE(member_id, att_date) means
    -- at most one attendance row per calendar day in the window).
    ROUND(
        (
            SELECT COUNT(*) FROM attendance a
            WHERE a.member_id = m.member_id
              AND a.att_date BETWEEN m.join_date
                  AND LEAST(CURDATE(), DATE_ADD(m.join_date, INTERVAL p.duration_months MONTH))
        ) /
        NULLIF(
            DATEDIFF(
                LEAST(CURDATE(), DATE_ADD(m.join_date, INTERVAL p.duration_months MONTH)),
                m.join_date
            ) + 1,
        0) * 100, 2
    ) AS attendance_pct,
    -- Last visit date
    (SELECT MAX(att_date) FROM attendance a WHERE a.member_id = m.member_id) AS last_visit,
    -- Days since last visit
    DATEDIFF(CURDATE(),
        (SELECT MAX(att_date) FROM attendance a WHERE a.member_id = m.member_id)
    ) AS days_since_visit
FROM member m
JOIN plan p    ON m.plan_id    = p.plan_id
LEFT JOIN trainer t ON m.trainer_id = t.trainer_id;

-- =====================================================
-- SEED DATA
-- =====================================================

-- ----- Plans -----
INSERT INTO plan (plan_name, duration_months, fee, sessions_per_week, description) VALUES
('Monthly',     1,   799.00, 6, 'Basic monthly plan with full gym access'),
('Quarterly',   3,  1999.00, 6, 'Three-month plan at a discounted rate'),
('Half-Yearly', 6,  3499.00, 6, 'Six-month plan with trainer consultation'),
('Annual',     12,  5999.00, 6, 'Best value — full year with diet guidance'),
('Student',     3,  1299.00, 5, 'Discounted plan for students with ID proof');

-- ----- Trainers -----
INSERT INTO trainer (trainer_name, specialization, phone, shift) VALUES
('Arjun Selvam',     'Strength',  '9876543210', 'Morning'),
('Deepika Rajan',    'Cardio',    '9876543211', 'Evening'),
('Karthick Murugan', 'CrossFit',  '9876543212', 'Both'),
('Priya Annamalai',  'Yoga',      '9876543213', 'Morning');

-- ----- Login users -----
-- Passwords hashed with PHP's password_hash(..., PASSWORD_BCRYPT) at cost 10.
-- admin / admin123   and   staff / staff123
INSERT INTO login (username, password, role, full_name) VALUES
('admin', '$2y$10$vkvAUcHLBs/3SZQBuPXgauoeOT6s37XKG2KSaak6vtvSjV7v7d9z2', 'admin', 'Admin User'),
('staff', '$2y$10$.Is3ntBkM1Mxs26H4WHiyel6vt4ZmP/KW4KfH0cLnt7kzeTrIPXZ6', 'staff', 'Staff User');

-- -------------------------------------------------------
-- MEMBERS (30 members, mix of statuses)
-- Join dates spread over last 8 months (Feb 2026 – Sep 2026)
-- -------------------------------------------------------
INSERT INTO member (member_code, member_name, gender, dob, phone, email, address, emergency_contact, join_date, expiry_date, plan_id, trainer_id, status) VALUES
('FT-2026-001','Aravind Kumar',   'Male',  '1998-04-12','9500001001','aravind.kumar@gmail.com',   '12 Anna Nagar, Madurai',    '9500002001','2026-02-01','2026-03-01',1,1,'Expired'),
('FT-2026-002','Bavya Devi',      'Female','2000-07-22','9500001002','bavya.devi@gmail.com',       '45 Gandhi Road, Virudhunagar','9500002002','2026-02-05','2026-05-05',2,2,'Active'),
('FT-2026-003','Chandru Sekar',   'Male',  '1995-11-30','9500001003','chandru.s@yahoo.com',       '7 Nehru St, Tirunelveli',   '9500002003','2026-02-10','2026-08-10',3,3,'Active'),
('FT-2026-004','Divya Lakshmi',   'Female','2002-03-18','9500001004','divya.l@gmail.com',          '23 MG Road, Coimbatore',    '9500002004','2026-02-15','2026-03-15',1,4,'Expired'),
('FT-2026-005','Elango Murugan',  'Male',  '1990-08-05','9500001005','elango.m@hotmail.com',       '56 Pillaiyar Koil St, Salem','9500002005','2026-02-20','2027-02-20',4,1,'Active'),
('FT-2026-006','Fathima Begum',   'Female','1997-12-14','9500001006','fathima.b@gmail.com',        '34 Mosque St, Trichy',      '9500002006','2026-03-01','2026-06-01',2,2,'Active'),
('FT-2026-007','Gokul Krishnan',  'Male',  '2001-05-28','9500001007','gokul.k@gmail.com',          '89 KK Nagar, Chennai',      '9500002007','2026-03-05','2026-06-05',5,3,'Expired'),
('FT-2026-008','Harini Priya',    'Female','1999-09-09','9500001008','harini.p@gmail.com',         '12 Lotus Colony, Madurai',  '9500002008','2026-03-10','2027-03-10',4,4,'Active'),
('FT-2026-009','Ilango Rajan',    'Male',  '1993-01-17','9500001009','ilango.r@yahoo.com',         '5 River View, Trichy',      '9500002009','2026-03-15','2026-09-15',3,1,'Active'),
('FT-2026-010','Janaki Sundaram', 'Female','2003-06-25','9500001010','janaki.s@gmail.com',         '78 Saraswati Nagar, Salem', '9500002010','2026-03-20','2026-06-20',2,2,'Cancelled'),
('FT-2026-011','Karthik Raj',     'Male',  '1996-02-14','9500001011','karthik.raj@gmail.com',      '34 Bharathi St, Coimbatore','9500002011','2026-04-01','2027-04-01',4,3,'Active'),
('FT-2026-012','Lavanya Sri',     'Female','2001-10-31','9500001012','lavanya.sri@gmail.com',      '67 Patel Nagar, Madurai',   '9500002012','2026-04-05','2026-07-05',2,4,'Active'),
('FT-2026-013','Mani Bharathi',   'Male',  '1988-07-07','9500001013','mani.b@hotmail.com',         '11 Bazaar St, Virudhunagar','9500002013','2026-04-10','2027-04-10',4,1,'Active'),
('FT-2026-014','Nithya Devi',     'Female','2000-12-02','9500001014','nithya.d@gmail.com',         '45 Old Town, Tirunelveli',  '9500002014','2026-04-15','2026-07-15',2,2,'Expired'),
('FT-2026-015','Oviya Rajan',     'Female','1998-04-20','9500001015','oviya.r@gmail.com',          '23 West St, Salem',         '9500002015','2026-04-20','2026-07-20',2,3,'Active'),
('FT-2026-016','Prabhu Murugan',  'Male',  '1992-09-15','9500001016','prabhu.m@yahoo.com',         '78 East St, Madurai',       '9500002016','2026-05-01','2027-05-01',4,4,'Active'),
('FT-2026-017','Rajesh Kumar',    'Male',  '1985-03-28','9500001017','rajesh.k@gmail.com',         '12 North St, Coimbatore',   '9500002017','2026-05-05','2026-08-05',2,1,'Active'),
('FT-2026-018','Saranya Devi',    'Female','2002-08-11','9500001018','saranya.d@gmail.com',         '56 South St, Salem',        '9500002018','2026-05-10','2026-08-10',2,2,'Active'),
('FT-2026-019','Thirumurugan S',  'Male',  '1994-11-05','9500001019','thiru.s@hotmail.com',        '34 Temple St, Trichy',      '9500002019','2026-05-15','2026-11-15',3,3,'Active'),
('FT-2026-020','Uma Devi',        'Female','1999-02-17','9500001020','uma.d@gmail.com',             '89 New Colony, Madurai',    '9500002020','2026-05-20','2027-05-20',4,4,'Active'),
('FT-2026-021','Vignesh Kumar',   'Male',  '2003-07-04','9500001021','vignesh.k@gmail.com',        '5 Lake View, Virudhunagar', '9500002021','2026-06-01','2026-09-01',5,1,'Expired'),
('FT-2026-022','Wendy Mary',      'Female','1997-05-30','9500001022','wendy.m@gmail.com',           '23 Church St, Trichy',      '9500002022','2026-06-05','2027-06-05',4,2,'Active'),
('FT-2026-023','Xavier Anand',    'Male',  '1991-09-12','9500001023','xavier.a@yahoo.com',         '67 Cross St, Chennai',      '9500002023','2026-06-10','2026-09-10',2,3,'Cancelled'),
('FT-2026-024','Yamuna Devi',     'Female','2000-01-28','9500001024','yamuna.d@gmail.com',          '11 Flower Garden, Madurai', '9500002024','2026-06-15','2026-09-15',2,4,'Active'),
('FT-2026-025','Zubair Ahmed',    'Male',  '1996-06-16','9500001025','zubair.a@gmail.com',          '45 Mosque Lane, Trichy',    '9500002025','2026-06-20','2027-06-20',4,1,'Active'),
('FT-2026-026','Anbu Selvan',     'Male',  '1989-10-24','9500001026','anbu.s@gmail.com',            '78 Nagar, Coimbatore',      '9500002026','2026-07-01','2027-07-01',4,2,'Active'),
('FT-2026-027','Brindha Devi',    'Female','2001-03-15','9500001027','brindha.d@gmail.com',         '12 Garden St, Salem',       '9500002027','2026-07-10','2026-10-10',2,3,'Active'),
('FT-2026-028','Chitra Kannan',   'Female','1998-08-22','9500001028','chitra.k@yahoo.com',          '34 Park Road, Madurai',     '9500002028','2026-07-20','2027-07-20',4,4,'Active'),
('FT-2026-029','Durai Murugan',   'Male',  '1993-05-10','9500001029','durai.m@gmail.com',           '56 Hill View, Trichy',      '9500002029','2026-08-01','2026-11-01',2,1,'Active'),
('FT-2026-030','Eswari Lakshmi',  'Female','2002-11-19','9500001030','eswari.l@gmail.com',          '89 River St, Virudhunagar', '9500002030','2026-08-15','2026-09-15',1,2,'Expired');

-- Update cancelled members
UPDATE member SET status='Cancelled', cancel_date='2026-05-10', cancel_reason='Relocated to another city' WHERE member_code='FT-2026-010';
UPDATE member SET status='Cancelled', cancel_date='2026-08-15', cancel_reason='Financial constraints' WHERE member_code='FT-2026-023';

-- -------------------------------------------------------
-- PAYMENT ROWS (one per member at join; some have renewals)
-- -------------------------------------------------------
INSERT INTO payment (member_id, plan_id, amount, paid_date, mode, next_due_date)
SELECT m.member_id, m.plan_id, p.fee, m.join_date,
       ELT(FLOOR(1+RAND()*3),'Cash','UPI','Card'),
       m.expiry_date
FROM member m
JOIN plan p ON p.plan_id = m.plan_id;

-- Renewal for FT-2026-001 (already expired Monthly → renewed to Monthly again for demo)
INSERT INTO payment (member_id, plan_id, amount, paid_date, mode, next_due_date) VALUES
(1, 1, 799.00, '2026-03-02', 'UPI', '2026-04-02');

-- Renewal for FT-2026-004 (expired, renewed to Quarterly)
INSERT INTO payment (member_id, plan_id, amount, paid_date, mode, next_due_date) VALUES
(4, 2, 1999.00, '2026-03-01', 'Card', '2026-06-01');

-- -------------------------------------------------------
-- ATTENDANCE ROWS
-- Strategy:
--   Active "excellent" members (5,8,11,13,16,20,22,25,26,28): ~85-90% attendance
--   Active "good" members (2,3,6,9,12,15,17,18,19,24,27,29): ~55-65%
--   Active "poor" members (7 already expired, use actives): 25-40%
--   Dropout risk: 5 members with NO visit in last 10-21 days
--   Check-in times: clustered at 06:00-08:00 and 18:00-21:00
--   Weekends lighter (Saturday: 60%, Sunday: 40%)
-- We generate realistic rows for the last 90 days (2026-07-01 to 2026-09-30)
-- -------------------------------------------------------

-- Helper procedure to insert attendance for a range
DROP PROCEDURE IF EXISTS seed_attendance;
DELIMITER $$

CREATE PROCEDURE seed_attendance(
    p_member_id INT,
    p_start_date DATE,
    p_end_date DATE,
    p_attend_pct INT,      -- percent chance per day (0-100)
    p_morning_pct INT,     -- percent chance morning shift vs evening
    p_skip_recent INT      -- 0=normal, N=skip last N days (dropout simulation)
)
BEGIN
    DECLARE cur_date DATE DEFAULT p_start_date;
    DECLARE dow INT;
    DECLARE effective_pct INT;
    DECLARE roll INT;
    DECLARE check_in_time TIME;
    DECLARE check_out_time TIME;
    DECLARE dur INT;
    DECLARE skip_from DATE;

    SET skip_from = DATE_SUB(p_end_date, INTERVAL p_skip_recent DAY);

    day_loop: WHILE cur_date <= p_end_date DO
        -- Skip dropout days
        IF p_skip_recent > 0 AND cur_date >= skip_from THEN
            SET cur_date = DATE_ADD(cur_date, INTERVAL 1 DAY);
            ITERATE day_loop;
        END IF;

        SET dow = DAYOFWEEK(cur_date); -- 1=Sun,2=Mon...7=Sat
        -- Reduce attendance on weekends
        SET effective_pct = p_attend_pct;
        IF dow = 1 THEN SET effective_pct = FLOOR(p_attend_pct * 0.4); END IF; -- Sunday
        IF dow = 7 THEN SET effective_pct = FLOOR(p_attend_pct * 0.6); END IF; -- Saturday

        SET roll = FLOOR(RAND() * 100);
        IF roll < effective_pct THEN
            -- Decide morning or evening
            IF FLOOR(RAND() * 100) < p_morning_pct THEN
                -- Morning: 06:00-08:30
                SET check_in_time = SEC_TO_TIME(
                    (6 * 3600) + FLOOR(RAND() * 150) * 60
                );
            ELSE
                -- Evening: 18:00-20:30
                SET check_in_time = SEC_TO_TIME(
                    (18 * 3600) + FLOOR(RAND() * 150) * 60
                );
            END IF;
            SET dur = 45 + FLOOR(RAND() * 75); -- 45-120 min workout
            SET check_out_time = ADDTIME(check_in_time, SEC_TO_TIME(dur * 60));

            INSERT IGNORE INTO attendance (member_id, att_date, check_in, check_out, duration_min)
            VALUES (p_member_id, cur_date, check_in_time, check_out_time, dur);
        END IF;

        SET cur_date = DATE_ADD(cur_date, INTERVAL 1 DAY);
    END WHILE day_loop;
END$$

DELIMITER ;

-- Now seed attendance for each member.
-- IMPORTANT: for members whose membership has already lapsed, the end date
-- is capped at their real expiry_date / cancel_date (not 2026-09-30) so we
-- never generate a gym visit after a membership stopped being valid — that
-- would be impossible once the app's own check-in guard (Module 2) is in
-- place. Currently-Active members are seeded all the way through today.

-- Excellent attenders (>75%): members 5,8,11,13,16,20,22,25,26,28 (all Active)
CALL seed_attendance(5,  '2026-02-20', '2026-09-30', 88, 60, 0);
CALL seed_attendance(8,  '2026-03-10', '2026-09-30', 85, 55, 0);
CALL seed_attendance(11, '2026-04-01', '2026-09-30', 90, 70, 0);
CALL seed_attendance(13, '2026-04-10', '2026-09-30', 87, 65, 0);
CALL seed_attendance(16, '2026-05-01', '2026-09-30', 85, 60, 0);
CALL seed_attendance(20, '2026-05-20', '2026-09-30', 88, 50, 0);
CALL seed_attendance(22, '2026-06-05', '2026-09-30', 86, 55, 0);
CALL seed_attendance(25, '2026-06-20', '2026-09-30', 89, 60, 0);
CALL seed_attendance(26, '2026-07-01', '2026-09-30', 91, 65, 0);
CALL seed_attendance(28, '2026-07-20', '2026-09-30', 84, 55, 0);

-- Good attenders (50-74%): members 2,3,6,9,12,15,17,18,24 (now Expired — capped
-- at their own expiry_date) plus 19,27,29 (Active — see dropout-risk block below)
CALL seed_attendance(2,  '2026-02-05', '2026-05-05', 62, 50, 0);
CALL seed_attendance(3,  '2026-02-10', '2026-08-10', 58, 55, 0);
CALL seed_attendance(6,  '2026-03-01', '2026-06-01', 65, 60, 0);
CALL seed_attendance(9,  '2026-03-15', '2026-09-15', 60, 45, 0);
CALL seed_attendance(12, '2026-04-05', '2026-07-05', 55, 50, 0);
CALL seed_attendance(15, '2026-04-20', '2026-07-20', 63, 55, 0);
CALL seed_attendance(17, '2026-05-05', '2026-08-05', 58, 60, 0);
CALL seed_attendance(18, '2026-05-10', '2026-08-10', 62, 50, 0);
CALL seed_attendance(24, '2026-06-15', '2026-09-15', 65, 55, 0);

-- Poor attenders (<25% of their plan window): members 1,4 (Expired — capped at expiry)
CALL seed_attendance(1,  '2026-02-01', '2026-03-01', 25, 50, 0);
CALL seed_attendance(4,  '2026-02-15', '2026-03-15', 22, 55, 0);

-- Dropout risk demo: MUST be status='Active' members (the risk flag in 4.4 only
-- applies to Active members), so we use 19, 27 and 29 — all currently Active —
-- and stop their attendance N days before today to create the gap.
CALL seed_attendance(19, '2026-05-15', '2026-09-30', 57, 45, 12); -- no visit last 12 days → At Risk
CALL seed_attendance(27, '2026-07-10', '2026-09-30', 60, 60, 16); -- no visit last 16 days → At Risk
CALL seed_attendance(29, '2026-08-01', '2026-09-30', 58, 50, 24); -- no visit last 24 days → Likely Dropout

-- Members whose membership lapsed or was cancelled BEFORE the dropout-risk
-- window even applies (status filter excludes them from 4.4, but they still
-- need realistic historical attendance for their own report card / revenue):
CALL seed_attendance(14, '2026-04-15', '2026-07-15', 55, 50, 0); -- Expired 2026-07-15
CALL seed_attendance(21, '2026-06-01', '2026-09-01', 60, 55, 0); -- Expired 2026-09-01
CALL seed_attendance(30, '2026-08-15', '2026-09-15', 50, 60, 0); -- Expired 2026-09-15
CALL seed_attendance(7,  '2026-03-05', '2026-06-05', 45, 50, 0); -- Expired 2026-06-05
CALL seed_attendance(23, '2026-06-10', '2026-08-15', 40, 55, 0); -- Cancelled 2026-08-15

-- Drop the seeding procedure (keep DB clean for production)
DROP PROCEDURE IF EXISTS seed_attendance;

-- -------------------------------------------------------
-- Update expired members' status based on expiry_date
-- -------------------------------------------------------
UPDATE member SET status = 'Expired'
WHERE expiry_date < CURDATE()
  AND status = 'Active';

-- -------------------------------------------------------
-- DEMO ADJUSTMENTS (date-relative to CURDATE(), so the demo
-- stays meaningful no matter what day sql/fittrack.sql is
-- imported on — not just on the day it was authored).
-- -------------------------------------------------------

-- Renewal alerts (4.10): two Active members whose plan expires within the
-- next 7 days, so "Renewal Alerts" is never an empty report.
UPDATE member SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY)
WHERE member_code = 'FT-2026-027' AND status = 'Active';
UPDATE member SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
WHERE member_code = 'FT-2026-029' AND status = 'Active';

-- Module 3 renewal demo: member 9 (Half-Yearly) had lapsed, then renewed
-- earlier this month — brings them back to Active and gives "this month's
-- revenue" a non-zero value even when no one has freshly joined this month.
UPDATE member SET status = 'Active',
    expiry_date = DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 5 DAY), INTERVAL 6 MONTH)
WHERE member_code = 'FT-2026-009';
INSERT INTO payment (member_id, plan_id, amount, paid_date, mode, next_due_date)
SELECT m.member_id, m.plan_id, p.fee,
       DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 DAY),
       'Cash',
       DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 5 DAY), INTERVAL 6 MONTH)
FROM member m JOIN plan p ON p.plan_id = m.plan_id
WHERE m.member_code = 'FT-2026-009';
