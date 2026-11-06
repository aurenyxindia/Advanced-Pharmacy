package `in`.paperlesspharmacy.advanced.ui.util

import java.text.NumberFormat
import java.util.Locale

private val inrWhole: NumberFormat = NumberFormat.getCurrencyInstance(Locale("en", "IN")).apply {
    maximumFractionDigits = 0
    minimumFractionDigits = 0
}

fun formatInr(value: Double): String = inrWhole.format(value)

fun formatInr(value: Int): String = formatInr(value.toDouble())

/** Product MRP with paise (catalog / billing lines). */
fun formatMrp(value: Double): String {
    if (value <= 0) return "—"
    return "₹" + String.format(Locale.ENGLISH, "%.2f", value)
}

/** Currency with paise (sales log amounts). */
fun formatAmount2(value: Double): String = formatMrp(value)

fun formatSaleDate(raw: String): String {
    if (raw.isBlank()) return "—"
    val s = raw.take(10)
    val parts = s.split("-", "/")
    if (parts.size >= 3 && parts[0].length == 4) {
        return "${parts[2].padStart(2, '0')}-${parts[1].padStart(2, '0')}-${parts[0]}"
    }
    return s
}

/** Accepts yyyy-MM, yyyy-MM-dd, MM/yy, MM/yyyy → yyyy-MM. */
fun parseExpiryYm(raw: String): String {
    val s = raw.trim()
    if (s.isEmpty()) return ""
    Regex("^(\\d{4})-(\\d{2})").find(s)?.let { return "${it.groupValues[1]}-${it.groupValues[2]}" }
    Regex("^(\\d{1,2})[/-](\\d{2}|\\d{4})$").find(s)?.let { m ->
        val mm = m.groupValues[1].toInt()
        if (mm !in 1..12) return ""
        val y = m.groupValues[2].let { if (it.length == 2) "20$it" else it }
        return "$y-${mm.toString().padStart(2, '0')}"
    }
    return ""
}

/** yyyy-MM → MM/YY for display. */
fun formatExpiryMmYy(ym: String): String =
    if (ym.length >= 7) "${ym.substring(5, 7)}/${ym.substring(2, 4)}" else ""
