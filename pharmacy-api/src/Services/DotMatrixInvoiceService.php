<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Retail sale bill for dot matrix (impact) printers: fixed 80-column text.
 * The page offers Direct Print (raw ESC/P via QZ Tray), .prn download and browser print.
 */
final class DotMatrixInvoiceService
{
    private const WIDTH = 80;
    private const LEFT = 46;

    /** Item grid slots — header and rows share them so columns always line up. */
    private const COLS = ['sr' => 4, 'name' => 28, 'mfg' => 10, 'batch' => 13, 'exp' => 7, 'qty' => 7, 'amt' => 11];

    /**
     * @param array<string,mixed> $sale
     * @param list<array<string,mixed>> $items enriched sale items
     * @param array<string,mixed> $branch
     * @param array<string,mixed> $client
     */
    public function page(array $sale, array $items, array $branch, array $client, float $net, bool $isWholesale): string
    {
        $billNo = trim((string) ($sale['invoice_no'] ?? '')) ?: ('#' . (int) ($sale['id'] ?? 0));
        $text = $this->billText($sale, $items, $branch, $client, $net, $isWholesale);
        return $this->html($text, $billNo);
    }

    /**
     * @param array<string,mixed> $sale
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $branch
     * @param array<string,mixed> $client
     */
    private function billText(array $sale, array $items, array $branch, array $client, float $net, bool $isWholesale): string
    {
        $pick = static function (array $keys, array ...$sources): string {
            foreach ($sources as $src) {
                foreach ($keys as $k) {
                    $v = trim((string) ($src[$k] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
            return '';
        };

        $shopName = mb_strtoupper($pick(['business_name', 'name'], $client, $branch)) ?: 'PHARMACY';
        $shopAddr = trim(implode(', ', array_filter([
            $pick(['address'], $branch, $client),
            trim($pick(['city'], $branch, $client) . ' ' . $pick(['pincode'], $branch, $client)),
        ])), ', ');
        $shopPhone = $pick(['phone'], $client, $branch);
        $gst = $pick(['gstin'], $client, $branch);
        $dl = $pick(['drug_license_no'], $client, $branch);
        $jurisdiction = $pick(['city'], $branch, $client) ?: $pick(['state'], $branch, $client);

        $patient = trim((string) (($sale['patient_name'] ?? '') ?: ($sale['customer_name'] ?? '')));
        $phone = trim((string) ($sale['contact_no'] ?? '')) ?: '-';
        $doctor = trim((string) ($sale['doctor_name'] ?? ''));
        if ($doctor === '' || strcasecmp($doctor, 'NA') === 0) {
            $doctor = '-';
        }
        $billNo = trim((string) ($sale['invoice_no'] ?? ''));
        $billDate = !empty($sale['invoice_date']) ? date('d-m-Y', strtotime((string) $sale['invoice_date'])) : '';
        $memo = mb_strtoupper(trim((string) ($sale['payment_mode'] ?? ''))) ?: '-';

        $W = self::WIDTH;
        $L = self::LEFT;
        $R = $W - $L;
        $c = self::COLS;
        $rule = str_repeat('-', $W);
        $lines = [];

        $lines[] = $this->pad($shopName, $W, 'C');
        foreach ($this->wrap(mb_strtoupper($shopAddr), $W) as $al) {
            $lines[] = $this->pad($al, $W, 'C');
        }
        if ($shopPhone !== '') {
            $lines[] = $this->pad('Phone No:' . $shopPhone, $W, 'C');
        }
        $lines[] = $rule;

        $lines[] = $this->pad(($isWholesale ? 'CUSTOMER:' : 'PATIENT:') . $patient, $L) . $this->pad('BILL NO:' . $billNo, $R, 'R');
        $lines[] = $this->pad('Ph:-' . $phone, $L) . $this->pad('DATE:' . $billDate, $R, 'R');
        $lines[] = $this->pad('DOCTOR :' . $doctor, $L) . $this->pad('MEMO:' . $memo, $R, 'R');
        $lines[] = $rule;

        $lines[] = $this->pad('SR.', $c['sr'])
            . $this->pad('MEDICINE NAME', $c['name'])
            . $this->pad('MFG', $c['mfg'])
            . $this->pad('BATCH NO', $c['batch'])
            . $this->pad('EX.DT', $c['exp'])
            . $this->pad('QTY', $c['qty'], 'R')
            . $this->pad('AMOUNT', $c['amt'], 'R');

        foreach ($items as $i => $it) {
            $name = trim((string) ($it['product_name'] ?? ''));
            if ($this->isNarcotic((string) ($it['schedule_category'] ?? ''))) {
                $name .= ' (N)';
            }
            $mfg = mb_strtoupper(trim((string) ($it['manufacturer_short'] ?? ''))) ?: '*****';
            $batch = trim((string) ($it['batch_no'] ?? ''));
            $exp = !empty($it['expiry_date']) ? date('m/y', strtotime((string) $it['expiry_date'])) : '00/00';
            $qty = (float) ($it['display_qty'] ?? $it['quantity'] ?? 0);
            $qtyText = abs($qty - round($qty)) < 0.001
                ? (string) (int) round($qty)
                : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $amt = number_format((float) ($it['line_total'] ?? $it['display_net'] ?? 0), 2, '.', '');

            $parts = $this->wrap($name, $c['name']) ?: [''];
            foreach ($parts as $n => $part) {
                $lines[] = $n === 0
                    ? $this->pad((string) ($i + 1), $c['sr'])
                        . $this->pad($part, $c['name'])
                        . $this->pad($mfg, $c['mfg'])
                        . $this->pad($batch, $c['batch'])
                        . $this->pad($exp, $c['exp'])
                        . $this->pad($qtyText, $c['qty'], 'R')
                        . $this->pad($amt, $c['amt'], 'R')
                    : $this->pad('', $c['sr']) . $this->pad($part, $c['name']);
            }
        }
        $lines[] = $rule;

        $footParts = array_values(array_filter([$dl !== '' ? 'Dl :' . $dl : '', $gst !== '' ? 'GST No:' . $gst : '']));
        $joined = implode(' ', $footParts);
        $foot = mb_strlen($joined) <= $L
            ? [$joined]
            : array_merge(...array_map(fn (string $p) => $this->wrap($p, $L), $footParts));
        $foot = $foot ?: [''];
        $lines[] = $this->pad($foot[0], $L) . $this->pad('AMT:  ' . number_format($net, 2, '.', ''), $R, 'R');
        for ($i = 1, $n = count($foot); $i < $n; $i++) {
            $lines[] = $this->pad($foot[$i], $W);
        }
        $jur = $jurisdiction !== '' ? 'SUBJECT TO ' . mb_strtoupper($jurisdiction) . ' JURISDICTION' : '';
        $lines[] = $this->pad($jur, $L) . $this->pad('Authorized Signatory', $R, 'R');

        return implode("\n", array_map('rtrim', $lines));
    }

    /** Retail marks Schedule H / H1 / NDPS / narcotic lines with (N). */
    private function isNarcotic(string $schedule): bool
    {
        $s = mb_strtoupper(trim($schedule));
        return $s !== '' && (bool) preg_match('/\b(H1?|NDPS|NARC)/', $s);
    }

    private function cut(string $s, int $n): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > $n ? mb_substr($s, 0, max(0, $n)) : $s;
    }

    private function pad(string $s, int $n, string $align = 'L'): string
    {
        $s = $this->cut($s, $n);
        $gap = max(0, $n - mb_strlen($s));
        return match ($align) {
            'R' => str_repeat(' ', $gap) . $s,
            'C' => str_repeat(' ', intdiv($gap, 2)) . $s . str_repeat(' ', $gap - intdiv($gap, 2)),
            default => $s . str_repeat(' ', $gap),
        };
    }

    /** @return list<string> word-wrapped lines; words longer than $n are split. */
    private function wrap(string $s, int $n): array
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '' || $n <= 0) {
            return [];
        }
        $out = [];
        $line = '';
        foreach (explode(' ', $s) as $word) {
            while (mb_strlen($word) > $n) {
                if ($line !== '') {
                    $out[] = $line;
                    $line = '';
                }
                $out[] = mb_substr($word, 0, $n);
                $word = mb_substr($word, $n);
            }
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($try) > $n) {
                $out[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $out[] = $line;
        }
        return $out;
    }

    private function html(string $billText, string $billNo): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $fileBase = 'bill-' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $billNo), '-');

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dot Matrix Print ' . $e($billNo) . '</title>
<style>
  :root { --w: 9.5in; --h: 0; --ml: 0.64in; --mr: 0.38in; --mt: 0.2in; --mb: 0.3in; --font: 10pt; --lh: 12pt; --weight: 700; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #e2e8f0; color: #0f172a; }
  .bar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px;
    padding: 10px 14px; background: #0f766e; color: #fff; font: 13px "Segoe UI", Arial, sans-serif; }
  .bar strong { font-size: 14px; margin-right: 4px; }
  .bar label { display: flex; align-items: center; gap: 5px; white-space: nowrap; }
  .bar select { font: inherit; padding: 3px 6px; border-radius: 6px; border: 1px solid #99f6e4; color: #0f172a; }
  .bar button { font: inherit; padding: 5px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,.6);
    background: transparent; color: #fff; cursor: pointer; }
  .bar button:hover { background: rgba(255,255,255,.12); }
  .bar button.primary { background: #fff; color: #0f766e; border-color: #fff; font-weight: 700; }
  .status { flex: 1 1 100%; font-size: 12px; opacity: .92; }
  .status.err { color: #fecaca; opacity: 1; }
  .wrap { display: flex; justify-content: center; padding: 18px; overflow-x: auto; }
  .sheet { width: var(--w); min-height: var(--h); padding: var(--mt) var(--mr) var(--mb) var(--ml); background: #fffef8;
    box-shadow: 0 4px 18px rgba(15,23,42,.18); border-left: 9px dotted #cbd5e1; border-right: 9px dotted #cbd5e1; }
  pre { margin: 0; white-space: pre; overflow: hidden; color: #000; letter-spacing: 0; font-kerning: none;
    font-variant-ligatures: none; font-family: "Courier New", Courier, "Lucida Console", monospace;
    font-size: var(--font); line-height: var(--lh); font-weight: var(--weight); -webkit-font-smoothing: none; }
  @media print {
    html, body { background: #fff; }
    .bar { display: none; }
    .wrap { display: block; padding: 0; }
    .sheet { box-shadow: none; border: 0; min-height: 0; background: #fff; }
  }
</style>
<style id="page-size">@page { size: 9.5in 11in; margin: 0; }</style>
</head>
<body>
<div class="bar">
  <strong>Dot Matrix Print</strong>
  <label>Paper <select id="paper">
    <option value="roll95">Continuous 9.5&quot; — fit to bill</option>
    <option value="roll10">Continuous 10&quot; — fit to bill</option>
    <option value="roll8">Continuous 8&quot; — fit to bill</option>
    <option value="roll150">Continuous 15 cm — fit to bill (15 CPI)</option>
    <option value="80x6">80 Col — 9.5&quot; × 6&quot;</option>
    <option value="80x8">80 Col — 9.5&quot; × 8&quot;</option>
    <option value="80x11">80 Col — 9.5&quot; × 11&quot;</option>
    <option value="80x12">80 Col — 9.5&quot; × 12&quot;</option>
    <option value="10x12">10&quot; × 12&quot;</option>
    <option value="132x11">132 Col — 15&quot; × 11&quot;</option>
    <option value="a4">A4</option>
    <option value="a5">A5</option>
  </select></label>
  <label>Size <select id="cpi">
    <option value="10">10 CPI (large)</option>
    <option value="12">12 CPI (80-col)</option>
    <option value="15">15 CPI (compact)</option>
    <option value="17">17 CPI (narrow)</option>
  </select></label>
  <label>Darkness <select id="weight">
    <option value="700">Bold</option>
    <option value="400">Normal</option>
  </select></label>
  <label>Line space <select id="lpi">
    <option value="6">6 LPI</option>
    <option value="8">8 LPI</option>
  </select></label>
  <label>Left shift <select id="shift">
    <option value="0">0</option><option value="2">+2 mm</option><option value="4">+4 mm</option>
    <option value="6">+6 mm</option><option value="8">+8 mm</option><option value="10">+10 mm</option><option value="12">+12 mm</option>
  </select></label>
  <label>Quality <select id="quality">
    <option value="nlq">NLQ (clearest)</option>
    <option value="draft">Draft (fast)</option>
  </select></label>
  <label>After bill <select id="eject">
    <option value="tear">Feed to tear-off</option>
    <option value="ff">Eject page</option>
    <option value="none">Stop</option>
  </select></label>
  <label>Printer <select id="printer"><option value="">— Find printers —</option></select></label>
  <button type="button" id="btnFind">Find</button>
  <button type="button" class="primary" id="btnDirect">Direct Print</button>
  <button type="button" id="btnPrn">Download .prn</button>
  <button type="button" id="btnBrowser">Browser Print</button>
  <div class="status" id="status">Direct Print sends plain text in the printer&#39;s own font (sharp and fast). It needs QZ Tray installed once on this PC (qz.io/download). Browser Print sends an image, which prints faint on dot matrix.</div>
</div>
<div class="wrap"><div class="sheet" id="sheet"><pre id="bill">' . $e($billText) . '</pre></div></div>
<script>
(function () {
  "use strict";
  var FILE = ' . json_encode($fileBase) . ';
  var PAPERS = {
    roll95: ["9.5in", "auto", "0.20in", "0.30in", "0.38in"], roll10: ["10in", "auto", "0.20in", "0.30in", "0.45in"],
    roll8: ["8in", "auto", "0.20in", "0.30in", "0.30in"], "80x6": ["9.5in", "6in", "0.18in", "0.22in", "0.38in"],
    "80x8": ["9.5in", "8in", "0.22in", "0.26in", "0.38in"], "80x11": ["9.5in", "11in", "0.28in", "0.32in", "0.40in"],
    "80x12": ["9.5in", "12in", "0.30in", "0.35in", "0.40in"], "10x12": ["10in", "12in", "0.30in", "0.35in", "0.45in"],
    "132x11": ["15in", "11in", "0.30in", "0.35in", "0.50in"], a4: ["210mm", "297mm", "8mm", "10mm", "10mm"],
    a5: ["148mm", "210mm", "6mm", "8mm", "8mm"],
    /* [width, height, top, bottom, right, base left (in), smallest CPI that fits 80 columns] */
    roll150: ["150mm", "auto", "0.20in", "0.30in", "0.12in", 0.12, 15]
  };
  var FONT = { "10": "12pt", "12": "10pt", "15": "8pt", "17": "7pt" };
  var LINE = { "6": "12pt", "8": "9pt" };
  var DEFAULTS = { paper: "roll95", cpi: "12", weight: "700", lpi: "6", shift: "6", quality: "nlq", eject: "tear" };
  var $ = function (id) { return document.getElementById(id); };
  var root = document.documentElement, bill = $("bill"), sheet = $("sheet"), statusEl = $("status"), printerEl = $("printer");

  function load(key) { try { return localStorage.getItem("ap_dm_" + key); } catch (e) { return null; } }
  function save(key, v) { try { localStorage.setItem("ap_dm_" + key, v); } catch (e) {} }
  function setStatus(msg, err) { statusEl.textContent = msg; statusEl.className = "status" + (err ? " err" : ""); }
  function inches(v) { var n = parseFloat(v) || 0; return /mm$/.test(v) ? n / 25.4 : n; }
  function paper() { return PAPERS[$("paper").value] || PAPERS.roll95; }

  Object.keys(DEFAULTS).forEach(function (k) {
    var el = $(k), v = load(k);
    el.value = v !== null && Array.prototype.some.call(el.options, function (o) { return o.value === v; }) ? v : DEFAULTS[k];
    el.addEventListener("change", function () { save(k, el.value); apply(); });
  });
  var savedPrinter = load("printer");
  if (savedPrinter) { printerEl.add(new Option(savedPrinter, savedPrinter, true, true)); }
  printerEl.addEventListener("change", function () { save("printer", printerEl.value); });

  /* @page ignores CSS variables, so the size is written here. Continuous paper uses the bill length. */
  function pageSize() {
    var p = paper(), h = p[1];
    if (h === "auto") { h = (bill.getBoundingClientRect().height / 96 + inches(p[2]) + inches(p[3]) + 0.05).toFixed(2) + "in"; }
    $("page-size").textContent = "@page { size: " + p[0] + " " + h + "; margin: 0; }";
  }

  function apply() {
    var p = paper(), shift = parseInt($("shift").value, 10) || 0;
    if (p[6] && (parseInt($("cpi").value, 10) || 12) < p[6]) {
      $("cpi").value = String(p[6]);
      save("cpi", $("cpi").value);
      setStatus("15 cm paper: print size set to " + p[6] + " CPI so all 80 columns fit on the paper.");
    }
    root.style.setProperty("--w", p[0]);
    root.style.setProperty("--h", p[1] === "auto" ? "0" : p[1]);
    root.style.setProperty("--mt", p[2]);
    root.style.setProperty("--mb", p[3]);
    root.style.setProperty("--mr", p[4]);
    root.style.setProperty("--ml", ((p[5] != null ? p[5] : 0.40) + shift / 25.4).toFixed(3) + "in");
    root.style.setProperty("--font", FONT[$("cpi").value] || "10pt");
    root.style.setProperty("--lh", LINE[$("lpi").value] || "12pt");
    root.style.setProperty("--weight", $("weight").value);
    pageSize();
  }

  /* Epson ESC/P — works on 9-pin and 24-pin printers (Epson LX/FX, TVS MSP, Wipro, OKI…). */
  function escp() {
    var ESC = "\\x1B", cpi = parseInt($("cpi").value, 10) || 12, p = paper();
    var out = ESC + "@" + ESC + "x" + ($("quality").value === "nlq" ? "\\x01" : "\\x00");
    out += cpi === 10 ? ESC + "P" : cpi === 12 ? ESC + "M" : cpi === 15 ? ESC + "g" : ESC + "P\\x0F";
    out += $("lpi").value === "8" ? ESC + "0" : ESC + "2";
    if ($("weight").value === "700") { out += ESC + "E" + ESC + "G"; }
    var printable = p[5] != null ? inches(p[0]) - 0.3 : Math.max(4, inches(p[0]) - 1.5);
    var room = Math.floor(printable * cpi) - 80;
    var margin = Math.max(0, Math.min(Math.round(((parseInt($("shift").value, 10) || 0) / 25.4) * cpi), room));
    out += ESC + "l" + String.fromCharCode(margin);
    bill.textContent.replace(/\\r/g, "").split("\\n").forEach(function (line) {
      var s = line.normalize ? line.normalize("NFD").replace(/[\\u0300-\\u036f]/g, "") : line;
      out += s.replace(/\\u20B9/g, "Rs").replace(/[^\\x20-\\x7E]/g, "?").replace(/\\s+$/, "") + "\\r\\n";
    });
    var eject = $("eject").value;
    if (eject === "ff") { out += "\\x0C"; } else if (eject === "tear") { out += "\\r\\n\\r\\n\\r\\n\\r\\n\\r\\n\\r\\n"; }
    return out + ESC + "@";
  }

  var qzReady = null;
  function qz() {
    if (!qzReady) {
      qzReady = new Promise(function (resolve, reject) {
        if (window.qz) { return resolve(window.qz); }
        var s = document.createElement("script");
        s.src = "https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js";
        s.onload = function () { resolve(window.qz); };
        s.onerror = function () { qzReady = null; reject(new Error("QZ Tray library could not be loaded")); };
        document.head.appendChild(s);
      }).then(function (q) {
        q.security.setCertificatePromise(function (resolve) { resolve(); });
        q.security.setSignaturePromise(function () { return function (resolve) { resolve(); }; });
        return q.websocket.isActive() ? q : q.websocket.connect({ retries: 1, delay: 1 }).then(function () { return q; });
      });
      qzReady.catch(function () { qzReady = null; });
    }
    return qzReady;
  }
  function qzError(err) {
    setStatus("QZ Tray is not running on this PC. Install it once from qz.io/download and start it, or use Download .prn. (" + ((err && err.message) || err) + ")", true);
  }

  function findPrinters() {
    setStatus("Looking for printers…");
    return qz().then(function (q) { return q.printers.find(); }).then(function (list) {
      list = list || [];
      var keep = printerEl.value;
      printerEl.innerHTML = "";
      list.forEach(function (name) { printerEl.add(new Option(name, name)); });
      if (keep && list.indexOf(keep) !== -1) { printerEl.value = keep; }
      else {
        var dm = list.filter(function (n) { return /lx|fx|epson|tvs|msp|dot|matrix|wipro|oki|9\\s?pin|24\\s?pin/i.test(n); })[0];
        if (dm) { printerEl.value = dm; }
      }
      save("printer", printerEl.value);
      setStatus("Found " + list.length + " printer(s). Select the dot matrix printer and click Direct Print.");
    }).catch(qzError);
  }

  $("btnFind").addEventListener("click", findPrinters);
  $("btnDirect").addEventListener("click", function () {
    var printer = printerEl.value;
    if (!printer) { findPrinters(); return; }
    setStatus("Sending to " + printer + "…");
    qz().then(function (q) {
      return q.print(q.configs.create(printer, { encoding: "ISO-8859-1" }), [{ type: "raw", format: "command", flavor: "plain", data: escp() }]);
    }).then(function () { setStatus("Printed on " + printer + "."); }).catch(qzError);
  });
  $("btnPrn").addEventListener("click", function () {
    var data = escp(), bytes = new Uint8Array(data.length);
    for (var i = 0; i < data.length; i++) { bytes[i] = data.charCodeAt(i) & 0xFF; }
    var url = URL.createObjectURL(new Blob([bytes], { type: "application/octet-stream" }));
    var a = document.createElement("a");
    a.href = url; a.download = FILE + ".prn";
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
    setStatus("Saved " + FILE + ".prn. To print it raw: copy /b " + FILE + ".prn \\"\\\\\\\\localhost\\\\<printer share name>\\"");
  });
  $("btnBrowser").addEventListener("click", function () { pageSize(); window.print(); });
  window.addEventListener("beforeprint", pageSize);
  apply();
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(pageSize); }
})();
</script>
</body>
</html>';
    }
}
