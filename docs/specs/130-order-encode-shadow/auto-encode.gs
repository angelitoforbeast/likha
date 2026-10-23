/***********************
 * ✅ COMPILED: runAllSteps + required functions
 * Sheets used:
 * - ENCODER
 * - LINKS
 *
 * LINKS cells used:
 * - E2 : taskCount (early exit / stop condition)
 * - B2/C2 : source spreadsheet + source sheet name
 * - B3/C3 : destination spreadsheet + destination sheet name
 * - D2 : check column letter (ex: "K")
 * - B6 : OpenAI API key (for GPT calls)
 * - B10/C10 : target spreadsheet URL + sheet name for final transfer
 ************************/

function runAllSteps() {
  const lock = LockService.getScriptLock();

  // Try to acquire lock for up to 30 seconds
  try {
    lock.waitLock(30000);
  } catch (e) {
    Logger.log("❌ Another instance is still running.");
    return;
  }

  try {
    const startTime = new Date().getTime();
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName("ENCODER");
    const linksSheet = ss.getSheetByName("LINKS");

    const maxDuration = 5 * 60 * 1000; // ✅ 5 minutes in ms

    // ✅ Early exit: check LINKS!E2
    SpreadsheetApp.flush();
    let taskCount = linksSheet.getRange("E2").getValue();
    if (!taskCount || taskCount < 1) {
      Logger.log("✅ LINKS!E2 is less than 1. Nothing to process. Exiting.");
      return;
    }

    for (let i = 0; i < 1000; i++) {
      const stopSignal = PropertiesService.getScriptProperties().getProperty("LOOP_STOP");
      if (stopSignal === "TRUE") {
        Logger.log("🛑 Loop stopped by user.");
        PropertiesService.getScriptProperties().deleteProperty("LOOP_STOP");
        break;
      }

      // 🔍 Check LINKS!E2 inside loop
      SpreadsheetApp.flush();
      Utilities.sleep(1000);

      taskCount = linksSheet.getRange("E2").getValue();
      Logger.log("🔍 Current value of LINKS!E2: " + taskCount);

      if (!taskCount || taskCount < 1) {
        Logger.log("✅ LINKS!E2 became less than 1. Stopping loop early.");
        break;
      }

      const currentTime = new Date().getTime();
      const elapsed = currentTime - startTime;

      if (elapsed >= maxDuration) {
        Logger.log("⏰ Stopped: 5 minutes elapsed.");
        break;
      }

      Logger.log(`🔁 Loop ${i + 1} - Elapsed Time: ${elapsed} ms`);

      sheet.getRange("V1").setValue("GETTING DATA");
      SpreadsheetApp.flush();

      const result = transferOneRowColumnsBtoIWithRowTag();
      if (!result) {
        Logger.log("⚠️ No result from transfer. Skipping this loop.");
        continue;
      }

      Utilities.sleep(100);

      sheet.getRange("V1").setValue("EXTRACTING ADDRESS");
      SpreadsheetApp.flush();
      extractAddressPartsRow2();
      Utilities.sleep(100);

      sheet.getRange("V1").setValue("EXTRACTING ADDRESS LINE 1");
      SpreadsheetApp.flush();
      extractAddressLine1WithLandmark();
      Utilities.sleep(100);

      sheet.getRange("V1").setValue("ANALYZING ITEM/PRICE + AUTOFILL");
      SpreadsheetApp.flush();
      extractDetails();
      checkMissingFieldsAndAutoFill();
      Utilities.sleep(100);

      sheet.getRange("V1").setValue("PROCESSING DATA");
      SpreadsheetApp.flush();
      Macro();
      storeAndTransferEncoderValues();
      Utilities.sleep(100);

      sheet.getRange("V1").setValue("MARKING AS DONE");
      SpreadsheetApp.flush();
      markSourceRowAsDone(result.sourceSheet, result.rowToCopy, result.checkCol);
    }
  } finally {
    lock.releaseLock();
  }
}

/* -----------------------------
   STEP 1: Transfer 1 qualified row
-------------------------------- */

