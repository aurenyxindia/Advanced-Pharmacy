package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.CrudRepository
import `in`.paperlesspharmacy.advanced.data.PaginatedRows
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.paginatedFromApiData

class SalesRepository(
    private val api: ApiClient,
    private val crud: CrudRepository,
) {
    fun list(
        search: String = "",
        page: Int = 1,
        perPage: Int = 100,
        billMode: String = "",
        saleType: String = "",
    ): PaginatedRows {
        val q = mutableMapOf("page" to page.toString(), "per_page" to perPage.toString())
        if (search.isNotBlank()) q["search"] = search
        if (billMode.isNotBlank()) q["bill_mode"] = billMode
        if (saleType.isNotBlank()) q["sale_type"] = saleType
        return paginatedFromApiData(api.get("sales", q).obj("data"))
    }

    fun get(id: Int): JsonObject = api.get("sales/$id").obj("data")

    fun create(body: JsonObject) = api.post("sales", body)

    fun update(id: Int, body: JsonObject) = api.put("sales/$id", body)

    fun searchProducts(q: String, genericId: Int = 0): List<JsonObject> {
        val params = mutableMapOf("q" to q)
        if (genericId > 0) params["generic_id"] = genericId.toString()
        return api.get("sales/search-products", params).dataRows()
    }

    fun batches(bindingId: Int): List<JsonObject> =
        api.get("bound-products/$bindingId/batches").dataRows()

    fun scan(code: String): JsonObject =
        api.get("sales/scan", mapOf("code" to code)).obj("data")

    fun lookupCustomers(phone: String): List<JsonObject> =
        api.get("customers/lookup", mapOf("phone" to phone)).dataRows()

    fun updatePaymentMode(id: Int, paymentMode: String, accountId: Int? = null, cashAmount: Double? = null): JsonObject {
        val body = JsonObject().apply {
            addProperty("payment_mode", paymentMode.lowercase())
            accountId?.let { addProperty("account_id", it) }
            cashAmount?.let { addProperty("cash_amount", it) }
        }
        return api.patch("sales/$id/payment-mode", body).obj("data")
    }

    fun sendWhatsApp(id: Int): JsonObject = api.post("sales/$id/whatsapp", JsonObject()).obj("data")

    fun invoicePdf(id: Int): ByteArray = api.getBytes("sales/$id/invoice")

    fun invoiceDualPdf(id: Int): ByteArray = api.getBytes("sales/$id/invoice-dual")

    fun invoiceDotMatrixHtml(id: Int): ByteArray = api.getBytes("sales/$id/invoice-dotmatrix")

    fun accounts(): List<JsonObject> = crud.list("accounts", perPage = 200).rows
}
