ALTER TABLE config
    ALTER COLUMN value TYPE jsonb USING value::jsonb
;
