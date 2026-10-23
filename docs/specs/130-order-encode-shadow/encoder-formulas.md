# ENCODER sheet formulas (macro sheet "MACRO FOR LDT (JUNE 2026) 2.0", read 2026-10-23)

Row 1 headers B..O: DATE, PAGE, NAME, PHONE NUMBER, ALL USER INPUT, SHOP DETAILS, CXD, PRICE, Name, Phone Number, Address, Province, City, Barangay.
Row 2 is the working row (A2 = source row number; B:I copied from the source tab B:I).

- P1: the query text the script writes (e.g. "cebu cebu").
- Q1 (spills down):
  =IF(P1="", "", FILTER(LocationData!A2:A50011,
     ISNUMBER(SEARCH(LOWER(INDEX(SPLIT(P1," "),1)), LOWER(LocationData!A2:A50011))) *
     IF(COUNTA(SPLIT(P1," "))>=2, ISNUMBER(SEARCH(LOWER(INDEX(SPLIT(P1," "),2)), LOWER(LocationData!A2:A50011))), TRUE) *
     ... same for words 3, 4, 5, 6 ...))
  i.e. every word of P1 (split on spaces, max 6 words) must be a case-insensitive substring of LocationData!A; result in sheet order.
- R1 = VLOOKUP($Q1, LocationData!$A:$D, 2, FALSE)  (province of the first candidate)
- S1 = VLOOKUP($Q1, LocationData!$A:$D, 3, FALSE)  (city)
- T1 = VLOOKUP($Q1, LocationData!$A:$D, 4, FALSE)  (barangay)
- U1 = COUNTA(Q:Q)  (number of candidates)
- V1 = status text the script writes ("GETTING DATA" etc.)

LocationData (macro sheet): A = B&" "&C&" "&D, B = Province (e.g. ABRA), C = City (e.g. ABRA-DOLORES), D = Barangay (e.g. BAYAAN); rows 2..~43,000, uppercase, hyphenated.
The AFTER MACRO sheet has its own LocationData (B province, C city, D barangay) read by the checker script.

Observed example (row 23823): P1 "cebu cebu" -> 1,229 candidates; first = AGUSAN-DEL-SUR / AGUSAN-DEL-SUR-ESPERANZA / CEBULAN (substring "cebu" in "cebulan"); W2 "Barangay", X2 "❌". This is the sheet's real behaviour and must be reproduced exactly in the shadow run.
