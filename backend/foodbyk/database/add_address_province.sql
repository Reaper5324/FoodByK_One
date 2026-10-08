-- Apply once to an existing database before deploying structured address forms.
ALTER TABLE addresses
    ADD COLUMN province VARCHAR(100) DEFAULT NULL AFTER city;
