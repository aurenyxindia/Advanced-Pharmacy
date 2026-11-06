package `in`.paperlesspharmacy.advanced.data.transaction

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.roundToInt
import kotlin.math.roundToLong

/** One line parsed by Retail "Upload PO" format maps (web `upload-purchase-csv.ts`). */
data class RetailCsvRow(
    val product: String = "",
    val mfg: String = "",
    val pack: String = "",
    val hsn: String = "",
    val batch: String = "",
    /** yyyy-MM when recognised, otherwise raw text. */
    val expiry: String = "",
    val qty: Double = 0.0,
    val free: Double = 0.0,
    val mrp: Double = 0.0,
    val rate: Double = 0.0,
    val ptr: Double = 0.0,
    val pts: Double = 0.0,
    val gross: Double = 0.0,
    val discPct: Double = 0.0,
    val discAmt: Double = 0.0,
    val gst: Double = 0.0,
)

private class FormatConfig(val skipCols: Set<Int>, val headerKeys: List<String>)

/** Retail #/uploadPurchase formatConfigs — skip row 0 (header), column maps after skipCols. */
object RetailPurchaseCsv {
    val FORMATS = listOf(
        "Medivision", "Marg ERP Format", "Marg ERP Nano", "Marg ERP 2", "Marg ERP 3", "Medica",
        "Easysol", "Care", "PHARMACC", "Reliable", "Samarth", "MicroPro", "Dawa",
    )

    private val CONFIGS: Map<String, FormatConfig> = mapOf(
        "Medivision" to FormatConfig(
            setOf(0, 1, 5, 13, 14, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33),
            listOf("product", "m_nameCsv", "packSizeFromCsv", "qty", "scheme", "mrp", "purchase_rate", "batch_no", "expiry_date", "totalAmt", "disc_amt", "gst"),
        ),
        "Marg ERP Format" to FormatConfig(
            setOf(0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 24) + (26..49).toSet(),
            listOf("m_nameCsv", "product", "packSizeFromCsv", "batch_no", "expiry_date", "gst", "purchase_rate", "mrp", "qty", "scheme", "disc", "disc_amt", "totalAmt"),
        ),
        "Marg ERP 2" to FormatConfig(
            setOf(0, 1, 4, 7, 10, 11, 13, 15, 17, 18, 19) + (22..48).toSet(),
            listOf("m_nameCsv", "hsn", "product", "packSizeFromCsv", "batch_no", "expiry_date", "gst", "purchase_rate", "mrp", "qty", "scheme"),
        ),
        "Marg ERP 3" to FormatConfig(
            setOf(0, 1, 3, 4, 7, 10, 13, 14, 17, 18, 19, 20, 21, 23, 24, 25, 27, 28, 29) + (31..48).toSet(),
            listOf("m_nameCsv", "product", "packSizeFromCsv", "batch_no", "expiry_date", "purchase_rate", "mrp", "qty", "scheme", "cgstPer", "sgstPer", "hsn"),
        ),
        "Medica" to FormatConfig(
            (0..22).toSet() + setOf(24, 27, 28, 33, 36, 38, 39, 40) + (42..57).toSet() + setOf(60, 62) + (64..75).toSet(),
            listOf("m_nameCsv", "product", "packSizeFromCsv", "batch_no", "expiry_date", "qty", "scheme", "purchase_rate", "totalAmt", "mrp", "disc", "hsn", "igstPer", "cgstPer", "sgstPer"),
        ),
        "Easysol" to FormatConfig(
            setOf(0, 1, 2, 3, 7) + (16..24).toSet() + setOf(26, 28, 30, 31, 32, 34, 35, 36, 37),
            listOf("product", "packSizeFromCsv", "m_nameCsv", "batch_no", "expiry_date", "purchase_rate", "mrp", "totalAmt", "qty", "scheme", "disc_amt", "sgstPer", "cgstPer", "igstPer", "hsn"),
        ),
        "Care" to FormatConfig(
            setOf(0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 22, 23, 24, 25, 26) + (28..35).toSet() + setOf(37),
            listOf("m_nameCsv", "product", "packSizeFromCsv", "batch_no", "expiry_date", "gst", "purchase_rate", "mrp", "qty", "scheme", "totalAmt", "hsn"),
        ),
        "PHARMACC" to FormatConfig(
            setOf(0, 1, 2, 3, 4, 10, 11, 12, 14, 15, 17, 18, 19) + (22..27).toSet() + setOf(29, 31, 32, 33),
            listOf("product", "packSizeFromCsv", "m_nameCsv", "batch_no", "expiry_date", "purchase_rate", "mrp", "qty", "scheme", "gst", "hsn"),
        ),
        "Reliable" to FormatConfig(
            setOf(0, 3, 4, 5, 9) + (15..20).toSet() + setOf(22, 23, 24, 25, 27, 28, 29) + (31..37).toSet(),
            listOf("product", "m_nameCsv", "batch_no", "expiry_date", "mrp", "gst", "qty", "scheme", "packSizeFromCsv", "disc_amt", "totalAmt", "hsn", "purchase_rate"),
        ),
        "Samarth" to FormatConfig(
            setOf(0, 1, 2, 3, 6, 15) + (18..28).toSet() + setOf(30, 31, 32) + (34..38).toSet(),
            listOf("product", "packSizeFromCsv", "m_nameCsv", "batch_no", "expiry_date", "purchase_rate", "mrp", "totalAmt", "qty", "scheme", "disc", "disc_amt", "gst", "hsn"),
        ),
        "Marg ERP Nano" to FormatConfig(
            setOf(0, 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 13, 15, 17, 18, 19, 24) + (26..37).toSet() + (39..50).toSet(),
            listOf("product", "gst", "purchase_rate", "mrp", "qty", "scheme", "disc", "disc_amt", "totalAmt", "hsn"),
        ),
        "MicroPro" to FormatConfig(
            setOf(0, 1, 2, 3, 4, 11, 13, 14, 17, 18, 19, 20, 22, 23, 25, 26, 27, 28, 29, 31),
            listOf("product", "packSizeFromCsv", "m_nameCsv", "batch_no", "expiry_date", "purchase_rate", "mrp", "qty", "scheme", "totalAmt", "gst", "hsn"),
        ),
        "Dawa" to FormatConfig(
            setOf(0, 1, 3, 4, 7, 10, 11, 13, 15, 17, 18, 19, 22, 23, 24, 25, 27),
            listOf("m_nameCsv", "product", "packSizeFromCsv", "batch_no", "expiry_date", "gst", "purchase_rate", "mrp", "qty", "scheme", "hsn"),
        ),
    )

