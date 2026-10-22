-- Creates the initial customer, staff, and admin accounts from railwaydb.sql.
-- Run against the new database after migration.sql has created and seeded roles.
-- The password_hash values are the original PHP Argon2id hashes; no plaintext
-- passwords are stored here. The accounts can keep using their existing passwords.
--
-- This is a one-time insert. If an email already exists, MySQL rejects the
-- statement rather than changing or duplicating that account.

INSERT INTO users (name, email, password_hash, role_id, phone, loyalty_points, is_active)
SELECT 'Shaun Maswikaneng',
       'maswikanengshaun@gmail.com',
       '$argon2id$v=19$m=65536,t=4,p=1$LjdVZm8zcVguOWxSaW5HRg$xqbcb5049hCVXeNjpuwBqbyM6PC7EpNAErKeqaWU1g8',
       id,
       '+27699307496',
       12.48,
       1
FROM roles
WHERE role_name = 'customer'
UNION ALL
SELECT 'Food by K Staff',
       'staff@gmail.com',
       '$argon2id$v=19$m=65536,t=4,p=1$VVNNZWlTQnNCQzBuQkh6Qg$piTnBMq8OlJpcc638NEQ1rDCex38BCxLjG3u2la+wE4',
       id,
       NULL,
       0.00,
       1
FROM roles
WHERE role_name = 'staff'
UNION ALL
SELECT 'Food by K Admin',
       'admin@local.com',
       '$argon2id$v=19$m=65536,t=4,p=1$UGovUnhHczNheXRvV1BERg$bdoW3eujGbXn0fkOm7owP+zfYTAsVgmh/rzvPH00Bt8',
       id,
       NULL,
       0.00,
       1
FROM roles
WHERE role_name = 'admin';

SELECT r.role_name, u.id, u.name, u.email, u.phone, u.loyalty_points
FROM users AS u
JOIN roles AS r ON r.id = u.role_id
WHERE u.email IN (
    'maswikanengshaun@gmail.com',
    'staff@gmail.com',
    'admin@local.com'
)
ORDER BY FIELD(r.role_name, 'customer', 'staff', 'admin');
