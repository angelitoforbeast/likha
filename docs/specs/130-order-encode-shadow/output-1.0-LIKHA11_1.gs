/***************************************************************
 * ✅ COPY-PASTE FRIENDLY VERSION (NO CONFLICTS)
 *
 * ✅ You only change:
 * 1) TARGET_SPREADSHEET_ID
 * 2) The GLOBAL entry function name at the bottom: LIKHA11_pababa()
 * 3) The MODULE name: CHECKER_11_pababa (if you will make another copy)
 *
 * Requirement:
 * - N1 yung startRowCandidate
 * - Hanapin ang FIRST AVAILABLE EVEN ROW pababa (scanning downward by 2)
 * - After processing that row, hanap ulit ng NEXT AVAILABLE EVEN ROW (below it)
 ***************************************************************/

var CHECKER_11_1 = (function () {

/*******************************
 * ✅ TARGET SPREADSHEET
 *******************************/
const TARGET_SPREADSHEET_ID = "1mWQqs6z_gtpKiUK8n3m8pf6M779FL-MGOcDm7RBYmJY"; // <-- PALITAN MO LANG ITO

function getTargetSS_() {
  if (!TARGET_SPREADSHEET_ID || String(TARGET_SPREADSHEET_ID).trim() === "") {
    return SpreadsheetApp.getActiveSpreadsheet();
  }
  return SpreadsheetApp.openById(String(TARGET_SPREADSHEET_ID).trim());
}

/*******************************
 * CONFIG
 *******************************/
const SHEET_DB  = "DATABASE";
const SHEET_LOC = "LocationData";
const SHEET_KEY = "API KEY";
const SHEET_LOG = "GPT_DEBUG";

// ✅ Verifier log
const SHEET_VERIFY_LOG = "GPT_VERIFY";

// ✅ Name+Addr1 log
const SHEET_NAMEADDR_LOG = "GPT_NAMEADDR";

// DATABASE columns (based on your sheet sample)
const COL_TIMESTAMP = 1; // A (optional)
const COL_FULLNAME  = 2; // B ✅ Full Name
const COL_PHONE     = 3; // C ✅ Phone
const COL_ADDR1     = 4; // D ✅ Address Line 1 (NO brgy/city/prov)
const COL_PROV      = 5; // E
const COL_CITY      = 6; // F
const COL_BRGY      = 7; // G
const COL_ITEM      = 8; // H (optional)
const COL_COD       = 9; // I (optional)
const COL_PAGE      = 10;// J  (NOTE: older config differs)

// ⚠️ KEEP YOUR ORIGINAL MAPPING (as you had it)
const COL_PAGE_OLD  = 8;     // H
const COL_PRICE     = 9;     // I
const COL_QTY       = 10;    // J
const COL_KTXT      = 11;    // K  ✅ GPT input (FULL customer chat)
const COL_MSG       = 13;    // M  ✅ CHECK reference (order/meta + address as requested)
const COL_R_OUT     = 18;    // R
const COL_S_OUT     = 19;    // S

// ✅ NEW: if Column N has value, force S = Full Address (skip fuzzy matching)
const COL_N_OVERRIDE = 14; // N

// Suggestions output columns
const COL_T_BRGYLIST = 20; // T  ✅ Barangay list
const COL_U_CITYLIST = 21; // U  ✅ City list
const COL_V_PROVLIST = 22; // V  ✅ Province list

// ✅ NEW: Column W output (K-based verification result)
const COL_W_KVERIFY  = 23; // W

// Start row pointer (formula) -> DATABASE!N1 (DO NOT UPDATE)
const CELL_STARTROW = "N1";

// GPT
const GPT_MODEL    = "gpt-5.2";
const GPT_SLEEP_MS = 250;

// Runtime guard
const MAX_RUNTIME_MS  = 4 * 60 * 1000; // 4 minutes
const STOP_BUFFER_MS  = 10 * 1000;     // don't start GPT call if <10s left


/*******************************
 * ✅ Find FIRST AVAILABLE EVEN ROW (pababa)
 * - startRowCandidate is from N1
 * - available = Column A has value AND (R blank OR S blank)
 *******************************/
function findFirstEvenRowToProcess_Down_(sh, startRowCandidate) {
  const lastRow = sh.getLastRow();
  let r = Math.min(Math.max(2, startRowCandidate), lastRow);

  // Ensure EVEN
  if (r % 3 === 0) r = r + 1;
  if (r % 3 === 1) r = r + 0;
  if (r % 3 === 2) r = r + 2;

  for (let row = r; row <= lastRow; row += 2) {
    const colA = String(sh.getRange(row, COL_TIMESTAMP).getValue() || "").trim(); // A
    if (!colA) continue;

    const rVal = String(sh.getRange(row, COL_R_OUT).getValue() || "").trim(); // R
    const sVal = String(sh.getRange(row, COL_S_OUT).getValue() || "").trim(); // S

    if (rVal === "" || sVal === "") return row;
  }
  return 0;
}


/*******************************
 * MAIN RUNNER (Even-row-by-even-row pababa)
 * - N1 -> starting candidate
 * - Find first available even row (pababa)
 * - Process it
 * - Then find next available even row again (below it)
 *******************************/
function LIKHA2_2() {
  const t0 = Date.now();

  const ss = getTargetSS_();
  const sh = ss.getSheetByName(SHEET_DB);
  if (!sh) throw new Error(`Sheet "${SHEET_DB}" not found`);

  const lastRow = sh.getLastRow();
  if (lastRow < 2) return;

  const n1Raw = sh.getRange(CELL_STARTROW).getValue();
  let startCandidate = parseInt(String(n1Raw || "").trim(), 10);

  if (!Number.isFinite(startCandidate) || isNaN(startCandidate) || startCandidate < 2) {
    ss.toast(
      `No rows to process: N1 is blank/invalid (N1="${n1Raw}").`,
      "CheckerAll_RowByRowFix",
      6
    );
    return;
  }

  if (startCandidate > lastRow) startCandidate = lastRow;

  // ✅ Build LocationData maps once
  const { brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv } = buildLocationMaps_();

  // ✅ Loop: find next available even row -> process -> then search again below it
  let candidate = startCandidate;

  while (true) {
    if (Date.now() - t0 > MAX_RUNTIME_MS) {
      ss.toast(
        `Stopped after 4 minutes. Re-run to continue (N1 will guide).`,
        "CheckerAll_RowByRowFix",
        6
      );
      return;
    }

    const row = findFirstEvenRowToProcess_Down_(sh, candidate);
    if (!row) {
      ss.toast(
        `No available EVEN rows found pababa starting from row ${candidate}.`,
        "CheckerAll_RowByRowFix",
        6
      );
      return;
    }

    checkSingleRow_RowByRowFix_(sh, row, brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv, t0);

    // Next search should be BELOW current even row
    candidate = row + 2;
    if (candidate > lastRow) {
      ss.toast(
        `Reached the bottom. Last processed EVEN row: ${row}.`,
        "CheckerAll_RowByRowFix",
        5
      );
      return;
    }
  }
}


/**
 * Check + Fix per row
 *
 * RULES:
 * - S classification (✅/Barangay/City/City and Barangay/Full Address) uses Column M only.
 * - GPT fixers (Province/City/Brgy + verifier + Name/Addr1) use Column K only.
 * - After edits, if final S is NOT ✅, verify support using Column K and write Column W.
 * - T/U/V blanking ONLY during FIRST-TIME compute (existingS === "").
 *
 * ✅ FINALIZE Name+Addr1 is called in finally,
 * but GPT call is SKIPPED if both B and D already filled (to avoid UrlFetch quota blowup).
 */
function checkSingleRow_RowByRowFix_(sh, row, brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv, t0) {
  const ss = getTargetSS_();

  const msgK_raw   = String(sh.getRange(row, COL_KTXT).getValue() || "").trim();
  const msgGPT_raw = msgK_raw;

  try {
    const page  = String(sh.getRange(row, COL_PAGE_OLD).getValue() || "").trim().toLowerCase();
    const price = String(sh.getRange(row, COL_PRICE).getValue() || "").replace(/[₱,]/g, "").trim();
    const qty   = String(sh.getRange(row, COL_QTY).getValue() || "").trim();

    const msgM_raw   = String(sh.getRange(row, COL_MSG).getValue() || "").trim();
    const msgCheck   = msgM_raw.toLowerCase().trim();
    const msgOrder   = msgCheck;

    const existingR = String(sh.getRange(row, COL_R_OUT).getValue() || "").trim();
    const existingS = String(sh.getRange(row, COL_S_OUT).getValue() || "").trim();

    const province = String(sh.getRange(row, COL_PROV).getValue() || "").trim();
    const cityRaw  = String(sh.getRange(row, COL_CITY).getValue() || "").trim();
    const barangay = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();

    const cityForCheck = normalizeCityForCheck_(cityRaw, province);

    /************
     * PHONE CHECK (C) - 10 digits
     ************/
    const phone = String(sh.getRange(row, COL_PHONE).getValue() || "").trim().replace(/\D/g, "");
    sh.getRange(row, COL_PHONE).setBackground(phone.length !== 10 ? "#FFFF00" : "white");

    /************
     * PAGE/PRICE/QTY CHECK -> R (uses M)
     ************/
    if (existingR === "") {
      let score = 0;
      let pageCorrect = false;
      let priceCorrect = false;
      let qtyCorrect = false;

      if (!msgOrder.includes("page:")) {
        sh.getRange(row, COL_R_OUT).setValue("✅");
        sh.getRange(row, COL_PAGE_OLD, 1, 3).setBackgrounds([["white", "white", "white"]]);
      } else {
        if (page && (msgOrder.includes(page) || msgOrder.includes(`page: ${page}`))) {
          score += 1;
          pageCorrect = true;
        }

        const priceMatch = msgOrder.match(/price:\s*₱?\s*([\d,.]+)/i);
        if (priceMatch && String(priceMatch[1] || "").replace(/,/g, "").trim() === price) {
          score += 2;
          priceCorrect = true;
        }

        const qtyMatch = msgOrder.match(/quantity:\s*(\d+)/i);
        if (qtyMatch && String(qtyMatch[1] || "").trim() === qty) {
          score += 4;
          qtyCorrect = true;
        }

        let result = "";
        switch (score) {
          case 7: result = "✅"; break;
          case 6: result = "Page Name"; break;
          case 5: result = "Quantity and Price"; break;
          case 4: result = "Page Name and Price"; break;
          case 3: result = "Quantity"; break;
          case 2: result = "Page Name and Quantity"; break;
          case 1: result = "Price and Quantity"; break;
          default: result = "❌";
        }

        sh.getRange(row, COL_R_OUT).setValue(result);
        if (page)  sh.getRange(row, COL_PAGE_OLD).setBackground(pageCorrect ? "white" : "#FFFF00");
        if (price) sh.getRange(row, COL_PRICE).setBackground(priceCorrect ? "white" : "#FFFF00");
        if (qty)   sh.getRange(row, COL_QTY).setBackground(qtyCorrect ? "white" : "#FFFF00");
      }
    }

    /************
     * ADDRESS CHECK -> S (uses M only)
     ************/
    if (existingS === "") {
      let sResult = "Full Address";

      const nOverrideVal = String(sh.getRange(row, COL_N_OVERRIDE).getValue() || "").trim();
      if (nOverrideVal !== "") {
        sResult = "Full Address";
      } else {
        const barangayClean = cleanBrgyForMatch_(barangay);
        const barangayMatch = isFuzzyMatch_(barangayClean, msgCheck);
        const cityMatch     = isFuzzyMatch_(cityForCheck, msgCheck);

        const pKey = normProv_(province);
        let provinceMatch = false;

        if (pKey === "manila") {
          provinceMatch =
            isFuzzyMatch_("manila", msgCheck) ||
            isFuzzyMatch_("metro manila", msgCheck) ||
            isFuzzyMatch_("metro-manila", msgCheck) ||
            isFuzzyMatch_("ncr", msgCheck);
        } else {
          provinceMatch = isFuzzyMatch_(province, msgCheck);
        }

        if (barangayMatch && cityMatch && provinceMatch) sResult = "✅";
        else if (!barangayMatch && cityMatch && provinceMatch) sResult = "Barangay";
        else if (barangayMatch && !cityMatch && provinceMatch) sResult = "City";
        else if (!barangayMatch && !cityMatch && provinceMatch) sResult = "City and Barangay";
        else if (barangayMatch && cityMatch && !provinceMatch) sResult = "Province";
        else sResult = "Full Address";
      }

      sh.getRange(row, COL_S_OUT).setValue(sResult);

      const yellow = "#ffff00";
      sh.getRange(row, COL_BRGY).setBackground(
        (sResult === "Barangay" || sResult === "Full Address" || sResult === "City and Barangay") ? yellow : "white"
      );
      sh.getRange(row, COL_CITY).setBackground(
        (sResult === "City" || sResult === "Full Address" || sResult === "City and Barangay") ? yellow : "white"
      );
      sh.getRange(row, COL_PROV).setBackground(
        (sResult === "Province" || sResult === "Full Address") ? yellow : "white"
      );

      // suggestion columns (first-time compute)
      if (sResult === "Barangay") {
        const list = getBarangayList_(cityRaw, province, brgyMap);
        sh.getRange(row, COL_T_BRGYLIST).setValue(list || "");
        sh.getRange(row, COL_U_CITYLIST).setValue("");
        sh.getRange(row, COL_V_PROVLIST).setValue("");
      } else if (sResult === "City") {
        const list = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(list || "");
        sh.getRange(row, COL_T_BRGYLIST).setValue("");
        sh.getRange(row, COL_V_PROVLIST).setValue("");
      } else if (sResult === "City and Barangay") {
        const list = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(list || "");
        sh.getRange(row, COL_T_BRGYLIST).setValue("");
        sh.getRange(row, COL_V_PROVLIST).setValue("");
      } else if (sResult === "Full Address") {
        sh.getRange(row, COL_V_PROVLIST).setValue(provListCsv || "");
        sh.getRange(row, COL_U_CITYLIST).setValue("");
        sh.getRange(row, COL_T_BRGYLIST).setValue("");
      } else {
        sh.getRange(row, COL_T_BRGYLIST).setValue("");
        sh.getRange(row, COL_U_CITYLIST).setValue("");
        sh.getRange(row, COL_V_PROVLIST).setValue("");
      }
    } else {
      // re-runs: fill-only if empty
      const sVal = existingS.trim().toLowerCase();
      const tVal = String(sh.getRange(row, COL_T_BRGYLIST).getValue() || "").trim();
      const uVal = String(sh.getRange(row, COL_U_CITYLIST).getValue() || "").trim();
      const vVal = String(sh.getRange(row, COL_V_PROVLIST).getValue() || "").trim();

      if (sVal === "barangay" && tVal === "") {
        const list = getBarangayList_(cityRaw, province, brgyMap);
        sh.getRange(row, COL_T_BRGYLIST).setValue(list || "");
      }

      if (sVal === "city" && uVal === "") {
        const list = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(list || "");
      }

      if (sVal === "city and barangay" && uVal === "") {
        const list = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(list || "");
      }

      if (sVal === "full address" && vVal === "") {
        sh.getRange(row, COL_V_PROVLIST).setValue(provListCsv || "");
      }
    }

    /************
     * GPT FIXES (K only) based on CURRENT S
     ************/
    const sNow = String(sh.getRange(row, COL_S_OUT).getValue() || "").trim().toLowerCase();

    // IMPORTANT: single-path (else-if) to avoid stale sNow issues
    if (sNow === "barangay") {
      const brgyList = String(sh.getRange(row, COL_T_BRGYLIST).getValue() || "").trim();

      if (!msgGPT_raw) {
        BRGYFIX_log_(row, province, cityRaw, barangay, brgyList, barangay, "", "SKIP:K_EMPTY_BRGY");
      } else if (brgyList) {
        if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
          BRGYFIX_log_(row, province, cityRaw, barangay, brgyList, barangay, "", "SKIP_NEAR_TIMEOUT_BRGY");
          return;
        }

        const brgyBefore = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();
        BRGYFIX_FixRow_(sh, row, province, cityRaw, barangay, msgGPT_raw, brgyList);
        const brgyAfter = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();

        if (brgyAfter && brgyAfter !== brgyBefore) {
          recomputeSAndSuggestions_(sh, row, msgM_raw, brgyMap, cityMap, provListCsv);
        }
      }

    } else if (sNow === "city") {
      let cityList = String(sh.getRange(row, COL_U_CITYLIST).getValue() || "").trim();
      if (!cityList) {
        cityList = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(cityList || "");
      }

      if (!cityList) return;
      if (!msgGPT_raw) {
        BRGYFIX_log_(row, province, cityRaw, barangay, cityList, cityRaw, "", "SKIP:K_EMPTY_CITYONLY");
        return;
      }

      if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
        BRGYFIX_log_(row, province, cityRaw, barangay, cityList, cityRaw, "", "SKIP_NEAR_TIMEOUT_CITYONLY");
        return;
      }

      const cityBefore = String(sh.getRange(row, COL_CITY).getValue() || "").trim();

      // 1) GPT fix CITY
      CITYFIX_FixRow_(sh, row, province, cityBefore, msgGPT_raw, cityList);
      const cityAfter = String(sh.getRange(row, COL_CITY).getValue() || "").trim();

      if (!cityAfter || !cityInList_(cityAfter, cityList)) {
        BRGYFIX_log_(row, province, cityBefore, barangay, cityList, cityAfter || cityBefore, "", "CITY_NOT_RESOLVED_STOP_CITYONLY");
        return;
      }

      // 2) Brgy list for new city
      const brgyListNew = getBarangayList_(cityAfter, province, brgyMap);
      sh.getRange(row, COL_T_BRGYLIST).setValue(brgyListNew || "");

      if (!brgyListNew) {
        BRGYFIX_log_(row, province, cityAfter, barangay, "", barangay, "", "BRGY_LIST_EMPTY_AFTER_CITYONLY");
        return;
      }

      // 3) Verify barangay belongs; if not -> GPT fix BRGY
      const brgyBefore = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();
      const belongs = brgyInList_(brgyBefore, brgyListNew);

      if (!belongs) {
        if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
          BRGYFIX_log_(row, province, cityAfter, brgyBefore, brgyListNew, brgyBefore, "", "SKIP_NEAR_TIMEOUT_CITYONLY_BRGYFIX");
          return;
        }
        BRGYFIX_FixRow_(sh, row, province, cityAfter, brgyBefore, msgGPT_raw, brgyListNew);
      }

      recomputeSAndSuggestions_(sh, row, msgM_raw, brgyMap, cityMap, provListCsv);

    } else if (sNow === "city and barangay") {
      let cityList = String(sh.getRange(row, COL_U_CITYLIST).getValue() || "").trim();
      if (!cityList) {
        cityList = getCityListByProvince_(province, cityMap);
        sh.getRange(row, COL_U_CITYLIST).setValue(cityList || "");
      }

      if (!cityList) return;
      if (!msgGPT_raw) {
        BRGYFIX_log_(row, province, cityRaw, barangay, cityList, barangay, "", "SKIP:K_EMPTY_CITYBRGY");
        return;
      }

      if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
        BRGYFIX_log_(row, province, cityRaw, barangay, cityList, barangay, "", "SKIP_NEAR_TIMEOUT_CITYBRGY");
        return;
      }

      const cityBefore = String(sh.getRange(row, COL_CITY).getValue() || "").trim();

      // 1) GPT fix CITY
      CITYFIX_FixRow_(sh, row, province, cityBefore, msgGPT_raw, cityList);
      const cityAfter = String(sh.getRange(row, COL_CITY).getValue() || "").trim();

      if (!cityAfter || !cityInList_(cityAfter, cityList)) {
        BRGYFIX_log_(row, province, cityBefore, barangay, cityList, cityAfter || cityBefore, "", "CITY_NOT_RESOLVED_SKIP_BRGY");
        return;
      }

      // 2) Brgy list using UPDATED city
      const brgyList2 = getBarangayList_(cityAfter, province, brgyMap);
      sh.getRange(row, COL_T_BRGYLIST).setValue(brgyList2 || "");

      if (!brgyList2) {
        BRGYFIX_log_(row, province, cityAfter, barangay, "", barangay, "", "BRGY_LIST_EMPTY_AFTER_CITY");
        return;
      }

      // 3) GPT fix BRGY
      BRGYFIX_FixRow_(sh, row, province, cityAfter, barangay, msgGPT_raw, brgyList2);

      recomputeSAndSuggestions_(sh, row, msgM_raw, brgyMap, cityMap, provListCsv);

    } else if (sNow === "full address") {
      let provList = String(sh.getRange(row, COL_V_PROVLIST).getValue() || "").trim();
      if (!provList) {
        provList = provListCsv || "";
        sh.getRange(row, COL_V_PROVLIST).setValue(provList || "");
      }
      if (!provList) return;

      if (!msgGPT_raw) {
        BRGYFIX_log_(row, province, cityRaw, barangay, provList, province, "", "SKIP:K_EMPTY_FULLADDR");
        return;
      }

      if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
        BRGYFIX_log_(row, province, cityRaw, barangay, provList, province, "", "SKIP_NEAR_TIMEOUT_FULLADDR");
        return;
      }

      // 1) GPT fix PROVINCE
      const provBefore = String(sh.getRange(row, COL_PROV).getValue() || "").trim();
      PROVFIX_FixRow_(sh, row, provBefore, msgGPT_raw, provList);
      const provAfter = String(sh.getRange(row, COL_PROV).getValue() || "").trim();

      if (!provAfter || !provInList_(provAfter, provList)) {
        BRGYFIX_log_(row, provBefore, "PROV", "PROV", provList, provAfter || provBefore, "", "PROV_NOT_RESOLVED_STOP");
        return;
      }

      // 2) City list for resolved province
      const cityList2 = getCityListByProvince_(provAfter, cityMap);
      sh.getRange(row, COL_U_CITYLIST).setValue(cityList2 || "");

      if (!cityList2) {
        BRGYFIX_log_(row, provAfter, "CITY", "CITY", "", "", "", "CITY_LIST_EMPTY_AFTER_PROV");
        return;
      }

      // 3) GPT fix CITY
      const cityBefore2 = String(sh.getRange(row, COL_CITY).getValue() || "").trim();
      CITYFIX_FixRow_(sh, row, provAfter, cityBefore2, msgGPT_raw, cityList2);
      const cityAfter2 = String(sh.getRange(row, COL_CITY).getValue() || "").trim();

      if (!cityAfter2 || !cityInList_(cityAfter2, cityList2)) {
        BRGYFIX_log_(row, provAfter, cityBefore2, barangay, cityList2, cityAfter2 || cityBefore2, "", "CITY_NOT_RESOLVED_STOP");
        return;
      }

      // 4) Brgy list for resolved province+city
      const brgyList3 = getBarangayList_(cityAfter2, provAfter, brgyMap);
      sh.getRange(row, COL_T_BRGYLIST).setValue(brgyList3 || "");

      if (!brgyList3) {
        BRGYFIX_log_(row, provAfter, cityAfter2, barangay, "", barangay, "", "BRGY_LIST_EMPTY_AFTER_CITY_FULLADDR");
        return;
      }

      // 5) GPT fix BRGY
      BRGYFIX_FixRow_(sh, row, provAfter, cityAfter2, barangay, msgGPT_raw, brgyList3);

      recomputeSAndSuggestions_(sh, row, msgM_raw, brgyMap, cityMap, provListCsv);
    }

    /************
     * FINAL: highlight H–J if blank (old mapping)
     ************/
    if (!page)  sh.getRange(row, COL_PAGE_OLD).setBackground("#FFFF00");
    if (!price) sh.getRange(row, COL_PRICE).setBackground("#FFFF00");
    if (!qty)   sh.getRange(row, COL_QTY).setBackground("#FFFF00");

    /*****************************************************************
     * ✅ K-based verification -> write Column W
     *****************************************************************/
    const finalS = String(sh.getRange(row, COL_S_OUT).getValue() || "").trim();

    if (finalS !== "✅") {
      if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) {
        VERIFYK_log_(row, "", "", "", "", "", "", "SKIP_NEAR_TIMEOUT_VERIFYK", "");
        return;
      }

      const provNow = String(sh.getRange(row, COL_PROV).getValue() || "").trim();
      const cityNow = String(sh.getRange(row, COL_CITY).getValue() || "").trim();
      const brgyNow = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();

      VERIFYK_VerifyAndWriteW_(sh, row, msgGPT_raw, provNow, cityNow, brgyNow, brgyToCitiesMap);
    } else {
      sh.getRange(row, COL_W_KVERIFY).setValue("");
      sh.getRange(row, COL_W_KVERIFY).setBackground("white");
    }

  } finally {
    // ✅ ALWAYS called, but GPT is SKIPPED if B & D already filled
    FINALIZE_NameAddr_(sh, row, t0, msgGPT_raw);
  }
}


