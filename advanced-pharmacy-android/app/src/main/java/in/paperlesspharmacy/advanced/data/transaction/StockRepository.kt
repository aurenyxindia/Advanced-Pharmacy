package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.str

/** Opening stock, stock adjustment and minimum inventory (web `StockService` + `MinInventoryService`). */
class StockRepository(
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    fun openingList(): List<JsonObject> = api.get("stock/opening").dataRows()

    fun openingMatchProducts(): List<JsonObject> = api.get("stock/opening/match-products").dataRows()

    fun openingBulkSave(body: JsonObject): JsonObject = api.post("stock/opening/bulk", body).obj("data")

    fun openingUpdate(batchId: Int, body: JsonObject): JsonObject = api.put("stock/opening/$batchId", body)

    fun openingDelete(batchId: Int): JsonObject = api.delete("stock/opening/$batchId")

    fun writeOffExpired(): JsonObject = api.post("stock/write-off-expired", JsonObject())

    fun adjustmentBatches(search: String): List<JsonObject> =
        api.get("stock/adjustment/batches", if (search.isBlank()) emptyMap() else mapOf("search" to search)).dataRows()

    fun adjustmentSave(type: String, remarks: String, lines: JsonArray): JsonObject =
        api.post(
            "stock/adjustment",
            JsonObject().apply {
                addProperty("adjustment_type", type)
                addProperty("remarks", remarks)
                add("lines", lines)
            },
        ).obj("data")

    fun minTriggers(): List<JsonObject> = api.get("min-inventory").dataRows()

    /** Short stock, most-sold first; limit = 0 → all. */
    fun minAlerts(limit: Int = 5): JsonObject =
        api.get("min-inventory/alerts", mapOf("limit" to limit.toString())).obj("data")

    fun minProducts(search: String): List<JsonObject> =
        api.get("min-inventory/products", mapOf("search" to search)).dataRows()

    fun minSave(productBindingId: Int, minQty: Int): JsonObject =
        api.post(
            "min-inventory",
            JsonObject().apply {
                addProperty("product_binding_id", productBindingId)
                addProperty("min_qty", minQty)
            },
        )

    fun minRemove(productBindingId: Int): JsonObject = api.delete("min-inventory/$productBindingId")

    fun searchProducts(q: String, limit: Int): List<JsonObject> =
        api.get("products/search", mapOf("q" to q, "limit" to limit.toString())).dataRows()

    fun hsnCodes(): List<String> =
        crud.list("hsn-codes", perPage = 500).rows
            .map { it.str("code").trim() }
            .filter { it.isNotEmpty() }
            .distinct()

    fun packSizes(): List<JsonObject> = api.get("meta/pack-sizes").dataRows()
}
