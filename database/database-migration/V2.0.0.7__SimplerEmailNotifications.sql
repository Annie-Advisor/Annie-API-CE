insert into config (updatedby,segment,field,value)
values
('Annie','mail','supportneedImmediate','
{
  "footer": {
    "en": "<p>Why did I get this message?</p><p>Your institution is developing student support services in cooperation with Annie Advisor. The goal is to provide support for those in need as easily as possible.<br/><br/> The above-mentioned student has request support with a SMS. According to our information they need your support. If you feel that the matter is not your concern, please answer this email.</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>",
    "fi": "<p>Miksi sain tämän viestin?</p><p>Oppilaitoksesi kehittää opiskelijan tukipalveluja yhteistyössä Annie Advisorin kanssa. Tavoitteena on tehdä tuen saamisesta opiskelijoille mahdollisimman helppoa.<br/><br/> Yllä mainittu opiskelija on pyytänyt tekstiviestillä tukea ja tietojemme mukaan hän tarvitsee sinun tukeasi. Jos koet, että tämä asia ei kuulu sinulle, voit vastata tähän sähköpostiin.</p></body></html>",
    "sv": "<p>Se listan på stureanden som behöver stöd här:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>"
  },
  "header": {
    "en": "<html><body><p>Hello! {{firstname}} {{lastname}} let us know a moment ago that they need support. Proceed to view the support request:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} acaba de informar que necesita apoyo.</p>",
    "fi": "<html><body><p>Hei! {{firstname}} {{lastname}} ilmoitti hetki sitten tarvitsevansa tukea. Siirry tarkastelemaan tukipyyntöä:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} bad om hjälp.</p>"
  },
  "subject": {
    "en": "{{firstname}} {{lastname}} needs your support ({{supportneedid}})",
    "es": "{{firstname}} {{lastname}} necesita apoyo ({{supportneedid}})",
    "fi": "{{firstname}} {{lastname}} tarvitsee tukeasi ({{supportneedid}})",
    "sv": "{{firstname}} {{lastname}} behöver stöd ({{supportneedid}})"
  }
}

')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();

insert into config (updatedby,segment,field,value)
values
('Annie','mail','messageToSupportneedImmediate','
{
  "footer": {
    "en": "<p>Why did I get this message?</p><p>Your institution is developing student support services in cooperation with Annie Advisor. The goal is to provide support for those in need as easily as possible.<br/><br/> The above-mentioned student has request support with a SMS. According to our information they need your support. If you feel that the matter is not your concern, please answer this email.</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>",
    "fi": "<p>Miksi sain tämän viestin?</p><p>Oppilaitoksesi kehittää opiskelijan tukipalveluja yhteistyössä Annie Advisorin kanssa. Tavoitteena on tehdä tuen saamisesta opiskelijoille mahdollisimman helppoa.<br/><br/> Yllä mainittu opiskelija on pyytänyt tekstiviestillä tukea ja tietojemme mukaan hän tarvitsee sinun tukeasi. Jos koet, että tämä asia ei kuulu sinulle, voit vastata tähän sähköpostiin.</p></body></html>",
    "sv": "<p>Se listan på stureanden som behöver stöd här:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>"
  },
  "header": {
    "en": "<html><body><p>Hello!{{firstname}} {{lastname}} sent a new message regarding their support need. Proceed to view the support request:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} acaba de informar que necesita apoyo.</p>",
    "fi": "<html><body><p>Hei!{{firstname}} {{lastname}} lähetti uuden viestin koskien tukipyyntöään. Siirry katsomaan viestiä:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} bad om hjälp.</p>"
  },
  "subject": {
    "en": "A new message from {{firstname}} {{lastname}}",
    "es": "Un nuevo mensaje de {{firstname}} {{lastname}}",
    "fi": "Uusi viesti opiskelijalta {{firstname}} {{lastname}}",
    "sv": "Nytt meddelande från {{firstname}} {{lastname}}"
  }
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();

insert into config (updatedby,segment,field,value)
values
('Annie','mail','firstReminder','
{
  "footer": {
    "en": "<p>Why did I get this message?</p><p>Your institution is developing student support services in cooperation with Annie Advisor. The goal is to provide support for those in need as easily as possible.<br/><br/> The above-mentioned student has request support with a SMS. According to our information they need your support. If you feel that the matter is not your concern, please answer this email.</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>",
    "fi": "<p>Miksi sain tämän viestin?</p><p>Oppilaitoksesi kehittää opiskelijan tukipalveluja yhteistyössä Annie Advisorin kanssa. Tavoitteena on tehdä tuen saamisesta opiskelijoille mahdollisimman helppoa.<br/><br/> Yllä mainittu opiskelija on pyytänyt tekstiviestillä tukea ja tietojemme mukaan hän tarvitsee sinun tukeasi. Jos koet, että tämä asia ei kuulu sinulle, voit vastata tähän sähköpostiin.</p></body></html>",
    "sv": "<p>Se listan på stureanden som behöver stöd här:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>"
  },
  "header": {
    "en": "<html><body><p>Hello! {{firstname}} {{lastname}} needs your support. Proceed to view the support request:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} acaba de informar que necesita apoyo.</p>",
    "fi": "<html><body><p>Hei! {{firstname}} {{lastname}} on ilmoittanut tarvitsevansa tukea. Siirry tarkastelemaan tukipyyntöä:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} bad om hjälp.</p>"
  },
  "subject": {
    "en": "Reminder: {{firstname}} {{lastname}} needs your support ({{supportneedid}})",
    "es": "Reminder: {{firstname}} {{lastname}} necesita apoyo ({{supportneedid}})",
    "fi": "Muistutus: {{firstname}} {{lastname}} tarvitsee tukeasi ({{supportneedid}})",
    "sv": "Påminnelse: {{firstname}} {{lastname}} behöver stöd ({{supportneedid}})"
  }
}
'),
('Annie','mail','secondReminder','
{
  "footer": {
    "en": "<p>Why did I get this message?</p><p>Your institution is developing student support services in cooperation with Annie Advisor. The goal is to provide support for those in need as easily as possible.<br/><br/> The above-mentioned student has request support with a SMS. According to our information they need your support. If you feel that the matter is not your concern, please answer this email.</p></body></html>",
    "es": "<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>",
    "fi": "<p>Miksi sain tämän viestin?</p><p>Oppilaitoksesi kehittää opiskelijan tukipalveluja yhteistyössä Annie Advisorin kanssa. Tavoitteena on tehdä tuen saamisesta opiskelijoille mahdollisimman helppoa.<br/><br/> Yllä mainittu opiskelija on pyytänyt tekstiviestillä tukea ja tietojemme mukaan hän tarvitsee sinun tukeasi. Jos koet, että tämä asia ei kuulu sinulle, voit vastata tähän sähköpostiin.</p></body></html>",
    "sv": "<p>Se listan på stureanden som behöver stöd här:<br>https://{{hostname}}.annieadvisor.com/beta</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>"
  },
  "header": {
    "en": "<html><body><p>Hello! {{firstname}} {{lastname}} needs your support. Proceed to view the support request:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "es": "<html><body><p>¡Hola!</p><p>{{firstname}} {{lastname}} acaba de informar que necesita apoyo.</p>",
    "fi": "<html><body><p>Hei! {{firstname}} {{lastname}} on ilmoittanut tarvitsevansa tukea. Siirry tarkastelemaan tukipyyntöä:</p><p><a href=\"https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}\">https://{{hostname}}.annieadvisor.com/beta/request/{{supportneedid}}</a></p>",
    "sv": "<html><body><p>Hej!</p><p>{{firstname}} {{lastname}} bad om hjälp.</p>"
  },
  "subject": {
    "en": "Reminder: {{firstname}} {{lastname}} needs your support ({{supportneedid}})",
    "es": "Reminder: {{firstname}} {{lastname}} necesita apoyo ({{supportneedid}})",
    "fi": "Muistutus: {{firstname}} {{lastname}} tarvitsee tukeasi ({{supportneedid}})",
    "sv": "Påminnelse: {{firstname}} {{lastname}} behöver stöd ({{supportneedid}})"
  }
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