/*******************************
 * ✅ LAST PROCESS: Fix Full Name (B) + Address Line 1 (D)
 * - Uses Column K as source of truth
 * - Address Line 1 must EXCLUDE brgy/city/province
 *
 * ✅ QUOTA-SAFE: if BOTH FullName and Addr1 are already filled (and not UNKNOWN),
 * skip GPT call to avoid too many UrlFetch.
 *******************************/
function FINALIZE_NameAddr_(sh, row, t0, msgGPT_raw) {
  try {
    if (Date.now() - t0 > (MAX_RUNTIME_MS - STOP_BUFFER_MS)) return;

    msgGPT_raw = String(msgGPT_raw || "").trim();
    if (!msgGPT_raw) {
      NAMEADDR_log_(row, "", "", "", "", "SKIP:K_EMPTY_NAMEADDR", "");
      return;
    }

    const fullNameOld = String(sh.getRange(row, COL_FULLNAME).getValue() || "").trim();
    const addr1Old    = String(sh.getRange(row, COL_ADDR1).getValue() || "").trim();

    // ✅ Skip GPT if both already filled and not UNKNOWN
    const nameFilled = fullNameOld && fullNameOld.toLowerCase() !== "unknown";
    const addrFilled = addr1Old && addr1Old.toLowerCase() !== "unknown";
    if (nameFilled && addrFilled) {
      NAMEADDR_log_(row, fullNameOld, addr1Old, fullNameOld, addr1Old, "SKIP:ALREADY_FILLED", "");
      return;
    }

    const provNow = String(sh.getRange(row, COL_PROV).getValue() || "").trim();
    const cityNow = String(sh.getRange(row, COL_CITY).getValue() || "").trim();
    const brgyNow = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();

    const apiKey = BRGYFIX_getApiKey_();
    if (!apiKey) throw new Error(`API Key not found at "${SHEET_KEY}"!A1`);

    const systemText = NAMEADDR_system_();
    const prompt = NAMEADDR_buildPrompt_(msgGPT_raw, fullNameOld, addr1Old, provNow, cityNow, brgyNow);

    let raw = "";
    let out = { full_name: "", address_line1: "" };

    try {
      raw = OPENAI_callStrictJSON_(apiKey, GPT_MODEL, systemText, prompt);
      out = NAMEADDR_parseJSON_(raw);
    } catch (e) {
      NAMEADDR_log_(row, fullNameOld, addr1Old, "", "", "ERR_NAMEADDR:" + e.message, String(raw || ""));
      return;
    }

    let newName = String(out.full_name || "").trim();
    let newAddr = String(out.address_line1 || "").trim();

    newAddr = NAMEADDR_stripLocations_(newAddr, brgyNow, cityNow, provNow);

    newName = newName.replace(/\s+/g, " ").trim();
    newAddr = newAddr.replace(/\s+/g, " ").replace(/\s*,\s*/g, ", ").trim();
    newAddr = newAddr.replace(/^,|,$/g, "").trim();

    let wrote = false;

    if (!nameFilled && newName && newName.toLowerCase() !== "unknown" && newName !== fullNameOld) {
      if (/[a-zA-ZñÑ]/.test(newName)) {
        sh.getRange(row, COL_FULLNAME).setValue(newName);
        sh.getRange(row, COL_FULLNAME).setBackground("#00ff00");
        wrote = true;
      }
    }

    if (!addrFilled && newAddr !== "" && newAddr.toLowerCase() !== "unknown" && newAddr !== addr1Old) {
      sh.getRange(row, COL_ADDR1).setValue(newAddr);
      sh.getRange(row, COL_ADDR1).setBackground("#00ff00");
      wrote = true;
    }

    Utilities.sleep(GPT_SLEEP_MS);
    NAMEADDR_log_(row, fullNameOld, addr1Old, newName || fullNameOld, newAddr || addr1Old, wrote ? "WROTE_NAMEADDR" : "NO_CHANGE", raw);

  } catch (e) {
    NAMEADDR_log_(row, "", "", "", "", "ERR_NAMEADDR_FATAL:" + e.message, "");
  }
}

