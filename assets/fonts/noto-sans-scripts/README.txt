Noto Sans for the scripts FreeITSM's PDFs need beyond Latin, Greek and Cyrillic
(which are in ../noto-sans). Regular and Bold of each, from Google's Noto project:
https://github.com/notofonts/notofonts.github.io (fonts/<Family>/hinted/ttf),
downloaded 2026-11-07.

  NotoSansDevanagari   Hindi, Marathi        NotoSansGurmukhi   Punjabi
  NotoSansBengali      Bengali               NotoSansKannada    Kannada
  NotoSansGujarati     Gujarati              NotoSansMalayalam  Malayalam
  NotoSansTamil        Tamil                 NotoSansTelugu     Telugu
  NotoSansArabic       Arabic, Urdu, Persian NotoSansHebrew     Hebrew
  NotoSansThai         Thai

Each is "Copyright 2022 The Noto Project Authors" and licensed under the SIL Open
Font License 1.1 - OFL.txt in this folder (the same licence text for every family).
They are loaded only when a PDF contains that script - assets/js/pdf-writer.js.
