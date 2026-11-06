package com.paperlesspharmacy.auraghartak.ui

import java.util.Locale

fun inr(amount: Double): String {
    val sign = if (amount < 0) "-" else ""
    val abs = kotlin.math.abs(amount)
    return sign + "₹" + String.format(Locale.US, "%,.2f", abs)
}

/** Loose unit the customer buys: tablet, capsule, or a generic unit. */
fun looseUnitWord(packSize: String, productName: String = ""): String {
    val text = "$packSize $productName".lowercase(Locale.US)
    return when {
        Regex("\\bcap(s|sule|sules)?\\b").containsMatchIn(text) -> "capsule"
        Regex("\\btab(s|let|lets)?\\b").containsMatchIn(text) -> "tablet"
        else -> "unit"
    }
}

/** "₹15.00 / tablet" plus "Strip of 10 · ₹150.00" when the product is sold loose from a strip. */
fun unitPriceLabel(unitMrp: Double, unitsPerPack: Int, packSize: String, productName: String): Pair<String, String?> {
    if (unitMrp <= 0) return "Ask price" to null
    if (unitsPerPack <= 1) return inr(unitMrp) to null
    val word = looseUnitWord(packSize, productName)
    val packWord = if (word == "unit") "Pack" else "Strip"
    return "${inr(unitMrp)} / $word" to "$packWord of $unitsPerPack · ${inr(unitMrp * unitsPerPack)}"
}

fun prettyDate(raw: String): String {
    val s = raw.trim().replace('T', ' ')
    if (s.length < 10) return raw
    val date = s.take(10).split("-")
    if (date.size != 3) return raw
    val time = if (s.length >= 16) s.substring(11, 16) else ""
    return "${date[2]}-${date[1]}-${date[0]}" + if (time.isNotBlank()) " $time" else ""
}

fun expiryLabel(raw: String): String {
    val s = raw.trim()
    if (s.length >= 7 && s[4] == '-') {
        return s.substring(5, 7) + "/" + s.substring(2, 4)
    }
    return s
}

fun statusLabel(status: String): String = when (status) {
    "placed" -> "Placed"
    "confirmed" -> "Confirmed"
    "packed" -> "Packed"
    "out_for_delivery" -> "Out for delivery"
    "delivered" -> "Delivered"
    "cancelled" -> "Cancelled"
    else -> status.replace('_', ' ').replaceFirstChar { if (it.isLowerCase()) it.titlecase() else it.toString() }
}

fun stockQtyNumber(qty: Double): String {
    val whole = qty % 1.0 == 0.0
    return if (whole) qty.toInt().toString() else String.format(Locale.US, "%.1f", qty)
}

fun stockLabel(qty: Double): String = "${stockQtyNumber(qty)} in stock"

fun digits(value: String): String = value.filter { it.isDigit() }

fun extractToken(raw: String): String {
    val text = raw.trim()
    if (text.isEmpty()) return ""
    val tokenQuery = Regex("[?&]token=([^&#\\s]+)", RegexOption.IGNORE_CASE).find(text)
    if (tokenQuery != null) return java.net.URLDecoder.decode(tokenQuery.groupValues[1], Charsets.UTF_8.name())
    return text
}