    /** Quoted commas / tab separators (Retail parseCsvLine). */
    fun parseCsvLine(line: String): List<String> {
        val out = mutableListOf<String>()
        val cur = StringBuilder()
        var inQuotes = false
        for (c in line) {
            when {
                c == '"' -> inQuotes = !inQuotes
                (c == ',' && !inQuotes) || c == '\t' -> { out += cur.toString().trim(); cur.clear() }
                else -> cur.append(c)
            }
        }
        out += cur.toString().trim()
        return out
    }

    /** Retail formatExpiryToYYYYMM. */
    fun expiryToYm(value: String): String {
        val s = value.trim().replace(Regex("\\s"), "").trim('"', '\'')
        if (s.isEmpty()) return ""
        Regex("^(\\d{4})[/\\-](\\d{1,2})").find(s)?.let { return "${it.groupValues[1]}-${it.groupValues[2].padStart(2, '0')}" }
        Regex("^(\\d{1,2})\\D(\\d{1,2})\\D(\\d{4})$").find(s)?.let {
            val (p1, p2, year) = it.destructured
            val month = if (p1.toInt() > 12) p2 else if (p2.toInt() > 12) p1 else p2
            return "$year-${month.padStart(2, '0')}"
        }
        Regex("^(\\d{1,2})\\D(\\d{1,2})\\D(\\d{2})$").find(s)?.let {
            val (p1, p2, yy) = it.destructured
            val month = if (p1.toInt() > 12) p2 else if (p2.toInt() > 12) p1 else p2
            val y = yy.toInt()
            return "${if (y <= 29) 2000 + y else 1900 + y}-${month.padStart(2, '0')}"
        }
        // MM/YY (common on bills)
        Regex("^(\\d{1,2})\\D(\\d{2})$").find(s)?.let {
            val mm = it.groupValues[1].toInt()
            if (mm in 1..12) return "20${it.groupValues[2]}-${mm.toString().padStart(2, '0')}"
        }
        val digits = s.filter { it.isDigit() }
        if (digits.length == 8) {
            val mm = digits.substring(2, 4)
            if (mm.toInt() in 1..12) return "${digits.substring(4, 8)}-$mm"
        }
        if (digits.length == 7) {
            val mm = digits.substring(1, 3)
            if (mm.toInt() in 1..12) return "${digits.substring(3, 7)}-$mm"
        }
        return s
    }

    private fun num(v: String?): Double = v.orEmpty().replace(",", "").trim().toDoubleOrNull() ?: 0.0
    private fun r2(v: Double): Double = (v * 100).roundToLong() / 100.0

