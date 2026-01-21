import fs from "fs";
import path from "path";
import * as pdfjsLib from "pdfjs-dist/legacy/build/pdf.mjs";
import { PDFDocument } from "pdf-lib";

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function writeStatus(statusPath, patch) {
    let cur = {};
    try { cur = JSON.parse(fs.readFileSync(statusPath, "utf8")); } catch { }
    const merged = { ...cur, ...patch };
    fs.writeFileSync(statusPath, JSON.stringify(merged, null, 2));
}

function normalizeItemKey(item) {
    const x = String(item || "").trim();
    if (!x) return "UNKNOWN";
    return x.toUpperCase().replace(/\s+/g, " ").slice(0, 160);
}

function extractWaybill(text) {
    const m = String(text || "").match(/\bJT\d{10,20}\b/g);
    return (m && m[0]) ? m[0] : "";
}

function extractItem(text) {
    const t = String(text || "").replace(/\s+/g, " ").trim();

    const r1 = /(\d{1,3})\s*[xX]\s*([A-Za-z0-9][A-Za-z0-9\s\-\/\(\)&]+?)(?=\s+(Piece:|Weight:|Goods:|Pouch Size:|Remarks|JT\d{10,20}\b|$))/;
    let m = t.match(r1);
    if (m) return { itemKey: normalizeItemKey(m[2]), qty: parseInt(m[1], 10) || 0 };

    const r2 = /(\d{1,3})\s*[xX]\s*([A-Za-z0-9][A-Za-z0-9\s\-\/\(\)&]{2,})/;
    m = t.match(r2);
    if (m) return { itemKey: normalizeItemKey(String(m[2] || "").trim().slice(0, 90)), qty: parseInt(m[1], 10) || 0 };

    return { itemKey: "UNKNOWN", qty: 0 };
}

function safeFileName(s) {
    return String(s || "UNKNOWN")
        .trim()
        .replace(/[\/\\?%*:|"<>]/g, "-")
        .replace(/\s+/g, " ")
        .slice(0, 120);
}

async function main() {
    const inputRaw = await new Promise((resolve) => {
        let buf = "";
        process.stdin.setEncoding("utf8");
        process.stdin.on("data", (d) => buf += d);
        process.stdin.on("end", () => resolve(buf));
    });

    const payload = JSON.parse(inputRaw || "{}");
    const files = payload.files || [];
    const outDir = payload.outDir;
    const statusPath = payload.statusPath;

    if (!files.length) throw new Error("No files provided.");
    if (!outDir) throw new Error("outDir missing.");

    const itemsDir = path.join(outDir, "items");
    fs.mkdirSync(itemsDir, { recursive: true });

    writeStatus(statusPath, { message: "Reading PDFs...", progress: 5 });

    // Pass 1: count total pages
    let totalPages = 0;
    const docs = [];
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const data = new Uint8Array(fs.readFileSync(file));
        const pdf = await pdfjsLib.getDocument({ data }).promise;
        docs.push({ file, data, pdf, numPages: pdf.numPages });
        totalPages += pdf.numPages;

        writeStatus(statusPath, { message: `Counting pages: ${i + 1}/${files.length}`, progress: Math.min(10, 5 + (i + 1)) });
        await sleep(0);
    }

    writeStatus(statusPath, { message: `Processing 0/${totalPages} pages`, progress: 12 });

    // Extract per page
    let donePages = 0;
    const pageRecords = []; // {fileIndex, fileName, pageNum, waybill, itemKey, pageIndex0}
    for (let i = 0; i < docs.length; i++) {
        const { file, data, pdf, numPages } = docs[i];
        const fileName = path.basename(file);

        for (let p = 1; p <= numPages; p++) {
            const page = await pdf.getPage(p);
            const content = await page.getTextContent();
            const text = (content.items || []).map(it => it.str).join(" ");

            const wb = extractWaybill(text);
            const it = extractItem(text);

            pageRecords.push({
                fileIndex: i,
                fileName,
                filePath: file,
                pageNum: p,
                pageIndex0: p - 1,
                waybill: wb,
                itemKey: it.itemKey,
            });

            donePages++;
            const progress = Math.round(12 + (donePages / totalPages) * 70);
            writeStatus(statusPath, {
                message: `Processing: ${donePages}/${totalPages} pages`,
                progress,
            });

            await sleep(0);
        }
    }

    // Group by item
    const groupsMap = new Map();
    for (const r of pageRecords) {
        if (!groupsMap.has(r.itemKey)) groupsMap.set(r.itemKey, []);
        groupsMap.get(r.itemKey).push(r);
    }

    // Build per-item PDFs
    writeStatus(statusPath, { message: "Building per-item PDFs...", progress: 85 });

    // Cache loaded source PDFs (pdf-lib)
    const srcCache = new Map(); // filePath -> PDFDocument
    const groupList = Array.from(groupsMap.entries()).sort((a, b) => b[1].length - a[1].length);

    for (let gi = 0; gi < groupList.length; gi++) {
        const [itemKey, pages] = groupList[gi];
        const outPdf = await PDFDocument.create();

        // maintain original order
        pages.sort((a, b) => (a.fileIndex - b.fileIndex) || (a.pageNum - b.pageNum));

        for (const pg of pages) {
            if (!srcCache.has(pg.filePath)) {
                const bytes = fs.readFileSync(pg.filePath);
                srcCache.set(pg.filePath, await PDFDocument.load(bytes));
            }
            const srcPdf = srcCache.get(pg.filePath);
            const [copied] = await outPdf.copyPages(srcPdf, [pg.pageIndex0]);
            outPdf.addPage(copied);
        }

        const bytes = await outPdf.save();
        const outName = safeFileName(itemKey) + ".pdf";
        fs.writeFileSync(path.join(itemsDir, outName), bytes);

        const progress = Math.min(95, 85 + Math.round(((gi + 1) / groupList.length) * 10));
        writeStatus(statusPath, { message: `Building PDFs: ${gi + 1}/${groupList.length}`, progress });
        await sleep(0);
    }

    // Summary structure for PHP
    const groups = groupList.map(([item, pages]) => {
        const waybills = Array.from(new Set(pages.map(x => x.waybill).filter(Boolean)));
        return {
            item,
            pages: pages.length,
            unique_waybills: waybills.length,
            waybill_list: waybills.join(" "),
        };
    });

    const unknownPages = (groupsMap.get("UNKNOWN") || []).length;

    const summary = {
        totalFiles: files.length,
        totalPages,
        unknownPages,
        groups,
    };

    fs.writeFileSync(path.join(outDir, "summary.json"), JSON.stringify(summary, null, 2));

    writeStatus(statusPath, { message: "Node done. Finalizing in PHP...", progress: 98 });
}

main()
    .then(() => process.exit(0))
    .catch((e) => {
        console.error(e);
        process.exit(1);
    });
