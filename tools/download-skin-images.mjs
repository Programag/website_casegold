#!/usr/bin/env node
/* =========================================================================
   JEDNORAZOWY SKRYPT: pobiera wszystkie obrazki skinów z zewnętrznych
   linków (cdn.g4skins.com, Steam CDN, casesimulator.eu) do folderu
   skins/ i podmienia linki w skin-images.js na lokalne ścieżki - żeby
   strona przestała zależeć od zewnętrznych serwerów przy każdym wejściu.

   URUCHOMIENIE (z katalogu głównego repo, wymaga Node.js 18+ i internetu):
     node tools/download-skin-images.mjs

   Środowisko, w którym to piszę, ma zablokowany dostęp sieciowy do tych
   domen (polityka egress proxy) - stąd ten skrypt jest do uruchomienia
   przez Ciebie lokalnie, nie przeze mnie tutaj.

   Co robi:
   1. Wczytuje SKIN_IMAGES ze skin-images.js (bezpiecznie, przez Function -
      to statyczny obiekt danych, nie kod wykonujący cokolwiek niebezpiecznego).
   2. Dla każdego UNIKALNEGO linku (wiele kluczy weapon|skin|wear potrafi
      wskazywać na ten sam URL) pobiera obrazek do skins/<slug>.<ext>,
      gdzie <ext> jest ustalane z nagłówka Content-Type odpowiedzi.
   3. Zapisuje NOWĄ wersję skin-images.js z lokalnymi ścieżkami zamiast
      external URL-i - wpisy, których pobieranie się nie powiodło (404,
      timeout, itd.), zostają z oryginalnym URL-em jako fallback, żeby
      jedno zepsute źródło nie wybiło całej strony.
   4. Zapisuje raport (tools/download-skin-images-report.json) z listą
      sukcesów/porażek do przejrzenia.
   ========================================================================= */

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, "..");
const SKIN_IMAGES_PATH = path.join(REPO_ROOT, "skin-images.js");
const OUTPUT_DIR = path.join(REPO_ROOT, "skins");
const REPORT_PATH = path.join(__dirname, "download-skin-images-report.json");

const CONCURRENCY = 8;
const TIMEOUT_MS = 20000;
const MAX_RETRIES = 2;

function loadSkinImages() {
  const src = fs.readFileSync(SKIN_IMAGES_PATH, "utf8");
  const match = src.match(/const SKIN_IMAGES = (\{[\s\S]*?\n\});/);
  if (!match) throw new Error("Nie znaleziono SKIN_IMAGES w skin-images.js - format pliku się zmienił?");
  // eslint-disable-next-line no-new-func
  const obj = new Function(`return (${match[1]});`)();
  return { src, objLiteral: match[1], obj };
}

function slugify(key) {
  return key
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 80);
}

function extFromContentType(ct) {
  if (!ct) return null;
  if (ct.includes("png")) return "png";
  if (ct.includes("webp")) return "webp";
  if (ct.includes("jpeg") || ct.includes("jpg")) return "jpg";
  if (ct.includes("gif")) return "gif";
  return null;
}

function extFromUrl(url) {
  const m = url.match(/\.(png|webp|jpe?g|gif)(?:\?|$)/i);
  return m ? m[1].toLowerCase().replace("jpeg", "jpg") : null;
}

async function downloadOne(url, destBase) {
  for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
      const res = await fetch(url, { signal: controller.signal, headers: { "User-Agent": "Mozilla/5.0 (compatible; skin-image-downloader/1.0)" } });
      clearTimeout(timer);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const ct = res.headers.get("content-type") || "";
      const ext = extFromContentType(ct) || extFromUrl(url) || "png";
      const buf = Buffer.from(await res.arrayBuffer());
      if (buf.length < 100) throw new Error("odpowiedź podejrzanie mała, prawdopodobnie nie jest to obrazek");
      const destPath = `${destBase}.${ext}`;
      fs.writeFileSync(path.join(OUTPUT_DIR, destPath), buf);
      return { ok: true, localPath: `skins/${destPath}`, bytes: buf.length };
    } catch (e) {
      if (attempt === MAX_RETRIES) return { ok: false, error: e.message };
      await new Promise((r) => setTimeout(r, 500 * (attempt + 1)));
    }
  }
}

async function main() {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });
  const { src, obj } = loadSkinImages();

  const entries = Object.entries(obj); // [key, url][]
  const urlToKeys = new Map();
  for (const [key, url] of entries) {
    if (!/^https?:\/\//.test(url)) continue; // already local (np. karambit.PNG) - pomiń
    if (!urlToKeys.has(url)) urlToKeys.set(url, []);
    urlToKeys.get(url).push(key);
  }

  const uniqueUrls = [...urlToKeys.keys()];
  console.log(`Znaleziono ${entries.length} wpisów, ${uniqueUrls.length} unikalnych linków do pobrania.`);

  const results = new Map(); // url -> {ok, localPath?, error?}
  let done = 0;
  let queueIdx = 0;
  async function worker() {
    while (queueIdx < uniqueUrls.length) {
      const idx = queueIdx++;
      const url = uniqueUrls[idx];
      const canonicalKey = urlToKeys.get(url)[0];
      const destBase = slugify(canonicalKey) || `img-${idx}`;
      const result = await downloadOne(url, destBase);
      results.set(url, result);
      done++;
      if (done % 25 === 0 || done === uniqueUrls.length) {
        console.log(`  ${done}/${uniqueUrls.length}...`);
      }
    }
  }
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));

  const succeeded = [...results.values()].filter((r) => r.ok).length;
  const failed = [...results.values()].filter((r) => !r.ok);
  console.log(`\nPobrano: ${succeeded}/${uniqueUrls.length}. Nieudane: ${failed.length}.`);

  // zbuduj nowy obiekt SKIN_IMAGES: dla powodzenia -> lokalna ścieżka,
  // dla porażki -> oryginalny URL (fallback), dla już-lokalnych -> bez zmian
  const newObj = {};
  for (const [key, url] of entries) {
    if (!/^https?:\/\//.test(url)) { newObj[key] = url; continue; }
    const r = results.get(url);
    newObj[key] = r && r.ok ? r.localPath : url;
  }

  const lines = Object.entries(newObj).map(([k, v]) => `  ${JSON.stringify(k)}: ${JSON.stringify(v)},`);
  const newObjLiteral = `{\n${lines.join("\n")}\n}`;
  const newSrc = src.replace(/const SKIN_IMAGES = \{[\s\S]*?\n\};/, `const SKIN_IMAGES = ${newObjLiteral};`);
  fs.writeFileSync(SKIN_IMAGES_PATH, newSrc);
  console.log(`\nZapisano zaktualizowany ${SKIN_IMAGES_PATH}`);

  const report = {
    totalEntries: entries.length,
    uniqueUrls: uniqueUrls.length,
    succeeded,
    failedCount: failed.length,
    failed: [...results.entries()].filter(([, r]) => !r.ok).map(([url, r]) => ({ url, error: r.error, keys: urlToKeys.get(url) })),
  };
  fs.writeFileSync(REPORT_PATH, JSON.stringify(report, null, 2));
  console.log(`Raport zapisany w ${REPORT_PATH}`);
  if (failed.length > 0) {
    console.log(`\nUWAGA: ${failed.length} obrazków nie udało się pobrać - zostały z oryginalnym linkiem (fallback). Zobacz raport po szczegóły.`);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
