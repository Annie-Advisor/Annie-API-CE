insert into config (updatedby,segment,field,value)
values
('Annie','mail','surveyEnd','
{"footer":{"en":"<p>You can find the support needs reported by students here:<br>https://{{hostname}}.annieadvisor.com</p><p>In case you have any questions, please reply :)</p><p>Kind regards,<br>Annie</p></body></html>","es":"<p>Puedes encontrar las necesidades de apoyo que tus estudiantes han marcardo aquí:<br>https://{{hostname}}.annieadvisor.com</p><p>Si tienes alguna pregunta o duda, contesta a este mensaje :)</p><p>Saludos,<br>Annie</p></body></html>","fi":"<p>Voit tarkastella opiskelijoiden ilmoittamia tuen tarpeita täällä:<br>https://{{hostname}}.annieadvisor.com</p><p>Jos sinulla on kysyttävää, voit vastata tähän viestiin :)</p><p>terveisin,<br>Annie</p></body></html>","sv":"<p>Du kan se listan på studerandenas stödbehov här:<br>https://{{hostname}}.annieadvisor.com</p><p>Du kan svara på detta meddelande om du har frågor :)</p><p>Mvh,<br>Annie</p></body></html>"},"header":{"en":"<html><body><p>Hi!</p><p>{{surveyname}} has ended. Here is the current status of support needs reported by students:</p>","es":"<html><body><p>¡Hola!</p><p>{{surveyname}} ha finalizado. Aquí puedes encontrar el estado actual de las necesidades de apoyo marcadas por los estudiantes:</p>","fi":"<html><body><p>Moikka!</p><p>{{surveyname}} on nyt päättynyt. Tuen tarpeiden tilanne tällä hetkellä:</p>","sv":"<html><body><p>Hej!</p><p>{{surveyname}} har nu tagit slut. Läget med stödbehoven är nu:</p>"},"subject":{"en":"{{surveyname}} has ended, status of student support needs","es":"{{surveyname}} ha finalizado, estado de las necesidades de apoyo de los estudiantes","fi":"{{surveyname}} on päättynyt, tuen tarpeiden tilanne","sv":"{{surveyname}} har slutat, situationen nu"}}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
