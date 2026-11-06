package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj

/** Head Office — branch performance, portfolio, stock transfer (same endpoints as web). */
class HoRepository(private val api: ApiClient) {

    fun branchPerformance(): JsonObject = api.get("ho/branch-performance").obj("data")

    fun portfolio(): List<JsonObject> = api.get("ho/portfolio").dataRows()

    fun branches(): List<JsonObject> = api.get("meta/branches").dataRows()

    fun branchStock(branchId: Int, q: String = ""): List<JsonObject> {
        val params = mutableMapOf("branch_id" to branchId.toString())
        if (q.isNotBlank()) params["q"] = q.trim()
        return api.get("ho/branch-stock", params).dataRows()
    }

    fun transfers(): List<JsonObject> = api.get("ho/transfers").dataRows()

    fun transferLog(id: Int): JsonObject = api.get("ho/transfers/$id").obj("data")

    fun transfer(fromBranchId: Int, toBranchId: Int, items: List<Pair<Int, Double>>, notes: String = ""): JsonObject {
        val body = JsonObject().apply {
            addProperty("from_branch_id", fromBranchId)
            addProperty("to_branch_id", toBranchId)
            if (notes.isNotBlank()) addProperty("notes", notes)
            add(
                "items",
                JsonArray().apply {
                    items.forEach { (bindingId, qty) ->
                        add(JsonObject().apply {
                            addProperty("product_binding_id", bindingId)
                            addProperty("quantity", qty)
                        })
                    }
                },
            )
        }
        return api.post("ho/transfers", body)
    }
}
