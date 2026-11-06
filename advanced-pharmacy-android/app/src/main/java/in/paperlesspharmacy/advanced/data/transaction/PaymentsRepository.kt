package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.dataRows
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.paginatedFromApiData

/** Payments hub — distributor / retail / wholesale settle, history, vouchers, receipt print. */
class PaymentsRepository(private val api: ApiClient) {

    fun distributorDues(): List<JsonObject> = api.get("payments/dues/distributors").dataRows()

    fun customerDues(): List<JsonObject> = api.get("payments/dues/customers").dataRows()

    fun wholesaleCustomerDues(): List<JsonObject> = api.get("payments/dues/wholesale-customers").dataRows()

    fun settleDistributor(body: JsonObject): JsonObject = api.post("payments/settle/distributor", body).obj("data")

    fun settleCustomer(body: JsonObject): JsonObject = api.post("payments/settle/customer", body).obj("data")

    fun settleWholesaleCustomer(body: JsonObject): JsonObject =
        api.post("payments/settle/wholesale-customer", body).obj("data")

    fun history(partyType: String, party: String = "", phone: String = ""): List<JsonObject> {
        val q = mutableMapOf("party_type" to partyType)
        if (party.isNotBlank()) q["party"] = party
        if (phone.isNotBlank()) q["phone"] = phone
        return api.get("payments/history", q).dataRows()
    }

    fun printAllocationHtml(id: Int): ByteArray = api.getBytes("payments/allocations/$id/print")

    fun vouchers(search: String = ""): List<JsonObject> {
        val q = mutableMapOf("per_page" to "100")
        if (search.isNotBlank()) q["search"] = search
        val data = api.get("payments", q).obj("data")
        return if (data.has("data") && data.get("data").isJsonArray) {
            paginatedFromApiData(data).rows
        } else {
            api.get("payments", q).dataRows()
        }
    }

    fun saveVoucher(body: JsonObject): JsonObject = api.post("payments", body)

    fun deleteVoucher(id: Int) {
        api.delete("payments/$id")
    }
}
