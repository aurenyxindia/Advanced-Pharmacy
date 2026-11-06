package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.str

/** Return Note hub (debit note, retail sales return, wholesale credit note, deduction %) and Daily Closing — same endpoints as web. */
class ReturnsRepository(private val api: ApiClient) {

    fun pharmacyState(): String = runCatching { api.get("meta/profile").obj("data").str("state") }.getOrDefault("")

    // ---- Debit Note (purchase return to distributor) ----
    fun debitNotes(search: String = ""): List<JsonObject> =
        api.get("debit-notes", if (search.isBlank()) emptyMap() else mapOf("search" to search)).dataRows()

    fun debitNote(id: Int): JsonObject = api.get("debit-notes/$id").obj("data")

    fun debitPdf(id: Int): ByteArray = api.getBytes("debit-notes/$id/pdf")

    fun debitProducts(): List<JsonObject> = api.get("debit-notes/return-products").dataRows()

    fun debitBatches(productName: String): List<JsonObject> =
        api.get("debit-notes/return-batches", mapOf("product_name" to productName)).dataRows()

    fun debitSuppliers(productName: String, batchNo: String): List<JsonObject> =
        api.get("debit-notes/return-suppliers", mapOf("product_name" to productName, "batch_no" to batchNo)).dataRows()

    fun saveDebit(editId: Int?, body: JsonObject): JsonObject =
        (if (editId != null) api.put("debit-notes/$editId/return", body) else api.post("debit-notes/return", body)).obj("data")

    // ---- Retail sales return ----
    fun returnableSales(search: String = "", limit: Int = 30): List<JsonObject> {
        val q = mutableMapOf("limit" to limit.toString())
        if (search.isNotBlank()) q["search"] = search
        return api.get("retail-sales-returns/sales", q).dataRows()
    }

    fun returnableSale(id: Int): JsonObject = api.get("retail-sales-returns/sales/$id").obj("data")

    fun saveSalesReturn(body: JsonObject): JsonObject = api.post("retail-sales-returns", body).obj("data")

    fun salesReturnHistory(search: String = ""): List<JsonObject> =
        api.get("retail-sales-returns/history", if (search.isBlank()) emptyMap() else mapOf("search" to search)).dataRows()

    fun salesReturnDetail(id: Int): JsonObject = api.get("retail-sales-returns/history/$id").obj("data")

    fun salesReturnPdf(id: Int): ByteArray = api.getBytes("retail-sales-returns/history/$id/pdf")

    // ---- Wholesale credit note ----
    fun creditNotes(search: String = ""): List<JsonObject> =
        api.get("credit-notes", if (search.isBlank()) emptyMap() else mapOf("search" to search)).dataRows()

    fun creditNote(id: Int): JsonObject = api.get("credit-notes/$id").obj("data")

    fun creditPdf(id: Int): ByteArray = api.getBytes("credit-notes/$id/pdf")

    fun creditCustomers(): List<JsonObject> = api.get("credit-notes/customers").dataRows()

    fun creditProducts(customer: String): List<JsonObject> =
        api.get("credit-notes/return-products", mapOf("customer" to customer)).dataRows()

    fun creditBatches(customer: String, productName: String): List<JsonObject> =
        api.get("credit-notes/return-batches", mapOf("customer" to customer, "product_name" to productName)).dataRows()

    fun saveCredit(editId: Int?, body: JsonObject): JsonObject =
        (if (editId != null) api.put("credit-notes/$editId/return", body) else api.post("credit-notes/return", body)).obj("data")

    // ---- Return deduction % ----
    fun deductions(): List<JsonObject> = api.get("return-deductions").dataRows()

    fun addDeduction(months: Int, pct: Double) {
        api.post("return-deductions", JsonObject().apply {
            addProperty("return_months", months)
            addProperty("deduction_pct", pct)
        })
    }

    // ---- Daily closing ----
    fun closingSummary(date: String): JsonObject = api.get("daily-closing/summary", mapOf("date" to date)).obj("data")

    /** Full response so the caller can show the server message and WhatsApp result. */
    fun dayEnd(date: String, availableCash: Double): JsonObject =
        api.post("daily-closing/day-end", JsonObject().apply {
            addProperty("date", date)
            addProperty("available_cash", availableCash)
        })

    fun closingReport(from: String, to: String): List<JsonObject> {
        val data = api.get("daily-closing/report", mapOf("from" to from, "to" to to)).obj("data")
        val rows = data.getAsJsonArray("rows") ?: return emptyList()
        return rows.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }
    }

    fun closingExport(from: String, to: String): ByteArray = api.getBytes("daily-closing/export", mapOf("from" to from, "to" to to))

    fun closingExportRow(date: String): ByteArray = api.getBytes("daily-closing/export-row", mapOf("closing_date" to date))
}