function NAMEADDR_system_() {
  return [
    "You extract and normalize Philippine customer name and Address Line 1 from messy chat logs.",
    "",
    "You will receive RAW_CUSTOMER_CHAT and CURRENT values.",
    "",
    "Output STRICT JSON only:",
    "{\"full_name\":\"...\",\"address_line1\":\"...\"}",
    "",
    "Rules for full_name:",
    "- Choose the customer's real name if clearly stated (often after 'Name:' or in order confirmation).",
    "- Ignore page/persona names (e.g., seller, admin) unless the customer is clearly that person.",
    "- Remove emojis and extra punctuation; keep proper spacing/casing.",
    "",
    "Rules for address_line1:",
    "- This is Address Line 1 ONLY: house/lot/block, street, purok/sitio, subdivision, building, landmark, etc.",
    "- DO NOT include Barangay/Brgy, City/Municipality, or Province in address_line1.",
    "- If chat has only 'Brgy, City, Province' and no other details, return empty string for address_line1.",
    "- Prefer details near address lines, or after words like 'address', 'landmark', 'purok', 'street', 'subd', 'blk', 'lot'.",
    "- If there are conflicting address details, pick the most complete and most recent one.",
    "",
    "Return JSON only. No extra text."
  ].join("\n");
}

function NAMEADDR_buildPrompt_(kTextRaw, fullNameOld, addr1Old, provNow, cityNow, brgyNow) {
  return (
    "RAW_CUSTOMER_CHAT:\n<<<\n" + String(kTextRaw || "") + "\n>>>\n\n" +
    "CURRENT_FULL_NAME: " + String(fullNameOld || "") + "\n" +
    "CURRENT_ADDRESS_LINE1: " + String(addr1Old || "") + "\n" +
    "CURRENT_PROVINCE: " + String(provNow || "") + "\n" +
    "CURRENT_CITY: " + String(cityNow || "") + "\n" +
    "CURRENT_BARANGAY: " + String(brgyNow || "") + "\n\n" +
    "Task:\n" +
    "1) Extract best FULL NAME.\n" +
    "2) Extract best ADDRESS LINE 1 (exclude barangay/city/province).\n\n" +
    "Return STRICT JSON only:\n" +
    "{\"full_name\":\"...\",\"address_line1\":\"...\"}\n"
  );
}