function transferOneRowColumnsBtoIWithRowTag() {
  const activeSS = SpreadsheetApp.getActiveSpreadsheet();
  const linkSheet = activeSS.getSheetByName("LINKS");

  const VB2 = linkSheet.getRange("B2").getValue().toString().trim();
  const VC2 = linkSheet.getRange("C2").getValue().toString().trim();
  const VB3 = linkSheet.getRange("B3").getValue().toString().trim();
  const VC3 = linkSheet.getRange("C3").getValue().toString().trim();
  const VD2 = linkSheet.getRange("D2").getValue().toString().trim();
  const checkCol = columnLetterToNumber(VD2);

  const sourceId = VB2.includes("https://") ? VB2.match(/[-\w]{25,}/)[0] : VB2;
  const destId   = VB3.includes("https://") ? VB3.match(/[-\w]{25,}/)[0] : VB3;

  const sourceSheet = SpreadsheetApp.openById(sourceId).getSheetByName(VC2);
  const destSheet   = SpreadsheetApp.openById(destId).getSheetByName(VC3);

  const lastRow = sourceSheet.getLastRow();

  // ✅ reference row from ALL ORDERS!K1
  let referenceRow = parseInt(sourceSheet.getRange("K1").getValue(), 10);
  if (isNaN(referenceRow)) {
    Logger.log("ℹ️ ALL ORDERS!K1 is empty or invalid, defaulting to row 2");
    referenceRow = 2;
  }

  let rowToCopy;
  const tryRow = referenceRow + 1;

  if (tryRow <= lastRow && isQualifiedRow(sourceSheet, tryRow, checkCol)) {
    rowToCopy = tryRow;
  } else {
    Logger.log(`🔁 Row ${tryRow} not qualified. Searching from row 2...`);
    rowToCopy = findQualifiedRow(sourceSheet, 2, lastRow, checkCol);
  }

  if (!rowToCopy) {
    Logger.log("❌ No valid row found.");
    return null;
  }

  // Copy B:I (8 cols) then prepend row number in A
  const data = sourceSheet.getRange(rowToCopy, 2, 1, 8).getValues();
  const outputRow = [[rowToCopy, ...data[0]]]; // A:I (9 cols)

  // Replace row 2 in destination
  if (destSheet.getLastRow() >= 2) {
    destSheet.deleteRow(2);
    Utilities.sleep(200);
    destSheet.insertRowBefore(2);
  }
  destSheet.getRange(2, 1, 1, 9).setValues(outputRow);

  // Wait until data appears (max 5s)
  let waitTime = 0;
  while (waitTime < 5000) {
    const rowCheck = destSheet.getRange("A2:I2").getValues()[0].join("").trim();
    if (rowCheck !== "") break;
    Utilities.sleep(200);
    waitTime += 500;
  }

  return {
    sourceSheet,
    rowToCopy,
    checkCol
  };
}

function isQualifiedRow(sheet, row, checkCol) {
  if (row <= 1) return false;
  const colA = sheet.getRange(row, 1).getValue().toString().trim();
  const currentCheck = sheet.getRange(row, checkCol).getValue().toString().trim();
  const previousCheck = sheet.getRange(row - 1, checkCol).getValue().toString().trim();
  return colA !== "" && currentCheck === "" && previousCheck !== "";
}

function findQualifiedRow(sheet, startRow, endRow, checkCol) {
  for (let row = startRow; row <= endRow; row++) {
    if (isQualifiedRow(sheet, row, checkCol)) return row;
  }
  return null;
}

function columnLetterToNumber(letter) {
  let column = 0;
  for (let i = 0; i < letter.length; i++) {
    column *= 26;
    column += letter.charCodeAt(i) - 64;
  }
  return column;
}

function markSourceRowAsDone(sourceSheet, rowNumber, checkCol) {
  if (!sourceSheet || !rowNumber || !checkCol) {
    Logger.log("⚠️ Cannot mark row: Missing required parameters.");
    return;
  }
  sourceSheet.getRange(rowNumber, checkCol).setValue("✅");
  Logger.log(`✅ Marked source row ${rowNumber}, column ${checkCol} as done.`);
}

/* -----------------------------
   STEP 2: Extract address parts from ENCODER!H2
-------------------------------- */

