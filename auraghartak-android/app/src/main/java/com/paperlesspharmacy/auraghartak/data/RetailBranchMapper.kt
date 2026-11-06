package com.paperlesspharmacy.auraghartak.data

import com.google.gson.JsonObject

/** Maps Retail `listClients` rows (field names vary on serverdev). */
object RetailBranchMapper {
    fun fromRow(row: JsonObject): Branch? {
        val code = row.firstStr("client_no", "clientNo", "client_code")
        if (code.isBlank()) return null
        val medical = row.firstStr("medical_name", "medical", "medicalName").ifBlank { code }
        val state = placeName(row.firstStr("state", "State"))
        val pincode = NearYou.pinDigits(
            row.firstStr("pincode", "pin", "Pin", "PIN", "pin_code", "zip", "postal_code"),
        )
        val address = row.firstStr("address", "Address", "Address1", "client_address", "addr")
            .ifBlank { row.firstStr("address1", "Address2") }
        var city = placeName(row.firstStr("city", "City", "district", "District", "place"))
        if (city.isBlank()) city = IndianAddress.guessCity(address, state)
        val phone = row.firstStr("mobile", "Mobile", "cNumber", "phone", "contact", "contact_no")
        return Branch(
            id = retailKey(code),
            name = medical,
            city = city,
            businessName = medical,
            source = "retail",
            clientNo = code,
            state = state,
            pincode = pincode,
            address = address,
            phone = phone,
        )
    }
}

private object IndianAddress {
    /** Best-effort city from a free-text retail address (often "street, city, state"). */
    fun guessCity(address: String, state: String): String {
        val a = address.trim()
        if (a.isBlank()) return ""
        val parts = a.split(',').map { it.trim() }.filter { it.isNotEmpty() }
        if (parts.size >= 2) {
            val candidate = parts[parts.size - 2]
            if (candidate.length in 2..40 && !candidate.all { it.isDigit() }) {
                if (state.isBlank() || !candidate.equals(state, ignoreCase = true)) {
                    return placeName(candidate)
                }
            }
        }
        val pinMatch = Regex("\\b(\\d{6})\\b").find(a)
        if (pinMatch != null) {
            val before = a.substring(0, pinMatch.range.first).split(',').lastOrNull()?.trim().orEmpty()
            if (before.length in 2..40) return placeName(before)
        }
        return ""
    }
}

private fun JsonObject.firstStr(vararg keys: String): String {
    for (key in keys) {
        val v = str(key)
        if (v.isNotBlank()) return v
    }
    return ""
}

private fun JsonObject.str(key: String): String {
    val el = get(key) ?: return ""
    if (el.isJsonNull || !el.isJsonPrimitive) return ""
    return el.asString.trim()
}

private fun placeName(raw: String): String =
    raw.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.joinToString(" ") { word ->
        word.lowercase().replaceFirstChar { it.titlecase() }
    }

private fun retailKey(value: String): Long {
    var hash = 1125899906842597L
    value.forEach { hash = 31L * hash + it.code }
    val positive = hash and Long.MAX_VALUE
    return if (positive == 0L) 1L else positive
}