function NAMEADDR_parseJSON_(raw) {
  const s = String(raw || "").trim();
  try {
    const obj = JSON.parse(s);
    return {
      full_name: String(obj.full_name || "").trim(),
      address_line1: String(obj.address_line1 || "").trim()
    };
  } catch (e) {}

  const m = s.match(/\{[\s\S]*\}/);
  if (m) {
    const obj = JSON.parse(m[0]);
    return {
      full_name: String(obj.full_name || "").trim(),
      address_line1: String(obj.address_line1 || "").trim()
    };
  }
  return { full_name: "", address_line1: "" };
}

function NAMEADDR_stripLocations_(addr, brgy, city, prov) {
  let out = String(addr || "");

  const parts = [brgy, city, prov]
    .map(x => String(x || "").trim())
    .filter(Boolean);

  for (const p of parts) {
    if (!p) continue;
    const escaped = escapeRegExp_(p);
    const re = new RegExp(`\\b${escaped}\\b`, "ig");
    out = out.replace(re, " ");
  }

  out = out
    .replace(/\b(barangay|brgy)\b/ig, " ")
    .replace(/\b(city|city of|municipality)\b/ig, " ")
    .replace(/\b(province|prov)\b/ig, " ")
    .replace(/\s+/g, " ")
    .replace(/\s*,\s*/g, ", ")
    .replace(/^,|,$/g, "")
    .trim();

  return out;
}

function NAMEADDR_log_(rowNum, oldName, oldAddr, newName, newAddr, status, raw) {
  const ss = getTargetSS_();
  let sh = ss.getSheetByName(SHEET_NAMEADDR_LOG);
  if (!sh) sh = ss.insertSheet(SHEET_NAMEADDR_LOG);

  if (sh.getLastRow() === 0) {
    sh.appendRow([
      "timestamp", "row",
      "old_full_name", "old_address_line1",
      "new_full_name", "new_address_line1",
      "status", "raw_response"
    ]);
  }

  sh.appendRow([
    new Date(),
    rowNum,
    oldName,
    oldAddr,
    newName,
    newAddr,
    status,
    raw
  ]);
}


