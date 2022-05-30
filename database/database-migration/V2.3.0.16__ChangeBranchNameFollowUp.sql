INSERT INTO config (updatedby,segment,field,value)
VALUES
('Annie','followup','config','
{
  "LIKERT": {
    "other": {
      "message": "Vastaa asteikolla 1-5. 😇 "
    },
    "branchLIKERT1": {
      "result": "1",
      "message": "Harmin paikka, pahoittelut! 😕 \nVälitän tiedon eteenpäin.",
      "condition": "^1\\b"
    },
    "branchLIKERT2": {
      "result": "2",
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^2\\b"
    },
    "branchLIKERT3": {
      "result": "3",
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^3\\b"
    },
    "branchLIKERT4": {
      "result": "4",
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^4\\b"
    },
    "branchLIKERT5": {
      "result": "5",
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^5\\b"
    },
    "message": "Hei {{firstname}}! Kerroit aiemmin, että haluat tukea ({{supportneedcategory}}). Kuinka hyödyllistä saamasi tuki oli asteikolla 1-5?\n\n1 Ei lainkaan hyödyllistä\n5 Erittäin hyödyllistä\n\nTerveisin Annie-botti",
    "reminders": [
      {
        "delay": 24,
        "message": "Hei {{firstname}}! En vielä saanut vastaustasi. Kuinka hyödyllistä saamasi tuki oli asteikolla 1-5?\n\n1 Ei lainkaan hyödyllistä\n5 Erittäin hyödyllistä"
      }
    ]
  },
  "MULTIPLECHOICE": {
    "other": {
      "message": "Vastaa vain A, B, C tai D. 😇 "
    },
    "branchA": {
      "result": "GOTHELP",
      "message": "Hyvä juttu.\nMukavaa lukuvuoden jatkoa! 😊",
      "condition": "^[aA]\\b"
    },
    "branchB": {
      "result": "INPROGRESS",
      "message": "Kiitos tiedosta. Toivottavasti asiasi järjestyy parhain päin. Mukavaa lukuvuoden jatkoa! 😊",
      "condition": "^[bB]\\b"
    },
    "branchC": {
      "result": "NOHELP",
      "message": "Harmin paikka, pahoittelut! 😕 \nVälitän tiedon eteenpäin.",
      "condition": "^[cC]\\b"
    },
    "branchD": {
      "result": "OTHER",
      "message": "Kertoisitko vähän lisää tilanteestasi, niin välitän asian eteenpäin. Kiitos! 👍",
      "condition": "^[dD]\\b"
    },
    "message": "Hei {{firstname}}! Kerroit aiemmin, että haluat tukea ({{supportneedcategory}}). Koetko, että sait apua asiaan?\n\nA. Sain apua, kiitos! 🙌\nB. Asiani on kesken, mutta etenee 💪\nC. En saanut tarvitsemaani apua 😕\nD. Jotain muuta 🤔\n\nTerveisin Annie-botti 🤖",
    "reminders": [
      {
        "delay": 24,
        "message": "Hei {{firstname}}! En vielä saanut vastaustasi. Koetko, että sait apua asiaan?\n\nA. Sain apua, kiitos! 🙌\nB. Asiani on kesken, mutta etenee 💪\nC. En ole saanut yhteydenottoa 😕\nD. Jotain muuta 🤔"
      }
    ]
  }
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
