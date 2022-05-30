UPDATE supportneed SET status = 'NEW' WHERE status = '1';
UPDATE supportneed SET status = 'OPENED' WHERE status = '2';
UPDATE supportneed SET status = 'ACKED' WHERE status = '100';

DELETE FROM codes WHERE codeset = 'supportNeedStatus';