/*******************************
 * ✅ Recompute S after edits (M only)
 * ✅ No auto-clears of T/U/V here.
 *******************************/
function recomputeSAndSuggestions_(sh, row, msgM_raw, brgyMap, cityMap, provListCsv) {
  const msgCheck = String(msgM_raw || "").toLowerCase().trim();

  const provNow = String(sh.getRange(row, COL_PROV).getValue() || "").trim();
  const cityNow = String(sh.getRange(row, COL_CITY).getValue() || "").trim();
  const brgyNow = String(sh.getRange(row, COL_BRGY).getValue() || "").trim();

  const cityCheckNow = normalizeCityForCheck_(cityNow, provNow);

  const barangayCleanNow = cleanBrgyForMatch_(brgyNow);
  const barangayMatchNow = isFuzzyMatch_(barangayCleanNow, msgCheck);
  const cityMatchNow     = isFuzzyMatch_(cityCheckNow, msgCheck);

  const pKeyNow = normProv_(provNow);
  let provinceMatchNow = false;
  if (pKeyNow === "manila") {
    provinceMatchNow =
      isFuzzyMatch_("manila", msgCheck) ||
      isFuzzyMatch_("metro manila", msgCheck) ||
      isFuzzyMatch_("metro-manila", msgCheck) ||
      isFuzzyMatch_("ncr", msgCheck);
  } else {
    provinceMatchNow = isFuzzyMatch_(provNow, msgCheck);
  }

  let sNew = "Full Address";
  if (barangayMatchNow && cityMatchNow && provinceMatchNow) sNew = "✅";
  else if (!barangayMatchNow && cityMatchNow && provinceMatchNow) sNew = "Barangay";
  else if (barangayMatchNow && !cityMatchNow && provinceMatchNow) sNew = "City";
  else if (!barangayMatchNow && !cityMatchNow && provinceMatchNow) sNew = "City and Barangay";
  else if (barangayMatchNow && cityMatchNow && !provinceMatchNow) sNew = "Province";
  else sNew = "Full Address";

  sh.getRange(row, COL_S_OUT).setValue(sNew);

  const yellow = "#ffff00";
  sh.getRange(row, COL_BRGY).setBackground(
    (sNew === "Barangay" || sNew === "Full Address" || sNew === "City and Barangay") ? yellow : "white"
  );
  sh.getRange(row, COL_CITY).setBackground(
    (sNew === "City" || sNew === "Full Address" || sNew === "City and Barangay") ? yellow : "white"
  );
  sh.getRange(row, COL_PROV).setBackground(
    (sNew === "Province" || sNew === "Full Address") ? yellow : "white"
  );

  if (sNew === "Barangay") {
    sh.getRange(row, COL_T_BRGYLIST).setValue(getBarangayList_(cityNow, provNow, brgyMap) || "");
  } else if (sNew === "City") {
    sh.getRange(row, COL_U_CITYLIST).setValue(getCityListByProvince_(provNow, cityMap) || "");
  } else if (sNew === "City and Barangay") {
    sh.getRange(row, COL_U_CITYLIST).setValue(getCityListByProvince_(provNow, cityMap) || "");
  } else if (sNew === "Full Address") {
    sh.getRange(row, COL_V_PROVLIST).setValue(provListCsv || "");
  }
}


/*******************************
 * ✅ City normalization for compare (CHECK only)
 *******************************/
