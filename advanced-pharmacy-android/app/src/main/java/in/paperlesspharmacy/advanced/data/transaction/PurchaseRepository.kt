package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.PaginatedRows
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.paginatedFromApiData

class PurchaseRepository(
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    fun list(
        search: String = "",
        page: Int = 1,
        perPage: Int = 50,
        month: String = "",
        fromDate: String = "",
        toDate: String = "",
    ): PaginatedRows {
        val q = mutableMapOf("page" to page.toString(), "per_page" to perPage.toString())
        if (search.isNotBlank()) q["search"] = search
        if (month.isNotBlank()) q["month"] = month
        if (fromDate.isNotBlank()) q["from_date"] = fromDate
        if (toDate.isNotBlank()) q["to_date"] = toDate
        return paginatedFromApiData(api.get("purchases", q).obj("data"))
    }

    fun get(id: Int): JsonObject = api.get("purchases/$id").obj("data")

    fun create(body: JsonObject) = api.post("purchases", body)

    fun update(id: Int, body: JsonObject) = api.put("purchases/$id", body)

    fun delete(id: Int) = api.delete("purchases/$id")

    fun searchProducts(q: String, limit: Int = 40): List<JsonObject> =
        api.get("products/search", mapOf("q" to q, "limit" to limit.toString())).dataRows()

    fun checkInvoice(invoiceNo: String): JsonObject =
        api.get("import/purchase/check-invoice", mapOf("invoice_no" to invoiceNo)).obj("data")

    fun computeRates(mrp: Double, gst: Double, category: String): JsonObject {
        val q = mapOf("mrp" to mrp.toString(), "gst" to gst.toString(), "category" to category)
        return api.get("rate-margins/compute", q).obj("data")
    }

    fun searchSuppliers(q: String): List<JsonObject> =
        crud.list("distributors", search = q, perPage = 200).rows

    /** Whole distributor catalog (API caps 200 per page). */
    fun allDistributors(): List<JsonObject> {
        val out = mutableListOf<JsonObject>()
        var page = 1
        while (page <= 20) {
            val r = crud.list("distributors", page = page, perPage = 200)
            out += r.rows
            if (r.rows.isEmpty() || out.size >= r.total) break
            page++
        }
        return out.sortedBy { it.get("name")?.asString?.uppercase().orEmpty() }
    }

    fun packSizes(): List<JsonObject> = api.get("meta/pack-sizes").dataRows()

    fun parsePack(raw: String): JsonObject = api.get("meta/parse-pack", mapOf("raw" to raw)).obj("data")

    /** Open debit notes for "Return Bill Deduction" (same as web `debit-notes/open`). */
    fun openDebitNotes(party: String): List<JsonObject> =
        api.get("debit-notes/open", mapOf("party" to party)).dataRows()

    fun barcodesPdf(id: Int): ByteArray = api.getBytes("purchases/$id/barcodes")

    fun labelBarcodesPdf(id: Int): ByteArray = api.getBytes("purchases/$id/label-barcodes")
}
