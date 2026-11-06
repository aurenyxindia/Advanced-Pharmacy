package `in`.paperlesspharmacy.advanced.data.transaction

import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.data.ApiClient
import `in`.paperlesspharmacy.advanced.data.obj

class ProfileRepository(private val api: ApiClient) {
    fun get(): JsonObject = api.get("profile").obj("data")

    fun save(
        email: String,
        bankName: String,
        accountNo: String,
        ifsc: String,
        dob: String?,
        hideMasterProducts: Boolean? = null,
        hideMasterDistributors: Boolean? = null,
    ): JsonObject {
        val body = JsonObject().apply {
            addProperty("email", email)
            addProperty("bank_name", bankName)
            addProperty("account_no", accountNo)
            addProperty("ifsc_code", ifsc)
            if (dob.isNullOrBlank()) add("dob", com.google.gson.JsonNull.INSTANCE)
            else addProperty("dob", dob.take(10))
            // Admins only — client-wide Hide Master Catalogue (same as web My Profile).
            if (hideMasterProducts != null) {
                addProperty("hide_master_products", hideMasterProducts)
            }
            if (hideMasterDistributors != null) {
                addProperty("hide_master_distributors", hideMasterDistributors)
            }
        }
        return api.put("profile", body).obj("data")
    }

    fun upload(type: String, fileName: String, bytes: ByteArray, mime: String): JsonObject =
        api.postMultipart(
            path = "profile/upload/$type",
            fileField = "file",
            fileName = fileName,
            fileBytes = bytes,
            mimeType = mime,
        )

    fun assetBytes(clientId: Int, type: String): ByteArray =
        api.getBytes("profile/assets/$clientId/$type")
}
