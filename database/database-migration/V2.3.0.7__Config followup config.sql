INSERT INTO config (updatedby,segment,field,value)
VALUES
('Annie','followup','config','
{
  "MULTIPLECHOICE": {
    "other": {
      "message": "Vastaa vain A, B, C tai D. 😇 "
    },
    "branchA": {
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^[aA]\\b",
      "result": "GOTHELP"
    },
    "branchB": {
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^[bB]\\b",
      "result": "INPROGRESS"
    },
    "branchC": {
      "message": "Harmin paikka, pahoittelut! 😕 \nVälitän tiedon eteenpäin.",
      "condition": "^[cC]\\b",
      "result": "NOHELP"
    },
    "branchD": {
      "message": "Kertoisitko vähän lisää tilanteestasi, niin välitän asian eteenpäin. Kiitos! 👍",
      "condition": "^[dD]\\b",
      "result": "OTHER"
    },
    "message": "Hei {{firstname}}! Kerroit aiemmin, että haluat tukea ({{topic.name}}). Koetko, että sait apua asiaan?\n\nA. Sain apua, kiitos! 🙌\nB. Asiani on kesken, mutta etenee 💪\nC. En saanut tarvitsemaani apua 😕\nD. Jotain muuta 🤔\n\nTerveisin Annie-botti 🤖",
    "reminders": [
      {
        "delay": 24,
        "message": "Hei {{firstname}}! En vielä saanut vastaustasi. Koetko, että sait apua asiaan?\n\nA. Sain apua, kiitos! 🙌\nB. Asiani on kesken, mutta etenee 💪\nC. En ole saanut yhteydenottoa 😕\nD. Jotain muuta 🤔"
      }
    ]
  },
  "LIKERT": {
    "other": {
      "message": "Vastaa asteikolla 1-5. 😇 "
    },
    "branch1": {
      "message": "Harmin paikka, pahoittelut! 😕 \nVälitän tiedon eteenpäin.",
      "condition": "^1\\b",
      "result": "1"
    },
    "branch2": {
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^2\\b",
      "result": "2"
    },
    "branch3": {
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^3\\b",
      "result": "3"
    },
    "branch4": {
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^4\\b",
      "result": "4"
    },
    "branch5": {
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^5\\b",
      "result": "5"
    },
    "message": "Hei {{firstname}}! Kerroit aiemmin, että haluat tukea ({{topic.name}}). Kuinka hyödyllistä saamasi tuki oli asteikolla 1-5?\n\nTerveisin Annie-botti 🤖",
    "reminders": [
      {
        "delay": 24,
        "message": "Hei {{firstname}}! En vielä saanut vastaustasi. Kuinka hyödyllistä saamasi tuki oli asteikolla 1-5?"
      }
    ]
  }
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
