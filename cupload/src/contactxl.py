#!/usr/bin/python3
# -*- coding: utf-8 -*-
# vim: set fileencoding=UTF-8 :
"""
anniexl

Read data from Excel file.
"""
import sys, getopt
from time import localtime, strftime
import pandas as pd
import json
import re

def show(message):
  print(strftime("%Y-%m-%d %H:%M:%S", localtime())+" "+message)

def readdata(filename, verbose=0):
  df = pd.read_excel(
    filename,
    engine='openpyxl',
    sheet_name='anniecontact',
    dtype={
      'phonenumber': str,
      'customkey': str
    }
  )
  return df

def writedata(df, verbose):
  # convert to our model (see return)
  mydata = []
  # get columns
  columns = json.loads(df.to_json(orient="split"))["columns"]
  # "not null" columns check:
  for index, row in df.iterrows():
    r = json.loads(row.to_json(orient="columns")) # transform df to json obj (via str)
    # also make sure empty lines are skipped
    dataok = True
    if not "phonenumber" in r: dataok = False
    elif not r["phonenumber"]: dataok = False
    else:
      # fix phonenumber
      r["phonenumber"] = r["phonenumber"].replace("(0)","")
      r["phonenumber"] = r["phonenumber"].replace(" ","")
      r["phonenumber"] = r["phonenumber"].replace("-","")
      if not r["phonenumber"]: dataok = False
      else:
        # now there should be only numbers and possibly a leading "+"
        if not re.match("^\+?[0-9]+$", r["phonenumber"]):
          dataok = False
        else:
          # assume national format, convert to international (TODO: only Finnish now)
          if r["phonenumber"].startswith("0"):
            r["phonenumber"] = "+358"+r["phonenumber"][1:]
          # assume "excel" number w/ only leading "+" missing
          if not r["phonenumber"].startswith("+"):
            r["phonenumber"] = "+"+r["phonenumber"]
    if not "firstname" in r: dataok = False
    elif not r["firstname"]: dataok = False
    if not "lastname" in r: dataok = False
    elif not r["lastname"]: dataok = False
    if dataok:
      # replace "'" to "´"
      for k, c in r.items():
        if c and type(c) is str:
          r[k] = c.replace("'", "´")
      # go closer to db structure with "contact" key
      myitem={
        "contact": r
      }
      mydata.append(myitem)
  if verbose>1:
    print(mydata)
  return {
    "meta": {
      "columns": columns,
    },
    "data": mydata
  }

def usage():
  print("""usage: anniexl.py [OPTIONS]

OPTIONS
-h, --help          : this message and exit
-s, --source <file> : filename to read data from. Default anniecontact.xlsx
-v, --verbose       : increase verbosity
-q, --quiet         : reduce verbosity
""")

def main(argv):
  # variables from arguments with possible defaults
  verbose = 1 # default minor messages
  filename = "anniecontact.xlsx"

  try:
    opts, args = getopt.getopt(argv,"hs:vq",["help","source=","verbose","quiet"])
  except getopt.GetoptError as err:
    print(err)
    usage()
    sys.exit(2)
  for arg in args:
    api = arg
  for opt, arg in opts:
    if opt in ("-h", "--help"):
      usage()
      sys.exit(0)
    elif opt in ("-s", "--source"): filename = arg
    elif opt in ("-v", "--verbose"): verbose += 1
    elif opt in ("-q", "--quiet"): verbose -= 1

  if not filename:
    usage()
    exit("Insufficient information given to proceed. Exit.")

  if verbose>0: show("Begin reading file {}".format(filename))

  df = readdata(filename, verbose)
  if verbose>0: show("Read {} rows".format(len(df)))
  
  mydata = writedata(df, verbose)
  print(json.dumps(mydata))

  if verbose>0: show("Done.")

if __name__ == "__main__":
  main(sys.argv[1:])
