DELETE
FROM config
WHERE segment = 'mail'
AND field IN (
  'surveyEnd',
  'surveyEndTeacher',
  'dailyDigest',
  'dailyDigestSchedule'
);