    fun parse(csvText: String, format: String): List<RetailCsvRow> {
        val text = csvText.removePrefix("\uFEFF")
        if ((format == "Medivision" || format == "Auto") &&
            Regex("^H,", RegexOption.MULTILINE).containsMatchIn(text.trim()) &&
            Regex("^T,", RegexOption.MULTILINE).containsMatchIn(text)
        ) {
            return parseMedivisionHt(text)
        }
        val cfg = CONFIGS[format] ?: CONFIGS.getValue("Medivision")
        val lines = text.split(Regex("\\r?\\n")).filter { it.isNotBlank() }
        return lines.drop(1).mapNotNull { line ->
            val cols = parseCsvLine(line).filterIndexed { i, _ -> i !in cfg.skipCols }
            val o = cfg.headerKeys.mapIndexed { i, k -> k to cols.getOrElse(i) { "" } }.toMap()
            val product = o["product"].orEmpty().trim()
            if (product.isEmpty()) return@mapNotNull null
            var gst = num(o["gst"]).roundToInt().toDouble()
            when (format) {
                "Medica", "Easysol" -> gst = (num(o["igstPer"]) + num(o["cgstPer"]) + num(o["sgstPer"])).roundToInt().toDouble()
                "Marg ERP 3" -> {
                    val s = (num(o["cgstPer"]) + num(o["sgstPer"])).roundToInt()
                    gst = if (s > 0) s.toDouble() else 5.0
                }
            }
            val scheme = num(o["scheme"]).let { if (it > 0 && it == floorD(it)) it else 0.0 }
            RetailCsvRow(
                product = product,
                mfg = o["m_nameCsv"].orEmpty(),
                pack = o["packSizeFromCsv"].orEmpty(),
                hsn = o["hsn"].orEmpty(),
                batch = o["batch_no"].orEmpty(),
                expiry = o["expiry_date"].orEmpty().let { if (it.isNotBlank()) expiryToYm(it) else "" },
                qty = num(o["qty"]),
                free = scheme,
                mrp = r2(num(o["mrp"])),
                rate = r2(num(o["purchase_rate"])),
                ptr = r2(num(o["ptr"])),
                pts = r2(num(o["pts"])),
                gross = r2(num(o["totalAmt"])),
                discPct = r2(num(o["disc"])),
                discAmt = r2(num(o["disc_amt"])),
                gst = gst,
            )
        }
    }

    private fun floorD(v: Double) = kotlin.math.floor(v)

    /** Medivision Sale_ H/T dump — billed qty = amount ÷ trailing rate (API PurchaseFormatParser parity). */
    private fun parseMedivisionHt(text: String): List<RetailCsvRow> =
        text.split(Regex("\\r?\\n")).filter { it.isNotBlank() }.mapNotNull { line ->
            val cols = parseCsvLine(line)
            if (cols.getOrNull(0)?.uppercase() != "T") return@mapNotNull null
            val product = cols.getOrNull(2).orEmpty().trim()
            if (product.isEmpty()) return@mapNotNull null
            var qty = num(cols.getOrNull(6)).roundToInt()
            val scheme = num(cols.getOrNull(7)).roundToInt()
            val mrp = num(cols.getOrNull(8))
            val pts = num(cols.getOrNull(9))
            val amount = num(cols.getOrNull(12))
            val discAmt = num(cols.getOrNull(15))
            val gst = num(cols.getOrNull(16)).roundToInt()
            var rate = num(cols.getOrNull(31))
            if (rate <= 0) {
                for (i in cols.lastIndex downTo 20) {
                    val v = num(cols[i])
                    if (v > 0) { rate = v; break }
                }
            }
            if (rate <= 0) rate = pts
            if (rate > 0 && amount > 0) {
                val q = amount / rate
                if (abs(q - q.roundToInt()) < 0.051) {
                    val recon = max(1, q.roundToInt())
                    val errRaw = abs(qty * rate - amount)
                    val errRecon = abs(recon * rate - amount)
                    if (errRecon <= max(0.05, amount * 0.01) && (qty <= 0 || errRaw > max(0.5, amount * 0.05))) qty = recon
                }
            }
            val total = if (amount > 0) r2(amount) else r2(qty * rate)
            RetailCsvRow(
                product = product,
                pack = cols.getOrNull(4).orEmpty().trim(),
                batch = cols.getOrNull(10).orEmpty().trim(),
                expiry = expiryToYm(cols.getOrNull(11).orEmpty()),
                qty = qty.toDouble(),
                free = scheme.toDouble(),
                mrp = r2(mrp),
                rate = r2(rate),
                ptr = r2(rate),
                pts = r2(pts),
                gross = total,
                discAmt = r2(discAmt),
                discPct = if (total > 0 && discAmt > 0) (discAmt * 100 / total * 10000).roundToLong() / 10000.0 else 0.0,
                gst = gst.toDouble(),
            )
        }
}