function extractAddressPartsRow2() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("ENCODER");
  const value = sheet.getRange("H2").getValue();
  const lines = value.toString().split("\n").map(line => line.trim());

  let name = "", phone = "", province = "", city = "", barangay = "";

  lines.forEach(line => {
    if (/^-?\s*Name:/i.test(line)) {
      name = line.replace(/^-?\s*Name:\s*/i, "").trim();
    } else if (/^-?\s*Phone Number:/i.test(line)) {
      phone = line.replace(/^-?\s*Phone Number:\s*/i, "").trim().replace(/\s+/g, "");
      phone = phone.replace(/^(\+63|63)/, "0").replace(/[^0-9]/g, "");
      if (phone.length !== 11 || !/^09\d{9}$/.test(phone)) phone = "";
    } else if (/^-?\s*Province:/i.test(line)) {
      let raw = line.replace(/^-?\s*Province:\s*/i, "").trim();
      if (!/^(NA|N\/A|NOT PROVIDED)$/i.test(raw)) {
        province = raw.replace(/\b(province|brgy|barangay|city)[\.\s]*/gi, "")
                      .replace(/[^A-Za-z0-9 ]/g, "")
                      .trim()
                      .toUpperCase();
      }
    } else if (/^-?\s*City:/i.test(line)) {
      let raw = line.replace(/^-?\s*City:\s*/i, "").trim();
      if (!/^(NA|N\/A|NOT PROVIDED)$/i.test(raw)) {
        city = raw.replace(/\b(province|brgy|barangay|city)[\.\s]*/gi, "")
                  .replace(/[^A-Za-z0-9 ]/g, "")
                  .trim()
                  .toUpperCase();
      }
    } else if (/^-?\s*(Brgy|Barangay):/i.test(line)) {
      let raw = line.replace(/^-?\s*(Brgy|Barangay):\s*/i, "").trim();
      if (!/^(NA|N\/A|NOT PROVIDED)$/i.test(raw)) {
        barangay = raw.replace(/\b(province|brgy|barangay|city)[\.\s]*/gi, "")
                      .replace(/[^A-Za-z0-9 ]/g, "")
                      .trim()
                      .toUpperCase();
      }
    }
  });

  sheet.getRange("J2").setValue(name);

  const phoneCell = sheet.getRange("K2");
  phoneCell.setNumberFormat("@");
  phoneCell.setValue(phone);

  sheet.getRange("M2").setValue(province);
  sheet.getRange("N2").setValue(city);
  sheet.getRange("O2").setValue(barangay);
}

/* -----------------------------
   STEP 3: Extract Address Line 1 (with landmark) via GPT
-------------------------------- */

function extractAddressLine1WithLandmark() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const encoderSheet = ss.getSheetByName("ENCODER");
  const linksSheet = ss.getSheetByName("LINKS");

  const rawText = encoderSheet.getRange("H2").getValue().toString().trim();
  const apiKey = linksSheet.getRange("B6").getValue().toString().trim();

  if (!rawText || !apiKey) {
    Logger.log("Missing H2 content or API key.");
    return;
  }

  const prompt = `Extract Address Line 1 including landmark. Remove name, phone number, barangay, city, province, zip code, and instructions. Do not explain. Return only the address line.\n\nInput:\n${rawText}`;

  const payload = {
    model: "gpt-3.5-turbo",
    messages: [
      { role: "system", content: "You are a helpful assistant that extracts Address Line 1 including the landmark only." },
      { role: "user", content: prompt }
    ],
    temperature: 0.2
  };

  const options = {
    method: "post",
    contentType: "application/json",
    headers: { Authorization: `Bearer ${apiKey}` },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  };

  const response = UrlFetchApp.fetch("https://api.openai.com/v1/chat/completions", options);
  const json = JSON.parse(response.getContentText());
  const result = json.choices?.[0]?.message?.content?.trim() || "";

  encoderSheet.getRange("L2").setValue(result);
}

/* -----------------------------
   STEP 4: Extract Item + Price from ENCODER!G2
-------------------------------- */

