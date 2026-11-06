package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj

class PurchaseOrderRepository(
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    fun list(
        search: String = "",
        fromDate: String = "",
        toDate: String = "",
        poType: String = "",
    ): List<JsonObject> {
        val q = mutableMapOf<String, String>()
        if (search.isNotBlank()) q["search"] = search
        if (fromDate.isNotBlank()) q["from_date"] = fromDate
        if (toDate.isNotBlank()) q["to_date"] = toDate
        if (poType.isNotBlank()) q["po_type"] = poType
        return api.get("purchase-orders", q).dataRows()
    }

    fun get(id: Int): JsonObject = api.get("purchase-orders/$id").obj("data")

    fun create(body: JsonObject): JsonObject = api.post("purchase-orders", body).obj("data")

    fun update(id: Int, body: JsonObject): JsonObject = api.put("purchase-orders/$id", body).obj("data")

    fun shortBook(): JsonObject = api.get("purchase-orders/short-book").obj("data")

    fun pdfBytes(id: Int): ByteArray = api.getBytes("purchase-orders/$id/pdf")

    fun searchProducts(q: String, limit: Int = 30): List<JsonObject> =
        api.get("products/search", mapOf("q" to q, "limit" to limit.toString())).dataRows()

    fun distributors(): List<JsonObject> {
        val out = mutableListOf<JsonObject>()
        var page = 1
        while (page <= 20) {
            val r = crud.list("distributors", page = page, perPage = 200)
            out += r.rows
            if (r.rows.isEmpty() || out.size >= r.total) break
            page++
        }
        return out
            .filter { it.get("name")?.takeIf { n -> !n.isJsonNull }?.asString?.isNotBlank() == true }
            .sortedBy { it.get("name").asString.lowercase() }
    }
}
