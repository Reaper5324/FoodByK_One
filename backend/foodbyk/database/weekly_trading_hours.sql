CREATE TABLE business_trading_hours (
    weekday    TINYINT UNSIGNED PRIMARY KEY,
    is_open    TINYINT(1) NOT NULL DEFAULT 1,
    open_time  TIME NOT NULL DEFAULT '11:00:00',
    close_time TIME NOT NULL DEFAULT '18:00:00',
    CONSTRAINT chk_trading_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_trading_hours_order CHECK (close_time > open_time)
) ENGINE=InnoDB;

INSERT INTO business_trading_hours (weekday, is_open, open_time, close_time)
SELECT days.weekday, days.weekday < 7, settings.trading_hours_start, settings.trading_hours_end
FROM business_settings settings
CROSS JOIN (
    SELECT 1 AS weekday UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) days;