function extractDetails() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("ENCODER");
  const g2Value = sheet.getRange("G2").getValue().toString();

  const itemMatch = g2Value.match(/ITEM:\s*(.+?)(?:\r?\n|$)/i);
  const item = itemMatch ? itemMatch[1].trim() : "";

  const qtyMatch = g2Value.match(/QUANTITY:\s*(\d+)/i);
  const quantity = qtyMatch ? qtyMatch[1].trim() : "";

  const output = quantity && item ? `${quantity} x ${item}` : "";
  sheet.getRange("U2").setValue(output);

  let price = "";
  const pricePesoMatch = g2Value.match(/PRICE:\s*₱\s*([\d,\.]+)/i);
  const pricePlainMatch = g2Value.match(/PRICE:\s*([\d,\.]+)/i);

  if (pricePesoMatch) price = pricePesoMatch[1].trim();
  else if (pricePlainMatch) price = pricePlainMatch[1].trim();

  sheet.getRange("V2").setValue(price);
}

/* -----------------------------
   STEP 5: Fill missing fields (J2/K2/L2/M2/N2/O2) via GPT
-------------------------------- */

function checkMissingFieldsAndAutoFill() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName("ENCODER");
  const linksSheet = ss.getSheetByName("LINKS");

  const conversation = [
    sheet.getRange("D2").getValue().toString().trim(),
    sheet.getRange("E2").getValue().toString().trim(),
    sheet.getRange("F2").getValue().toString().trim()
  ].join(" ");

  const apiKey = linksSheet.getRange("B6").getValue().toString().trim();

  if (!conversation || !apiKey) {
    Logger.log("Missing D2/E2/F2 content (conversation) or API key.");
    return;
  }

  const fields = {
    "Name": "J2",
    "Phone Number": "K2",
    "Address Line 1": "L2",
    "Province": "M2",
    "City": "N2",
    "Barangay": "O2"
  };

  const missingFields = [];

  for (let label in fields) {
    const cellA1 = fields[label];
    const value = sheet.getRange(cellA1).getValue().toString().trim();
    if (!value) missingFields.push({ label, cell: cellA1 });
  }

  if (missingFields.length === 0) {
    Logger.log("✅ All required fields are filled.");
    return;
  }

  Logger.log("❌ Missing fields:\n" + missingFields.map(f => `${f.label} (${f.cell})`).join("\n"));

  missingFields.forEach(field => {
    const prompt = `"${conversation}"\n\nExtract only the ${field.label}. Answer with the exact ${field.label} only. No explanation.`;

    const payload = {
      model: "gpt-3.5-turbo",
      messages: [
        { role: "system", content: "You are a helpful assistant that extracts missing shipping details only." },
        { role: "user", content: prompt }
      ],
      temperature: 0.2
    };

    const options = {
      method: "post",
      contentType: "application/json",
      headers: { Authorization: `Bearer ${apiKey}` },
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    };

    try {
      const response = UrlFetchApp.fetch("https://api.openai.com/v1/chat/completions", options);
      const json = JSON.parse(response.getContentText());
      const output = json.choices?.[0]?.message?.content?.trim() || "";

      const cell = sheet.getRange(field.cell);
      if (field.label === "Phone Number") cell.setNumberFormat("@");
      cell.setValue(output);

      Utilities.sleep(200); // rate limit buffer
    } catch (error) {
      Logger.log(`❌ Failed to fetch ${field.label}: ${error}`);
    }
  });
}

/* -----------------------------
   STEP 6: Macro scoring + remarks
-------------------------------- */

