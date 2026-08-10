// Vercel Serverless Function (Node.js) for Student Results System
// مسار: api/index.js

const SPREADSHEET_ID = process.env.SPREADSHEET_ID || "ضع_معرف_شيت_جوجل_هنا";

export default async function handler(req, res) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Content-Type', 'application/json; charset=utf-8');

    const { action, id, sheetName } = req.query;

    const sheetsMap = await getSheetsFromGoogle(SPREADSHEET_ID);

    if (action === 'getSheets') {
        const sheetNames = Object.keys(sheetsMap);
        return res.status(200).json(sheetNames.length ? sheetNames : ["الصف الأول"]);
    }

    if (action === 'search') {
        const studentId = id ? id.trim() : '';

        if (!studentId) {
            return res.status(400).json({ error: 'الرجاء إدخال رقم الطالب / الجلوس.' });
        }

        if (!SPREADSHEET_ID || SPREADSHEET_ID === "ضع_معرف_جدول_البيانات_هنا") {
            return res.status(500).json({ error: 'لم يتم ضبط معرف جدول البيانات SPREADSHEET_ID.' });
        }

        let gid = "0";
        if (sheetName && sheetsMap[sheetName]) {
            gid = sheetsMap[sheetName];
        } else if (Object.keys(sheetsMap).length > 0) {
            gid = Object.values(sheetsMap)[0];
        }

        const csvUrl = buildCsvUrl(SPREADSHEET_ID, gid);

        try {
            let response = await fetch(csvUrl);
            if (!response.ok) {
                const fallbackUrl = `https://docs.google.com/spreadsheets/d/${SPREADSHEET_ID}/export?format=csv&gid=${gid}`;
                response = await fetch(fallbackUrl);
            }

            if (!response.ok) {
                return res.status(500).json({ error: 'تعذر الاتصال بجدول البيانات من جوجل.' });
            }

            let csvText = await response.text();
            csvText = csvText.replace(/^\uFEFF/, '');

            const lines = csvText.split(/\r?\n/).filter(line => line.trim() !== '');

            if (lines.length < 2) {
                return res.status(200).json({ message: 'لا توجد بيانات في الورقة المحددة.' });
            }

            // 1. عناوين الصف الأول
            const headers = parseCSVLine(lines[0]).map(h => h.trim());
            let foundStudent = null;

            // 2. البحث المحصور في العمود الأول فقط row[0]
            for (let i = 1; i < lines.length; i++) {
                const row = parseCSVLine(lines[i]);
                const firstColumnVal = row[0] ? row[0].trim() : '';

                if (firstColumnVal === studentId) {
                    foundStudent = {};
                    headers.forEach((header, idx) => {
                        if (header !== '') {
                            foundStudent[header] = row[idx] ? row[idx].trim() : '';
                        }
                    });
                    break;
                }
            }

            if (foundStudent) {
                return res.status(200).json(foundStudent);
            } else {
                return res.status(200).json({ message: 'لم يتم العثور على نتيجة لهذا الرقم.' });
            }

        } catch (err) {
            return res.status(500).json({ error: 'حدث خطأ في السيرفر أثناء قراءة البيانات.' });
        }
    }

    return res.status(400).json({ error: 'إجراء غير صالح.' });
}

async function getSheetsFromGoogle(spreadsheetInput) {
    const pubUrl = buildPubHtmlUrl(spreadsheetInput);
    const sheets = {};
    try {
        const response = await fetch(pubUrl);
        if (response.ok) {
            const html = await response.text();
            const regex = /<li\s+id="sheet-button-([0-9]+)"[^>]*><a[^>]*>(.*?)<\/a>/gi;
            let match;
            while ((match = regex.exec(html)) !== null) {
                const gid = match[1];
                const name = match[2].replace(/<[^>]*>/g, '').trim();
                if (name) {
                    sheets[name] = gid;
                }
            }
        }
    } catch (e) {
        console.error("Error fetching sheets html", e);
    }

    if (Object.keys(sheets).length === 0) {
        sheets["الورقة الرئيسية"] = "0";
    }
    return sheets;
}

function buildPubHtmlUrl(input) {
    input = input.trim();
    if (input.includes('2PACX-') || input.includes('/pub')) {
        const match = input.match(/2PACX-[a-zA-Z0-9_-]+/);
        if (match) {
            return `https://docs.google.com/spreadsheets/d/e/${match[0]}/pubhtml`;
        }
    }
    const sheetMatch = input.match(/\/d\/([a-zA-Z0-9_-]+)/);
    if (sheetMatch) {
        return `https://docs.google.com/spreadsheets/d/${sheetMatch[1]}/pubhtml`;
    }
    if (input.startsWith('2PACX-')) {
        return `https://docs.google.com/spreadsheets/d/e/${input}/pubhtml`;
    }
    return `https://docs.google.com/spreadsheets/d/${input}/pubhtml`;
}

function buildCsvUrl(input, gid = "0") {
    input = input.trim();
    if (input.includes('2PACX-') || input.includes('/pub')) {
        const match = input.match(/2PACX-[a-zA-Z0-9_-]+/);
        if (match) {
            return `https://docs.google.com/spreadsheets/d/e/${match[0]}/pub?output=csv&gid=${gid}`;
        }
    }
    const sheetMatch = input.match(/\/d\/([a-zA-Z0-9_-]+)/);
    if (sheetMatch) {
        return `https://docs.google.com/spreadsheets/d/${sheetMatch[1]}/export?format=csv&gid=${gid}`;
    }
    if (input.startsWith('2PACX-')) {
        return `https://docs.google.com/spreadsheets/d/e/${input}/pub?output=csv&gid=${gid}`;
    }
    return `https://docs.google.com/spreadsheets/d/${input}/export?format=csv&gid=${gid}`;
}

function parseCSVLine(text) {
    const result = [];
    let cell = '';
    let inQuotes = false;

    for (let i = 0; i < text.length; i++) {
        const char = text[i];
        if (char === '"') {
            inQuotes = !inQuotes;
        } else if (char === ',' && !inQuotes) {
            result.push(cell);
            cell = '';
        } else {
            cell += char;
        }
    }
    result.push(cell);
    return result;
}
