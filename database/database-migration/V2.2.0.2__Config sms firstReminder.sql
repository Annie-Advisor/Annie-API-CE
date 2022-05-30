insert into config (updatedby,segment,field,value)
values
('Annie','sms','firstReminder','
{
    "en": "Reminder: {{firstname}} {{lastname}} needs support.\n\nView the support request:\n[https://{{hostname}}.annieadvisor.com/app/request/{{supportneedid}}]",
    "es": "Recordatorio: {{firstname}} {{lastname}} acaba de informar que necesita apoyo.\n\nPuedes encontrar la necesidad de apoyo aquí:\n[https://{{hostname}}.annieadvisor.com/app/request/{{supportneedid}}]",
    "fi": "Muistutus: {{firstname}} {{lastname}} ilmoitti tarvitsevansa tukeasi.\n\nNäytä tukipyyntö:\n[https://{{hostname}}.annieadvisor.com/app/request/{{supportneedid}}]",
    "sv": "Påminnelse: {{firstname}} {{lastname}} bad om hjälp.\n\nVisa stödförfrågan:\n[https://{{hostname}}.annieadvisor.com/app/request/{{supportneedid}}]"
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
