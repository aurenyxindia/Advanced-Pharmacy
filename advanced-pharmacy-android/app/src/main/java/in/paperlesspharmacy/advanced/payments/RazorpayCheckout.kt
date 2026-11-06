package `in`.paperlesspharmacy.advanced.payments

import android.app.Activity
import com.google.gson.JsonObject
import com.razorpay.Checkout
import org.json.JSONObject

sealed class RazorpayPayResult {
    data class Success(
        val orderId: String,
        val paymentId: String,
        val signature: String,
    ) : RazorpayPayResult()

    data class Failed(val code: Int, val message: String) : RazorpayPayResult()
}

/**
 * Opens official Razorpay Checkout (UPI / cards / netbanking).
 * Amount and order_id come from the server — never typed by the user.
 */
object RazorpayCheckoutLauncher {
    fun open(activity: Activity, checkout: JsonObject) {
        val keyId = checkout.str("key_id")
        require(keyId.isNotBlank()) { "Missing Razorpay key." }

        val options = JSONObject().apply {
            put("key", keyId)
            put("order_id", checkout.str("order_id"))
            put("currency", checkout.str("currency").ifBlank { "INR" })
            put("amount", checkout.amountPaise())
            put("name", checkout.str("company").ifBlank { "Paperless Pharmacy" })
            put("description", checkout.str("description").ifBlank { "Subscription" })
            put("send_sms_hash", true)
            put("allow_rotation", false)
            put(
                "theme",
                JSONObject().put(
                    "color",
                    checkout.obj("theme").str("color").ifBlank { "#0D9488" },
                ),
            )
            val prefill = checkout.obj("prefill")
            put(
                "prefill",
                JSONObject().apply {
                    val name = prefill.str("name")
                    val email = prefill.str("email")
                    val contact = prefill.str("contact")
                    if (name.isNotBlank()) put("name", name)
                    if (email.isNotBlank()) put("email", email)
                    if (contact.isNotBlank()) put("contact", contact)
                },
            )
            put(
                "retry",
                JSONObject().put("enabled", true).put("max_count", 2),
            )
        }

        val co = Checkout()
        co.setKeyID(keyId)
        co.open(activity, options)
    }
}

private fun JsonObject.str(key: String): String =
    if (has(key) && !get(key).isJsonNull) get(key).asString.trim() else ""

private fun JsonObject.obj(key: String): JsonObject =
    if (has(key) && get(key).isJsonObject) getAsJsonObject(key) else JsonObject()

private fun JsonObject.amountPaise(): Int {
    if (!has("amount") || get("amount").isJsonNull) return 0
    return try {
        get("amount").asInt
    } catch (_: Exception) {
        get("amount").asDouble.toInt()
    }
}
