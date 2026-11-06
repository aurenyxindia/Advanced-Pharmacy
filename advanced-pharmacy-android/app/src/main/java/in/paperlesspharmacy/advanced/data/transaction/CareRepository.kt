package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.PaginatedRows
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.paginatedFromApiData

/** IPD admissions, refill reminders and online-order deliveries (same endpoints as web). */
class CareRepository(
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    // ---- IPD ----
    fun admissions(status: String = ""): List<JsonObject> =
        api.get("ipd/admissions", if (status.isBlank()) emptyMap() else mapOf("status" to status)).dataRows()

    fun admission(id: Int): JsonObject = api.get("ipd/admissions/$id").obj("data")

    fun admit(body: JsonObject): Int = api.post("ipd/admissions", body).obj("data").get("id")?.asInt ?: 0

    fun ipdPayment(id: Int, amount: Double, mode: String, note: String) {
        api.post("ipd/admissions/$id/payment", JsonObject().apply {
            addProperty("amount", amount)
            addProperty("mode", mode)
            if (note.isNotBlank()) addProperty("note", note)
        })
    }

    fun discharge(id: Int): JsonObject = api.post("ipd/admissions/$id/discharge", JsonObject()).obj("data")

    fun doctors(): List<JsonObject> = crud.list("doctors", perPage = 500).rows

    fun generics(): List<JsonObject> = crud.list("generics", perPage = 2000).rows

    // ---- Refills ----
    fun refills(due: String = "", status: String = ""): List<JsonObject> {
        val q = mutableMapOf<String, String>()
        if (due.isNotBlank()) q["due"] = due
        if (status.isNotBlank()) q["status"] = status
        return api.get("refills", q).dataRows()
    }

    fun refillSummary(): JsonObject = api.get("refills/summary").obj("data")

    fun createRefill(body: JsonObject): JsonObject = api.post("refills", body).obj("data")

    fun updateRefill(id: Int, status: String? = null, channel: String? = null, notes: String? = null) {
        api.put("refills/$id", JsonObject().apply {
            status?.let { addProperty("status", it) }
            channel?.let { addProperty("channel", it) }
            notes?.let { addProperty("notes", it) }
        })
    }

    // ---- Deliveries (online orders) ----
    fun orders(search: String = "", perPage: Int = 500): PaginatedRows {
        val q = mutableMapOf("per_page" to perPage.toString())
        if (search.isNotBlank()) q["search"] = search
        return paginatedFromApiData(api.get("orders", q).obj("data"))
    }

    fun order(id: Int): JsonObject = api.get("orders/$id").obj("data")

    fun updateOrderStatus(id: Int, status: String, assignedTo: String, note: String) {
        api.put("orders/$id/status", JsonObject().apply {
            addProperty("status", status)
            if (assignedTo.isNotBlank()) addProperty("assigned_to", assignedTo)
            if (note.isNotBlank()) addProperty("note", note)
        })
    }
}
