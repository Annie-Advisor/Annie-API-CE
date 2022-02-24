insert into config (updatedby,segment,field,value)
values
('Annie','mail','messageToSupportneedImmediate','
{
  "subject": {
    "en": "A new message from {{firstname}} {{lastname}}",
    "fi": "Uusi viesti opiskelijalta {{firstname}} {{lastname}}",
    "sv": "Nytt meddelande från {{firstname}} {{lastname}}",
    "es": "Un nuevo mensaje de {{firstname}} {{lastname}}"
  },
  "header": {
    "en": "<html><body><p>Hi!</p><p>{{firstname}} {{lastname}} sent a new message regarding their support need.</p>",
    "fi": "<html><body><p>Moikka!</p><p>{{firstname}} {{lastname}} lähetti juuri uuden viestin tuen tarpeeseensa liittyen.</p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} skickade ett nyt meddelande angående sitt stödbehov.</p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} ha enviado un nuevo mensaje sobre sus necesidades de apoyo.</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{ hostname }}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{ hostname }}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>",
    "sv": "<p>Du kan se listan på stödbehov här:<br>https://{{ hostname }}.annieadvisor.com</p><p>Svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
'),

('Annie','mail','dailyDigest','
{
  "subject": {
    "en": "Your daily digest",
    "fi": "Kooste päivän tapahtumista",
    "sv": "Sammanfattning av dagens händelser",
    "es": "Tu resumen diario"
  },
  "header": {
    "en": "<html><body><p>Hi!</p><p>During the last day there were {{supportneedCount}} new or updated support needs and messages from {{newMessageCount}} students considering support categories assigned to you.</p>",
    "fi": "<html><body><p>Moikka!</p><p>Viimeisen vuorokauden aikana havaittiin {{supportneedCount}} uutta tai muuttunutta tuen tarvetta ja uusia viestejä {{newMessageCount}} opiskelijalta sinulle kohdistettuihin tuen kategorioihin liittyen.</p>",
    "sv": "<html><body><p>Hej!</p><p>Under senaste dygnet upptäcktes {{supportneedCount}} nya eller ändrade stödbehov och {{newMessageCount}} nya meddelanden i såna stödbehov som har riktas till dej.</p>",
    "es": "<html><body><p>¡Hola!</p><p>Han habido {{supportneedCount}} actualizaciones en las necesidades de apoyo y {{newMessageCount}} mensajes de alumnos sobre temas asignados a ti.</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>",
    "sv": "<p>Du kan se studerandenas stödbehov här:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
'),

('Annie','mail','initiate','
{
  "subject": {
    "en": "Messages sent to {{contactCount}} students ({{surveyname}})",
    "fi": "Viestit lähetetty {{contactCount}} opiskelijalle ({{surveyname}})",
    "sv": "Meddelanden skickades åt {{contactCount}} studeranden ({{surveyname}})",
    "es": "Mensaje enviado a {{contactCount}} estudiantes ({{surveyname}})"
  },
  "header": {
    "en": "<html><body><p>Hi!</p><p>I have now sent the messages regarding {{surveyname}} to a total of {{contactCount}} students.</p>",
    "fi": "<html><body><p>Moikka!</p><p>Olen nyt lähettänyt opiskelijoille tekstiviestit ({{surveyname}}). Viestejä lähti yhteensä {{contactCount}} opiskelijalle.</p>",
    "sv": "<html><body><p>Hej!</p><p>Jag har nu skickat meddelandena åt era studeranden ({{surveyname}}). Allt som allt {{contactCount}} studeranden fick meddelanden.</p>",
    "es": "<html><body><p>¡Hola!</p><p>Acabo de enviar los mensaje sobre {{surveyname}} a un total de {{contactCount}} estudiantes.</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>",
    "sv": "<p>Du kan se studerandenas stödbehov här efter att de svarat:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
'),

('Annie','mail','surveyEnd','
{
  "subject": {
    "en": "{{surveyname}} ended, status of student support needs",
    "fi": "{{surveyname}} päättynyt, tuen tarpeiden tilanne",
    "sv": "{{surveyname}} har slutat, situationen nu",
    "es": "{{surveyname}} ha finalizado, estado de las necesidades de apoyo de los estudiantes"
  },
  "header": {
    "en": "<html><body><p>Hi!</p><p>{{surveyname}} has ended. Here is the current status of support needs reported by students:</p>",
    "fi": "<html><body><p>Moikka!</p><p>{{surveyname}} on nyt päättynyt. Tuen tarpeiden tilanne tällä hetkellä:</p>",
    "sv": "<html><body><p>Hej!</p><p>{{surveyname}} har nu tagit slut. Läget med stödbehoven är nu:</p>",
    "es": "<html><body><p>¡Hola!</p><p>{{surveyname}} ha finalizado. Aquí puedes encontrar el estado actual de las necesidades de apoyo marcadas por los estudiantes:</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>",
    "sv": "<p>Du kan se listan på studerandenas stödbehov här:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
'),

('Annie','mail','surveyEndTeacher','
{
"subject": {
    "en": "{{surveyname}} ended, status of student support needs in your group",
    "fi": "{{surveyname}} päättynyt, ryhmäsi tuen tarpeiden tilanne",
    "sv": "{{surveyname}} har slutat, din grupps situation",
    "es": "{{surveyname}} ha finalizado, estado de las necesidades de apoyo de los estudiantes en su grupo"
  },
  "header": {
    "en": "<html><body><p>Hi {{teachername}}!</p><p>{{surveyname}} has ended. Here is the current status of support needs reported by students in your group:</p>",
    "fi": "<html><body><p>Moikka {{teachername}}!</p><p>{{surveyname}} on nyt päättynyt. Sinun ryhmäsi tuen tarpeiden tilanne tällä hetkellä:</p>",
    "sv": "<html><body><p>Hej!</p><p>{{surveyname}} har nu tagit slut. Läget med stödbehoven i din grupp är nu:</p>",
    "es": "<html><body><p>Hi!</p><p>{{surveyname}} ha finalizado. Aquí puedes encontrar el estado actual de las necesidades de apoyo marcadas por los estudiantes en su grupo:</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>"
    "sv": "<p>Du kan se listan på studerandenas stödbehov här:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
'),

('Annie','mail','supportneedImmediate','
{
  "subject": {
    "en": "{{firstname}} {{lastname}} needs support",
    "fi": "{{firstname}} {{lastname}} tarvitsee tukea",
    "sv": "{{firstname}} {{lastname}} behöver stöd",
    "es": "{{firstname}} {{lastname}} necesita apoyo"
  },
  "header": {
    "en": "<html><body><p>Hi!</p><p>{{firstname}} {{lastname}} just reported a need for support.</p>",
    "fi": "<html><body><p>Moikka!</p><p>{{firstname}} {{lastname}} ilmoitti juuri tarvitsevansa tukea.</p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} bad om hjälp.</p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} acaba de informar que necesita apoyo.</p>"
  },
  "footer": {
    "en": "<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>",
    "fi": "<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>",
    "sv": "<p>Se listan på stureanden som behöver stöd här:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>"
  }
}
')

ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
