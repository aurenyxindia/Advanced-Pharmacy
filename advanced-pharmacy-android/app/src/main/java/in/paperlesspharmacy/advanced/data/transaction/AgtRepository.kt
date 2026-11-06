package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.arr
import `in`.paperlesspharmacy.advanced.data.bool
import `in`.paperlesspharmacy.advanced.data.jInt
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.str

/** AuraGharTak staff API (web `AgtService`), routes under `agt/`. */
class AgtRepository(private val api: ApiClient) {

    private fun objects(parent: JsonObject, key: String): List<JsonObject> {
        val a = parent.arr(key)
        return (0 until a.size()).mapNotNull { a.get(it).takeIf { e -> e.isJsonObject }?.asJsonObject }
    }

    fun searchCustomers(q: String): List<JsonObject> =
        objects(api.get("agt/customers", mapOf("q" to q)).obj("data"), "customers")

    /** Returns the full envelope: `message` + `data.{token, shop_url, whatsapp_sent}`. */
    fun createShare(
        patientNo: String,
        patientName: String,
        patientContact: String,
        patientAddress: String,
        sendWhatsapp: Boolean,
    ): JsonObject = api.post(
        "agt/share-link",
        JsonObject().apply {
            if (patientNo.isNotBlank()) addProperty("patient_no", patientNo)
            addProperty("patient_name", patientName)
            addProperty("patient_contact", patientContact)
            if (patientAddress.isNotBlank()) addProperty("patient_address", patientAddress)
            addProperty("send_whatsapp", sendWhatsapp)
        },
    )

    fun orderCounts(): Map<String, Int> {
        val counts = api.get("agt/order-counts").obj("data").obj("counts")
        return counts.keySet().associateWith { counts.jInt(it) }
    }

    fun listOrders(status: String, limit: Int = 60): List<JsonObject> {
        val q = mutableMapOf("limit" to limit.toString())
        if (status.isNotBlank() && status != "All") q["status"] = status
        return objects(api.get("agt/orders", q).obj("data"), "orders")
    }

    fun getOrder(id: Int): JsonObject =
        api.get("agt/orders/detail", mapOf("order_id" to id.toString())).obj("data").obj("order")

    fun pendingByPatient(patientNo: String, contact: String): List<JsonObject> =
        objects(
            api.get("agt/orders/pending", mapOf("patient_no" to patientNo, "patient_contact" to contact)).obj("data"),
            "orders",
        )

    fun markReady(orderId: Int, invoiceNo: String, saleId: Int, saleNet: Double): JsonObject =
        api.post(
            "agt/orders/mark-ready",
            JsonObject().apply {
                addProperty("order_id", orderId)
                addProperty("sale_invoice_no", invoiceNo)
                addProperty("sale_id", saleId)
                addProperty("sale_net", saleNet)
            },
        )

    /** Full envelope: `message` + `data.whatsapp.{ok, error}`. */
    fun updateStatus(orderId: Int, status: String, sendWhatsapp: Boolean): JsonObject =
        api.post(
            "agt/orders/status",
            JsonObject().apply {
                addProperty("order_id", orderId)
                addProperty("status", status)
                addProperty("send_whatsapp", sendWhatsapp)
            },
        )

    fun riders(): List<JsonObject> = objects(api.get("agt/riders").obj("data"), "riders")

    fun assignRider(orderId: Int, riderUserId: Int): JsonObject =
        api.post(
            "agt/orders/rider",
            JsonObject().apply {
                addProperty("order_id", orderId)
                addProperty("rider_user_id", riderUserId)
            },
        )

    fun allowCustomerCancel(): Boolean = api.get("agt/settings").obj("data").bool("allow_customer_cancel")

    fun saveAllowCustomerCancel(allow: Boolean): JsonObject =
        api.post("agt/settings", JsonObject().apply { addProperty("allow_customer_cancel", allow) })

    /** Prescription files are served from `/api/public/agt/prescription/<file>` (no auth needed). */
    fun prescriptionBytes(url: String): ByteArray {
        val path = url.substringAfter("/api/", url).trimStart('/')
        return api.getBytes(path)
    }

    /** Shop payment QR (`profile.assets.scanner`), or null when not uploaded. */
    fun paymentQr(fallbackClientId: Int?): ByteArray? {
        val p = api.get("profile").obj("data")
        if (p.obj("assets").str("scanner").isBlank()) return null
        val cid = p.jInt("client_id").takeIf { it > 0 } ?: fallbackClientId ?: return null
        return api.getBytes("profile/assets/$cid/scanner")
    }
}