function normalizeCityForCheck_(cityRaw, provinceRaw) {
  let c = String(cityRaw || "");
  if (!c.trim()) return "";

  let cNorm = normPlace_(c);

  cNorm = cNorm.replace(/\bcity\b/g, " ").replace(/\s+/g, " ").trim();

  const pNorm = normProv_(provinceRaw);
  if (!pNorm) return cNorm;

  if (pNorm === "manila") {
    cNorm = cNorm
      .replace(/\bmetro\b/g, " ")
      .replace(/\bncr\b/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  const reG = new RegExp(`\\b${escapeRegExp_(pNorm)}\\b`, "g");
  const m = cNorm.match(reG);
  const count = m ? m.length : 0;

  if (count === 0) return cNorm;

  if (count === 1) {
    const out = cNorm.replace(reG, " ").replace(/\s+/g, " ").trim();
    return out || cNorm;
  }

  const re1 = new RegExp(`\\b${escapeRegExp_(pNorm)}\\b`);
  const out2 = cNorm.replace(re1, " ").replace(/\s+/g, " ").trim();
  return out2 || cNorm;
}

function escapeRegExp_(s) {
  return String(s || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}


/*******************************
 * LOCATIONDATA MAPS
 * LocationData:
 *   B = Province
 *   C = City
 *   D = Barangay
 *******************************/
function buildLocationMaps_() {
  const ss = getTargetSS_();
  const shLoc = ss.getSheetByName(SHEET_LOC);

  const brgyMap = new Map(); // cityNorm|provNorm -> Set(brgyLabel)
  const cityMap = new Map(); // provNorm -> Map(cityNorm -> cityLabel)
  const provMap = new Map(); // provNorm -> provLabel (canonical display)
  const brgyToCitiesMap = new Map(); // brgyNorm|provNorm -> Set(cityLabel)

  if (!shLoc) return { brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv: "" };

  const last = shLoc.getLastRow();
  if (last < 2) return { brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv: "" };

  const rows = shLoc.getRange(2, 2, last - 1, 3).getValues(); // B:C:D

  rows.forEach(([provRaw, cityRaw, brgyRaw]) => {
    const provLabel = String(provRaw || "").trim();
    const p = normProv_(provRaw);

    const cNorm = normPlace_(cityRaw);
    const cityLabel = String(cityRaw || "").trim();

    const brgyLabel = normalizeBrgyLabel_(brgyRaw);

    if (p && provLabel && !provMap.has(p)) provMap.set(p, provLabel);

    if (p && cNorm && cityLabel) {
      if (!cityMap.has(p)) cityMap.set(p, new Map());
      const inner = cityMap.get(p);
      if (!inner.has(cNorm)) inner.set(cNorm, cityLabel);
    }

    if (p && cNorm && brgyLabel) {
      const key = cNorm + "|" + p;
      if (!brgyMap.has(key)) brgyMap.set(key, new Set());
      brgyMap.get(key).add(brgyLabel);

      const brgyNorm = normPlace_(
        String(brgyLabel || "")
          .replace(/\bbarangay\b/ig, " ")
          .replace(/\bbrgy\.?\b/ig, " ")
          .replace(/\s+/g, " ")
          .trim()
      );
      if (brgyNorm) {
        const invKey = brgyNorm + "|" + p;
        if (!brgyToCitiesMap.has(invKey)) brgyToCitiesMap.set(invKey, new Set());
        brgyToCitiesMap.get(invKey).add(cityLabel);
      }
    }
  });

  const provList = Array.from(provMap.values())
    .filter(Boolean)
    .sort((a, b) => String(a).localeCompare(String(b), "en", { sensitivity: "base" }));

  return { brgyMap, cityMap, provMap, brgyToCitiesMap, provListCsv: provList.join(", ") };
}

function getCityListByProvince_(province, cityMap) {
  if (!cityMap || cityMap.size === 0) return "";

  const p = normProv_(province);
  if (!p) return "";

  const inner = cityMap.get(p);
  if (!inner || inner.size === 0) return "";

  const list = Array.from(inner.values());
  list.sort((a, b) => String(a).localeCompare(String(b), "en", { sensitivity: "base" }));
  return list.join(", ");
}

function getBarangayList_(city, province, brgyMap) {
  if (!brgyMap || brgyMap.size === 0) return "";

  const c = normPlace_(city);
  const p = normProv_(province);
  if (!c || !p) return "";

  let set = brgyMap.get(c + "|" + p);
  if (!set || set.size === 0) set = brgyMap.get(p + "|" + c); // swapped fallback
  if (!set || set.size === 0) return "";

  const list = Array.from(set);
  list.sort((a, b) => String(a).localeCompare(String(b), "en", { sensitivity: "base" }));
  return list.join(", ");
}


/*******************************
 * ✅ Infer city from (barangay + province)
 *******************************/
function inferCityFromBarangay_(provNow, brgyNow, brgyToCitiesMap) {
  const p = normProv_(provNow);
  if (!p) return [];

  const bNorm = normPlace_(
    String(brgyNow || "")
      .replace(/\bbarangay\b/ig, " ")
      .replace(/\bbrgy\.?\b/ig, " ")
      .replace(/\s+/g, " ")
      .trim()
  );
  if (!bNorm) return [];

  const key = bNorm + "|" + p;
  const set = brgyToCitiesMap.get(key);
  if (!set || set.size === 0) return [];

  return Array.from(set);
}


/*******************************
 * Normalizers
 *******************************/
function normPlace_(v) {
  return String(v || "")
    .toLowerCase()
    .replace(/ñ/g, "n")
    .replace(/[.,]/g, " ")
    .replace(/[-/]/g, " ")
    .replace(/\bcity of\b/g, " ")
    .replace(/\bprovince of\b/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

// Metro Manila / NCR / Metro-Manila -> "manila"
function normProv_(v) {
  let s = String(v || "")
    .toLowerCase()
    .replace(/ñ/g, "n")
    .replace(/[.,]/g, " ")
    .replace(/[-/]/g, " ")
    .replace(/\s+/g, " ")
    .trim();

  if (!s) return "";

  if (
    s === "ncr" ||
    s.includes("metro manila") ||
    s.includes("metro-manila") ||
    s.includes("national capital region")
  ) {
    return "manila";
  }

  return s;
}

function cleanBrgyForMatch_(v) {
  return String(v || "")
    .replace(/\b(brgy\.?|barangay)\b/gi, "")
    .replace(/\s+/g, " ")
    .trim();
}

function normalizeBrgyLabel_(v) {
  let s = String(v || "").trim();
  if (!s) return "";

  s = s.replace(/\bbrgy\.?\b/ig, "").trim();
  s = s.replace(/\s+/g, " ").trim();

  if (/\bbarangay\b/i.test(s)) {
    const after = s.replace(/\bbarangay\b/ig, "").replace(/\s+/g, " ").trim();
    return after ? ("BARANGAY " + after) : "BARANGAY";
  }

  return s;
}


/*******************************
 * List membership checkers
 *******************************/
function cityInList_(cityValue, cityListCsv) {
  const target = normPlace_(cityValue);
  const cities = String(cityListCsv || "")
    .split(",")
    .map(s => normPlace_(s))
    .filter(Boolean);

  return cities.includes(target);
}

function provInList_(provValue, provListCsv) {
  const target = normProv_(provValue);
  const provs = String(provListCsv || "")
    .split(",")
    .map(s => normProv_(s))
    .filter(Boolean);

  return provs.includes(target);
}

function canonicalizeProvince_(provCandidate, provListCsv) {
  const target = normProv_(provCandidate);
  const provs = String(provListCsv || "")
    .split(",")
    .map(s => String(s).trim())
    .filter(Boolean);

  for (const p of provs) {
    if (normProv_(p) === target) return p;
  }
  return String(provCandidate || "").trim();
}

function brgyInList_(brgyValue, brgyListCsv) {
  const target = normPlace_(
    String(brgyValue || "")
      .replace(/\bbarangay\b/ig, " ")
      .replace(/\bbrgy\.?\b/ig, " ")
      .replace(/\s+/g, " ")
      .trim()
  );
  if (!target) return false;

  const brgys = String(brgyListCsv || "")
    .split(",")
    .map(s =>
      normPlace_(
        String(s || "")
          .replace(/\bbarangay\b/ig, " ")
          .replace(/\bbrgy\.?\b/ig, " ")
          .replace(/\s+/g, " ")
          .trim()
      )
    )
    .filter(Boolean);

  return brgys.includes(target);
}


/*******************************
 * FUZZY MATCH HELPERS (CHECK only)
 *******************************/
function normalizeForFuzzy_(str) {
  return String(str || "")
    .toLowerCase()
    .replace(/[0o]/g, "o")
    .replace(/[1il|]/g, "l")
    .replace(/[^a-z0-9]/g, "");
}

function isFuzzyMatch_(word, message, threshold = 0.7) {
  const normWord = normalizeForFuzzy_(word);
  const normMsg  = normalizeForFuzzy_(message);

  if (normWord.length < 3) return false;
  if (normMsg.includes(normWord)) return true;

  for (let i = 0; i <= normMsg.length - normWord.length; i++) {
    const window = normMsg.slice(i, i + normWord.length);
    const distance = levenshtein_(normWord, window);
    const similarity = 1 - distance / normWord.length;
    if (similarity >= threshold) return true;
  }
  return false;
}

function levenshtein_(a, b) {
  const dp = Array.from({ length: b.length + 1 }, () => []);
  for (let i = 0; i <= b.length; i++) dp[i][0] = i;
  for (let j = 0; j <= a.length; j++) dp[0][j] = j;

  for (let i = 1; i <= b.length; i++) {
    for (let j = 1; j <= a.length; j++) {
      const cost = b[i - 1] === a[j - 1] ? 0 : 1;
      dp[i][j] = Math.min(
        dp[i - 1][j] + 1,
        dp[i][j - 1] + 1,
        dp[i - 1][j - 1] + cost
      );
    }
  }
  return dp[b.length][a.length];
}


/**************************************************************
 * ✅ PROVINCE GPT FIXER (writes Column E only) - uses Column K
 **************************************************************/
function PROVFIX_FixRow_(sh, rowNum, provOld, ktxt, provListCsv) {
  ktxt = String(ktxt || "").trim();
  provListCsv = String(provListCsv || "").trim();

  if (!ktxt) {
    BRGYFIX_log_(rowNum, provOld, "PROV_OLD", "PROV_OLD", provListCsv, provOld, "", "SKIP:K_EMPTY_PROV");
    return;
  }
  if (!provListCsv) {
    BRGYFIX_log_(rowNum, provOld, "PROV_OLD", "PROV_OLD", provListCsv, provOld, "", "SKIP:PROV_LIST_EMPTY");
    return;
  }

  const apiKey = BRGYFIX_getApiKey_();
  if (!apiKey) throw new Error(`API Key not found at "${SHEET_KEY}"!A1`);

  const prompt = PROVFIX_buildPrompt_(ktxt, provOld, provListCsv);

  let raw = "";
  let newProv = "";

  try {
    raw = OPENAI_callStrictJSON_(apiKey, GPT_MODEL, PROVFIX_system_(), prompt);
    const parsed = BRGYFIX_parseJSON_(raw);
    newProv = String(parsed.province || "").trim();
  } catch (e) {
    BRGYFIX_log_(rowNum, provOld, "PROV_OLD", "PROV_OLD", provListCsv, provOld, String(raw || ""), "ERR_PROV:" + e.message);
    return;
  }

  let status = "PROV_NO_CHANGE";

  if (newProv && newProv !== "UNKNOWN") {
    const canon = canonicalizeProvince_(newProv, provListCsv);
    if (canon && canon !== provOld && provInList_(canon, provListCsv)) {
      sh.getRange(rowNum, COL_PROV).setValue(canon);
      sh.getRange(rowNum, COL_PROV).setBackground("#00ff00");
      status = "WROTE_PROV";
    } else {
      if (canon && !provInList_(canon, provListCsv)) status = "PROV_NOT_IN_LIST";
    }
  }

  Utilities.sleep(GPT_SLEEP_MS);
  BRGYFIX_log_(rowNum, provOld, "PROV_OLD", "PROV_OLD", provListCsv, newProv || provOld, raw, status);
}

function PROVFIX_system_() {
  return [
    "You validate Philippine province names.",
    "Choose ONLY from the provided province list.",
    "Return STRICT JSON only: {\"province\":\"...\"}.",
    "If truly cannot determine, return UNKNOWN."
  ].join(" ");
}

function PROVFIX_buildPrompt_(kText, provOld, provListCsv) {
  return (
    kText + "\n\n" +
    "Task: correct the PROVINCE only.\n" +
    "Rules:\n" +
    "- Choose ONLY from the allowed PROVINCE list below.\n" +
    "- If the message explicitly mentions NCR/Metro Manila, pick the NCR/Metro Manila option in the list.\n" +
    "- Do NOT keep the current province unless the message supports it.\n\n" +
    "Current Province: " + provOld + "\n\n" +
    "Allowed PROVINCE list (pick ONLY from this list):\n" +
    provListCsv + "\n\n" +
    "Return STRICT JSON only: {\"province\":\"...\"}\n"
  );
}


/**************************************************************
 * CITY GPT FIXER (writes Column F only) - uses Column K
 **************************************************************/
function CITYFIX_FixRow_(sh, rowNum, prov, cityOld, ktxt, cityList) {
  ktxt = String(ktxt || "").trim();
  cityList = String(cityList || "").trim();

  if (!ktxt) {
    BRGYFIX_log_(rowNum, prov, cityOld, "CITY_OLD", cityList, cityOld, "", "SKIP:K_EMPTY_CITY");
    return;
  }
  if (!cityList) {
    BRGYFIX_log_(rowNum, prov, cityOld, "CITY_OLD", cityList, cityOld, "", "SKIP:CITY_LIST_EMPTY");
    return;
  }

  const apiKey = BRGYFIX_getApiKey_();
  if (!apiKey) throw new Error(`API Key not found at "${SHEET_KEY}"!A1`);

  const prompt = CITYFIX_buildPrompt_(ktxt, prov, cityOld, cityList);

  let raw = "";
  let newCity = "";

  try {
    raw = OPENAI_callStrictJSON_(apiKey, GPT_MODEL, CITYFIX_system_(), prompt);
    const parsed = BRGYFIX_parseJSON_(raw);
    newCity = String(parsed.city || "").trim();
  } catch (e) {
    BRGYFIX_log_(rowNum, prov, cityOld, "CITY_OLD", cityList, cityOld, String(raw || ""), "ERR_CITY:" + e.message);
    return;
  }

  let status = "CITY_NO_CHANGE";

  if (newCity && newCity !== "UNKNOWN" && newCity !== cityOld && cityInList_(newCity, cityList)) {
    sh.getRange(rowNum, COL_CITY).setValue(newCity);
    sh.getRange(rowNum, COL_CITY).setBackground("#00ff00");
    status = "WROTE_CITY";
  } else {
    if (newCity && newCity !== "UNKNOWN" && !cityInList_(newCity, cityList)) {
      status = "CITY_NOT_IN_LIST";
    }
  }

  Utilities.sleep(GPT_SLEEP_MS);
  BRGYFIX_log_(rowNum, prov, cityOld, "CITY_OLD", cityList, newCity || cityOld, raw, status);
}

function CITYFIX_system_() {
  return [
    "You validate Philippine cities/municipalities.",
    "Choose ONLY from the provided city list.",
    "Return STRICT JSON only: {\"city\":\"...\",\"province\":\"...\"}.",
    "If truly cannot determine, return UNKNOWN."
  ].join(" ");
}

function CITYFIX_buildPrompt_(kText, prov, cityOld, cityList) {
  return (
    kText + "\n\n" +
    "Task: correct the CITY only.\n" +
    "Rules:\n" +
    "- Choose ONLY from the allowed CITY list below.\n" +
    "- If the message explicitly mentions a city/municipality, you MUST pick that city if it exists in the allowed list.\n" +
    "- Do NOT keep the current city unless the message supports it.\n" +
    "- If the message contains a 'City:' field, prioritize that value.\n\n" +
    "Province: " + prov + "\n" +
    "Current City: " + cityOld + "\n\n" +
    "Allowed CITY list (pick ONLY from this list):\n" +
    cityList + "\n\n" +
    "Return STRICT JSON only: {\"city\":\"...\",\"province\":\"...\"}\n"
  );
}


/**************************************************************
 * GPT BARANGAY FIXER (writes Column G only) - uses Column K
 **************************************************************/
function BRGYFIX_FixRow_(sh, rowNum, prov, city, brgy, ktxt, brgyList) {
  ktxt = String(ktxt || "").trim();
  if (!ktxt) {
    BRGYFIX_log_(rowNum, prov, city, brgy, brgyList, brgy, "", "SKIP:K_EMPTY_BRGY");
    return;
  }

  brgyList = String(brgyList || "").trim();
  if (!brgyList) {
    BRGYFIX_log_(rowNum, prov, city, brgy, brgyList, brgy, "", "SKIP:BRGY_LIST_EMPTY");
    return;
  }

  const apiKey = BRGYFIX_getApiKey_();
  if (!apiKey) throw new Error(`API Key not found at "${SHEET_KEY}"!A1`);

  const prompt = BRGYFIX_buildPrompt_(ktxt, prov, city, brgy, brgyList);

  let raw = "";
  let parsed = { barangay: "", city: "", province: "" };

  try {
    raw = OPENAI_callStrictJSON_(apiKey, GPT_MODEL, BRGYFIX_system_(), prompt);
    parsed = BRGYFIX_parseJSON_(raw);
  } catch (e) {
    BRGYFIX_log_(rowNum, prov, city, brgy, brgyList, brgy, String(raw || ""), "ERR_BRGY:" + e.message);
    return;
  }

  let newBrgy = brgy;
  if (parsed.barangay && parsed.barangay !== "UNKNOWN") newBrgy = String(parsed.barangay).trim();

  let status = "NO_CHANGE";
  if (newBrgy && newBrgy !== brgy) {
    if (brgyInList_(newBrgy, brgyList)) {
      sh.getRange(rowNum, COL_BRGY).setValue(newBrgy);
      sh.getRange(rowNum, COL_BRGY).setBackground("#00ff00");
      status = "WROTE_BRGY";
    } else {
      status = "BRGY_NOT_IN_LIST";
    }
  }

  Utilities.sleep(GPT_SLEEP_MS);
  BRGYFIX_log_(rowNum, prov, city, brgy, brgyList, newBrgy, raw, status);
}

function BRGYFIX_system_() {
  return [
    "You validate Philippine address barangays.",
    "Return STRICT JSON only: {\"barangay\":\"...\",\"city\":\"...\",\"province\":\"...\"}.",
    "If a barangay list is provided, choose ONLY from that list.",
    "If truly cannot determine, return UNKNOWN."
  ].join(" ");
}

function BRGYFIX_buildPrompt_(kText, prov, city, brgy, brgyList) {
  return (
    kText + "\n\n" +
    "We need to correct BARANGAY only.\n" +
    "Province: " + prov + "\n" +
    "City: " + city + "\n" +
    "Current Barangay: " + brgy + "\n\n" +
    "Allowed BARANGAY list (pick ONLY from this list):\n" +
    brgyList + "\n\n" +
    "Return STRICT JSON only: {\"barangay\":\"...\",\"city\":\"...\",\"province\":\"...\"}\n"
  );
}


/**************************************************************
 * ✅ K-based verifier (writes Column W only) - uses Column K
 **************************************************************/
function VERIFYK_VerifyAndWriteW_(sh, rowNum, ktxtRaw, provNow, cityNow, brgyNow, brgyToCitiesMap) {
  ktxtRaw = String(ktxtRaw || "").trim();

  if (!ktxtRaw) {
    sh.getRange(rowNum, COL_W_KVERIFY).setValue("");
    sh.getRange(rowNum, COL_W_KVERIFY).setBackground("#ffff00");
    VERIFYK_log_(rowNum, provNow, cityNow, brgyNow, "", "", "", "SKIP:K_EMPTY_VERIFYK", "");
    return;
  }

  const apiKey = BRGYFIX_getApiKey_();
  if (!apiKey) throw new Error(`API Key not found at "${SHEET_KEY}"!A1`);

  const systemText = VERIFYK_system_();
  const prompt = VERIFYK_buildPrompt_(ktxtRaw, provNow, cityNow, brgyNow);

  let raw = "";
  let out = { province_ok: false, city_ok: false, barangay_ok: false };

  try {
    raw = OPENAI_callStrictJSON_(apiKey, GPT_MODEL, systemText, prompt);
    out = VERIFYK_parseVerifyJSON_(raw);
  } catch (e) {
    sh.getRange(rowNum, COL_W_KVERIFY).setValue("Full Address");
    sh.getRange(rowNum, COL_W_KVERIFY).setBackground("#ffff00");
    VERIFYK_log_(rowNum, provNow, cityNow, brgyNow, "", "", "", "ERR_VERIFYK:" + e.message, String(raw || ""));
    return;
  }

  let province_ok = !!out.province_ok;
  let city_ok     = !!out.city_ok;
  let barangay_ok = !!out.barangay_ok;

  // ✅ IMPLIED CITY PATCH
  if (province_ok && barangay_ok && !city_ok) {
    const candidates = inferCityFromBarangay_(provNow, brgyNow, brgyToCitiesMap);
    if (candidates.length === 1) {
      const inferred = candidates[0];
      if (normPlace_(inferred) === normPlace_(cityNow)) city_ok = true;
    }
  }

  const wVal = VERIFYK_computeW_(province_ok, city_ok, barangay_ok);
  const wCell = sh.getRange(rowNum, COL_W_KVERIFY);

  if (wVal === "Province") {
    wCell.setValue("Province");
    wCell.setBackground("#ffff00");
    Utilities.sleep(1000);
    wCell.setValue("✅");
    wCell.setBackground("#00ff00");

    VERIFYK_log_(rowNum, provNow, cityNow, brgyNow, String(province_ok), String(city_ok), String(barangay_ok), "WROTE_W_PROVINCE_THEN_CHECKMARK", raw);
  } else {
    wCell.setValue(wVal);
    if (wVal === "✅") wCell.setBackground("#00ff00");
    else wCell.setBackground("#ffff00");

    VERIFYK_log_(rowNum, provNow, cityNow, brgyNow, String(province_ok), String(city_ok), String(barangay_ok), "WROTE_W", raw);
  }

  Utilities.sleep(GPT_SLEEP_MS);
}

function VERIFYK_system_() {
  return [
    "You are a strict-but-practical Philippine address verifier.",
    "You will receive RAW_CUSTOMER_CHAT and CURRENT_PROVINCE/CITY/BARANGAY.",
    "",
    "Output STRICT JSON only with booleans:",
    "{\"province_ok\":true/false,\"city_ok\":true/false,\"barangay_ok\":true/false}"
  ].join("\n");
}

function VERIFYK_buildPrompt_(kTextRaw, provNow, cityNow, brgyNow) {
  return (
    "RAW_CUSTOMER_CHAT:\n<<<\n" + String(kTextRaw || "") + "\n>>>\n\n" +
    "CURRENT_PROVINCE: " + String(provNow || "") + "\n" +
    "CURRENT_CITY: " + String(cityNow || "") + "\n" +
    "CURRENT_BARANGAY: " + String(brgyNow || "") + "\n\n" +
    "Return STRICT JSON only:\n" +
    "{\"province_ok\":true/false,\"city_ok\":true/false,\"barangay_ok\":true/false}\n"
  );
}

function VERIFYK_parseVerifyJSON_(raw) {
  const s = String(raw || "").trim();

  try {
    const obj = JSON.parse(s);
    return {
      province_ok: !!obj.province_ok,
      city_ok: !!obj.city_ok,
      barangay_ok: !!obj.barangay_ok
    };
  } catch (e) {}

  const m = s.match(/\{[\s\S]*\}/);
  if (m) {
    const obj = JSON.parse(m[0]);
    return {
      province_ok: !!obj.province_ok,
      city_ok: !!obj.city_ok,
      barangay_ok: !!obj.barangay_ok
    };
  }

  return { province_ok: false, city_ok: false, barangay_ok: false };
}

function VERIFYK_computeW_(province_ok, city_ok, barangay_ok) {
  if (province_ok && city_ok && barangay_ok) return "✅";

  if (!barangay_ok && city_ok && province_ok) return "Barangay";
  if (barangay_ok && !city_ok && province_ok) return "City";
  if (barangay_ok && city_ok && !province_ok) return "Province";

  if (!barangay_ok && !city_ok && province_ok) return "City and Barangay";
  if (!province_ok && !city_ok && barangay_ok) return "Province and City";
  if (!province_ok && city_ok && !barangay_ok) return "Province and Barangay";

  return "Full Address";
}

function VERIFYK_log_(rowNum, prov, city, brgy, provOk, cityOk, brgyOk, status, raw) {
  const ss = getTargetSS_();
  let sh = ss.getSheetByName(SHEET_VERIFY_LOG);
  if (!sh) sh = ss.insertSheet(SHEET_VERIFY_LOG);

  if (sh.getLastRow() === 0) {
    sh.appendRow([
      "timestamp", "row", "province", "city", "barangay",
      "province_ok", "city_ok", "barangay_ok",
      "status", "raw_response"
    ]);
  }

  sh.appendRow([
    new Date(),
    rowNum,
    prov,
    city,
    brgy,
    provOk,
    cityOk,
    brgyOk,
    status,
    raw
  ]);
}


/**************************************************************
 * ✅ Unified OpenAI caller (Responses API + Chat fallback)
 **************************************************************/
function OPENAI_callStrictJSON_(apiKey, model, systemText, userPrompt) {
  try {
    const url = "https://api.openai.com/v1/responses";
    const payload = {
      model: model,
      temperature: 0,
      input: [
        { role: "system", content: [{ type: "input_text", text: systemText }] },
        { role: "user",   content: [{ type: "input_text", text: userPrompt }] }
      ]
    };

    const res = UrlFetchApp.fetch(url, {
      method: "post",
      contentType: "application/json",
      headers: { Authorization: "Bearer " + apiKey },
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    });

    const code = res.getResponseCode();
    const text = res.getContentText();
    if (code >= 200 && code < 300) {
      const data = JSON.parse(text);
      return BRGYFIX_extractOutputText_(data) || text;
    }
  } catch (e) {}

  const url2 = "https://api.openai.com/v1/chat/completions";
  const payload2 = {
    model: model,
    temperature: 0,
    messages: [
      { role: "system", content: systemText },
      { role: "user", content: userPrompt + "\nReturn JSON only." }
    ]
  };

  const res2 = UrlFetchApp.fetch(url2, {
    method: "post",
    contentType: "application/json",
    headers: { Authorization: "Bearer " + apiKey },
    payload: JSON.stringify(payload2),
    muteHttpExceptions: true
  });

  const code2 = res2.getResponseCode();
  const text2 = res2.getContentText();
  if (!(code2 >= 200 && code2 < 300)) {
    throw new Error("OpenAI API error: " + code2 + " " + text2);
  }

  const data2 = JSON.parse(text2);
  return String(data2.choices?.[0]?.message?.content || "").trim();
}

function BRGYFIX_extractOutputText_(data) {
  if (!data) return "";
  if (typeof data.output_text === "string" && data.output_text.trim()) return data.output_text.trim();

  const out = data.output || [];
  for (const item of out) {
    const contents = item.content || [];
    for (const c of contents) {
      if ((c.type === "output_text" || c.type === "text") && c.text) return String(c.text).trim();
    }
  }
  return "";
}

function BRGYFIX_parseJSON_(raw) {
  const out = { barangay: "", city: "", province: "" };
  const s = String(raw || "").trim();

  try {
    const obj = JSON.parse(s);
    out.barangay = String(obj.barangay || "").trim();
    out.city     = String(obj.city || "").trim();
    out.province = String(obj.province || "").trim();
    return out;
  } catch (e) {}

  const m = s.match(/\{[\s\S]*\}/);
  if (m) {
    const obj = JSON.parse(m[0]);
    out.barangay = String(obj.barangay || "").trim();
    out.city     = String(obj.city || "").trim();
    out.province = String(obj.province || "").trim();
    return out;
  }
  return out;
}

function BRGYFIX_getApiKey_() {
  const ss = getTargetSS_();
  const sh = ss.getSheetByName(SHEET_KEY);
  if (!sh) throw new Error(`Sheet "${SHEET_KEY}" not found`);
  return String(sh.getRange("A1").getValue() || "").trim();
}


/**************************************************************
 * LOG (existing)
 **************************************************************/
function BRGYFIX_log_(rowNum, prov, city, brgyOld, list, brgyNew, raw, status) {
  const ss = getTargetSS_();
  let sh = ss.getSheetByName(SHEET_LOG);
  if (!sh) sh = ss.insertSheet(SHEET_LOG);

  if (sh.getLastRow() === 0) {
    sh.appendRow([
      "timestamp", "row", "province", "city", "barangay(old)",
      "list_len", "barangay(new)", "status", "raw_response"
    ]);
  }

  const listLen = list ? list.split(",").map(s => String(s).trim()).filter(Boolean).length : 0;

  sh.appendRow([
    new Date(),
    rowNum,
    prov,
    city,
    brgyOld,
    listLen,
    brgyNew,
    status,
    raw
  ]);
}


/********************
 * ✅ MODULE EXPORT
 ********************/
return { run: LIKHA2_2 };

})(); // end CHECKER_11_pababa module


/***************************************************************
 * ✅ GLOBAL ENTRY (unique per copy)
 ***************************************************************/
function LIKHA11_1() {
  return CHECKER_11_1.run();
}