function Macro() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  if (ss.getName() !== "MACRO FOR LDT (JUNE 2026) 2.0") return;

  const sheet = ss.getSheetByName("ENCODER");
  const lastRow = sheet.getLastRow();

  for (let row = 2; row <= lastRow; row++) {
    const status = sheet.getRange(row, 18).getValue(); // Column R
    if (status !== "") continue;

    let province = sheet.getRange(row, 13).getValue().toString().toLowerCase().replace(/-/g, " ").trim(); // M
    let city = sheet.getRange(row, 14).getValue().toString().toLowerCase().replace(/-/g, " ").trim();     // N
    let barangay = sheet.getRange(row, 15).getValue().toString().toLowerCase().replace(/-/g, " ").trim(); // O

    if (!province && !city && !barangay) return;

    let k1Value = "";

    // Province
    if (province) {
      k1Value += province;
      sheet.getRange("P1").setValue(k1Value);
      SpreadsheetApp.flush();
      Utilities.sleep(200);

      const q1 = sheet.getRange("Q1").getValue().toString().toLowerCase().replace(/-/g, " ");
      if (!q1.includes(province)) {
        k1Value = k1Value.replace(province, "").trim();
        sheet.getRange("P1").setValue(k1Value);
        SpreadsheetApp.flush();
        Utilities.sleep(200);
      }
    }

    // City
    if (city) {
      k1Value += (k1Value ? " " : "") + city;
      sheet.getRange("P1").setValue(k1Value);
      SpreadsheetApp.flush();
      Utilities.sleep(200);

      const q1 = sheet.getRange("Q1").getValue().toString().toLowerCase().replace(/-/g, " ");
      if (!q1.includes(city)) {
        k1Value = k1Value.replace(new RegExp(`\\b${city}\\b`, "g"), "").replace(/\s+/g, " ").trim();
        sheet.getRange("P1").setValue(k1Value);
        SpreadsheetApp.flush();
        Utilities.sleep(200);
      }
    }

    // Barangay
    if (barangay) {
      k1Value += (k1Value ? " " : "") + barangay;
      sheet.getRange("P1").setValue(k1Value);
      SpreadsheetApp.flush();
      Utilities.sleep(200);

      const q1 = sheet.getRange("Q1").getValue().toString().toLowerCase().replace(/-/g, " ");
      let barangayValue = 0;
      const pointsNow = sheet.getRange(row, 24).getValue(); // V
      if (pointsNow === 7 || q1.includes(barangay)) barangayValue = 1;

      if (barangayValue === 0) {
        k1Value = k1Value.replace(new RegExp(`\\b${barangay}\\b`, "g"), "").replace(/\s+/g, " ").trim();
        sheet.getRange("P1").setValue(k1Value);
        SpreadsheetApp.flush();
        Utilities.sleep(200);
      }
    }

    // Final scoring
    const q1_latest = sheet.getRange("Q1").getValue().toString().toLowerCase().replace(/-/g, " ");
    let points = 0;
    if (province && q1_latest.includes(province)) points += 1;
    if (city && q1_latest.includes(city)) points += 2;
    if (barangay && q1_latest.includes(barangay)) points += 4;

    sheet.getRange(row, 24).setValue(points); // V
    SpreadsheetApp.flush();
    Utilities.sleep(200);

    const suggestions = parseInt(sheet.getRange("U1").getValue(), 10) || 0;
    let remark = "";
    let statusIcon = "";

    if (points === 7 && suggestions > 1) {
      remark = "MULTIPLE SUGGESTED ADDRESS";
      statusIcon = "";
    } else if (points === 7 && suggestions === 1) {
      remark = "";
      statusIcon = "✅";
    } else {
      switch (points) {
        case 6: remark = "Province"; break;
        case 5: remark = "City"; break;
        case 4: remark = "Province and City"; break;
        case 3: remark = "Barangay"; break;
        case 2: remark = "Province and Barangay"; break;
        case 1: remark = "City and Barangay"; break;
        case 0: remark = "❌"; break;
        default: remark = "❌"; break;
      }
      statusIcon = "❌";
    }

    sheet.getRange("W2").setValue(remark);
    sheet.getRange("X2").setValue(statusIcon);
    SpreadsheetApp.flush();

    // Copy R1/S1/T1 to R2/S2/T2
    sheet.getRange("R2").setValue(sheet.getRange("R1").getValue());
    sheet.getRange("S2").setValue(sheet.getRange("S1").getValue());
    sheet.getRange("T2").setValue(sheet.getRange("T1").getValue());
    SpreadsheetApp.flush();
    Utilities.sleep(200);

    return; // process first eligible row only
  }
}

/* -----------------------------
   STEP 7: Store + transfer encoder values to target sheet from LINKS B10/C10
-------------------------------- */

