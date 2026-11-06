package `in`.paperlesspharmacy.advanced.data.master

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive

object MasterFormLogic {
    private val phone = Regex("^[6-9]\\d{9}$")
    private val phoneDist = Regex("^\\d{10,12}$")
    private val pincode = Regex("^[1-9][0-9]{5}$")
    private val hsn = Regex("^\\d{4,8}$")
    private val hsnStrict = Regex("^(?:\\d{4}|\\d{6}|\\d{8})$")
    private val fssai = Regex("^\\d{14}$")
    private val pan = Regex("^[A-Z]{5}[0-9]{4}[A-Z]$")
    private val ifsc = Regex("^[A-Z]{4}0[A-Z0-9]{6}$")
    private val gstin = Regex("^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$")

    fun visibleFields(config: MasterConfigDto, values: Map<String, String>, isEdit: Boolean): List<MasterFieldDto> =
        config.fields.filter { field ->
            if (isEdit && field.hideOnEdit) return@filter false
            matchesShowWhen(field.showWhen, values)
        }

    fun matchesShowWhen(showWhen: JsonElement?, values: Map<String, String>): Boolean {
        if (showWhen == null) return true
        val rules = when {
            showWhen.isJsonArray -> showWhen.asJsonArray.map { it.asJsonObject }
            showWhen.isJsonObject -> listOf(showWhen.asJsonObject)
            else -> return true
        }
        return rules.all { rule ->
            val field = rule.get("field")?.asString ?: return@all true
            val current = values[field].orEmpty()
            val equals = rule.get("equals") ?: return@all true
            when {
                equals.isJsonArray -> equals.asJsonArray.any { eq -> valueMatches(eq, current) }
                else -> valueMatches(equals, current)
            }
        }
    }

    private fun valueMatches(expected: JsonElement, current: String): Boolean {
        val cur = current.trim()
        return when {
            expected.isJsonPrimitive && expected.asJsonPrimitive.isNumber ->
                cur == expected.asNumber.toString() || cur == expected.asInt.toString()
            expected.isJsonPrimitive -> cur.equals(expected.asString, ignoreCase = false) ||
                cur == expected.asString
            else -> false
        }
    }

    fun normalizeField(field: MasterFieldDto, raw: String): String {
        var value = raw.trim()
        if (field.uppercase && field.type !in listOf("email", "password")) {
            value = value.uppercase()
        }
        when (field.validation) {
            "phone", "phoneDistributor", "pincode", "hsn", "hsnStrict" -> value = value.filter { it.isDigit() }
            "gstin" -> value = value.filter { it.isLetterOrDigit() }.uppercase()
            "pan", "ifsc" -> value = value.uppercase()
        }
        return value
    }

    fun validateField(field: MasterFieldDto, value: String, visible: Boolean): String? {
        if (!visible) return null
        val v = value.trim()
        if (field.required && v.isEmpty()) return "${field.label} is required."
        if (v.isEmpty()) return null
        when (field.validation) {
            "phone" -> if (!phone.matches(v)) return "Enter a valid 10-digit mobile (6–9 start)."
            "phoneDistributor" -> if (!phoneDist.matches(v)) return "Enter 10–12 digit contact."
            "pincode" -> if (!pincode.matches(v)) return "Enter a valid 6-digit pincode."
            "hsn" -> if (!hsn.matches(v)) return "HSN must be 4–8 digits."
            "hsnStrict" -> if (!hsnStrict.matches(v)) return "HSN must be 4, 6, or 8 digits."
            "fssai" -> if (!fssai.matches(v)) return "FSSAI must be 14 digits."
            "pan" -> if (!pan.matches(v)) return "Invalid PAN format."
            "ifsc" -> if (!ifsc.matches(v)) return "Invalid IFSC."
            "gstin" -> {
                if (v.length != 15) return "GSTIN must be 15 characters."
                if (!gstin.matches(v)) return "Invalid GSTIN format."
            }
        }
        field.maxLength?.let { if (v.length > it) return "${field.label} max $it characters." }
        if (field.type == "email" && !v.contains("@")) return "Enter a valid email."
        if (field.type == "number" || field.min != null || field.max != null) {
            val n = v.toDoubleOrNull() ?: return "${field.label} must be a number."
            field.min?.let { if (n < it) return "${field.label} min $it." }
            field.max?.let { if (n > it) return "${field.label} max $it." }
        }
        return null
    }

    fun buildPayload(
        config: MasterConfigDto,
        values: Map<String, String>,
        isEdit: Boolean,
    ): JsonObject {
        val body = JsonObject()
        visibleFields(config, values, isEdit).forEach { field ->
            var v = normalizeField(field, values[field.key].orEmpty())
            if (v.isEmpty()) return@forEach
            if (isEdit && field.type == "password" && v.isBlank()) return@forEach
            when (field.type) {
                "number", "genericSelect" -> {
                    val n = v.toDoubleOrNull()
                    if (n != null) {
                        if (field.key == "generic_id" || field.key.endsWith("_id")) {
                            body.addProperty(field.key, n.toInt())
                        } else if (v.contains('.')) body.addProperty(field.key, n)
                        else body.addProperty(field.key, n.toInt())
                    }
                }
                else -> {
                    if (field.options?.any { opt ->
                            optionValue(opt) == v || optionValue(opt) == v.toIntOrNull()?.toString()
                        } == true && v.all { it.isDigit() }
                    ) {
                        v.toIntOrNull()?.let { body.addProperty(field.key, it) } ?: body.addProperty(field.key, v)
                    } else body.addProperty(field.key, v)
                }
            }
        }
        return body
    }

    fun optionValue(opt: MasterOptionDto): String =
        when {
            opt.value.isJsonPrimitive && opt.value.asJsonPrimitive.isNumber -> opt.value.asNumber.toString()
            opt.value.isJsonPrimitive -> opt.value.asString
            else -> opt.value.toString()
        }

    fun optionLabelForValue(options: List<MasterOptionDto>, value: String): String =
        options.firstOrNull { optionValue(it) == value || optionValue(it) == value.trim() }?.label ?: value
}