function storeAndTransferEncoderValues() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const encoderSheet = ss.getSheetByName("ENCODER");
  const linkSheet = ss.getSheetByName("LINKS");

  const VB10 = linkSheet.getRange("B10").getValue().toString().trim(); // target spreadsheet URL
  const VC10 = linkSheet.getRange("C10").getValue().toString().trim(); // target sheet name

  Logger.log("VB10 (Spreadsheet URL): " + VB10);
  Logger.log("VC10 (Sheet Name): " + VC10);

  if (!VB10 || !VC10) {
    SpreadsheetApp.getUi().alert("❌ Missing VB10 or VC10 value in LINKS sheet.");
    return;
  }

  const targetSpreadsheet = SpreadsheetApp.openByUrl(VB10);
  const targetSheet = targetSpreadsheet.getSheetByName(VC10);

  if (!targetSheet) {
    SpreadsheetApp.getUi().alert("❌ Target sheet not found: " + VC10);
    return;
  }

  const VDATE = encoderSheet.getRange("B2").getValue();
  const VPAGE = encoderSheet.getRange("C2").getValue();
  const fbName = encoderSheet.getRange("D2").getValue();
  const phone = encoderSheet.getRange("E2").getValue();
  const userInput = encoderSheet.getRange("F2").getValue();
  const g2Value = encoderSheet.getRange("G2").getValue();
  const VALL_USER_INPUT = `FB NAME: ${fbName}\nPHONE NUMBER: ${phone}\n${userInput}\n${g2Value}`;
  const VSHOP_DETAILS = g2Value;
  const VCXD = encoderSheet.getRange("H2").getValue();
  const VFULL_NAME = encoderSheet.getRange("J2").getValue();
  const VPHONE_NUMBER = encoderSheet.getRange("K2").getValue();
  const VADDRESS = encoderSheet.getRange("L2").getValue();
  const VPROVINCE = encoderSheet.getRange("R2").getValue();
  const VCITY = encoderSheet.getRange("S2").getValue();
  const VBARANGAY = encoderSheet.getRange("T2").getValue();
  const VITEM = encoderSheet.getRange("U2").getValue();
  const VPRICE = encoderSheet.getRange("V2").getValue();
  const VGPT_REMARKS = encoderSheet.getRange("W2").getValue();

  // nextRow is coming from ENCODER!A2 (row number in source)
  let nextRow = parseInt(encoderSheet.getRange("A2").getValue(), 10);
  if (isNaN(nextRow) || nextRow < 1) {
    nextRow = targetSheet.getLastRow() + 1; // fallback append
  }

  targetSheet.getRange(nextRow, 1).setValue(VDATE);            // A
  targetSheet.getRange(nextRow, 2).setValue(VFULL_NAME);       // B
  targetSheet.getRange(nextRow, 3).setValue(VPHONE_NUMBER);    // C
  targetSheet.getRange(nextRow, 4).setValue(VADDRESS);         // D
  targetSheet.getRange(nextRow, 5).setValue(VPROVINCE);        // E
  targetSheet.getRange(nextRow, 6).setValue(VCITY);            // F
  targetSheet.getRange(nextRow, 7).setValue(VBARANGAY);        // G
  targetSheet.getRange(nextRow, 8).setValue(VITEM);            // H
  targetSheet.getRange(nextRow, 9).setValue(VPRICE);           // I
  targetSheet.getRange(nextRow, 10).setValue(VPAGE);           // J
  targetSheet.getRange(nextRow, 11).setValue(VALL_USER_INPUT); // K
  targetSheet.getRange(nextRow, 12).setValue(VSHOP_DETAILS);   // L
  targetSheet.getRange(nextRow, 13).setValue(VCXD);            // M
  targetSheet.getRange(nextRow, 14).setValue(VGPT_REMARKS);    // N

  Logger.log("✅ Data successfully transferred.");
}

/* -----------------------------
   Optional: STOP/START helpers (since runAllSteps checks LOOP_STOP)
-------------------------------- */

function Stop() {
  PropertiesService.getScriptProperties().setProperty("LOOP_STOP", "TRUE");
  SpreadsheetApp.getActiveSpreadsheet().toast("🛑 Stop signal sent. Loop will exit soon.");
}

function Start() {
  PropertiesService.getScriptProperties().deleteProperty("LOOP_STOP");
  SpreadsheetApp.getActiveSpreadsheet().toast("✅ Start signal sent. Loop will proceed.");
}
