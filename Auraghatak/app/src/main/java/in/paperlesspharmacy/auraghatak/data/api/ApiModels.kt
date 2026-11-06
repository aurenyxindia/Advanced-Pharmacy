package in.paperlesspharmacy.auraghatak.data.api

import com.google.gson.annotations.SerializedName

data class ApiEnvelope<T>(
    val success: Boolean?,
    val message: String?,
    val data: T?,
)

data class ShopPayload(
    val pharmacy: PharmacyInfo?,
    val patient: PatientInfo?,
    val token: String?,
    val products: List<ProductRow>?,
)

data class PharmacyInfo(
    val name: String?,
    val contact: String?,
    val address: String?,
)

data class PatientInfo(
    val name: String?,
    val contact: String?,
    val address: String?,
    @SerializedName("patient_no") val patientNo: String?,
)

data class ProductRow(
    @SerializedName("p_name") val productName: String?,
    @SerializedName("batch_no") val batchNo: String?,
    @SerializedName("pack_size") val packSize: String?,
    @SerializedName("unit_mrp") val unitMrp: Double?,
    val mrp: Double?,
    @SerializedName("availableQty") val availableQty: Double?,
)

data class PlaceOrderRequest(
    val token: String,
    val items: List<CartItemDto>,
    @SerializedName("patient_note") val patientNote: String? = null,
    @SerializedName("prescription_base64") val prescriptionBase64: String? = null,
)

data class CartItemDto(
    @SerializedName("p_name") val productName: String,
    @SerializedName("batch_no") val batchNo: String,
    val qty: Int,
    @SerializedName("unit_mrp") val unitMrp: Double,
)

data class PlaceOrderResult(
    @SerializedName("order_no") val orderNo: String?,
    @SerializedName("order_id") val orderId: Int?,
    @SerializedName("estimated_total") val estimatedTotal: Double?,
)

data class PublicBranch(
    val id: Int?,
    val name: String?,
    val city: String?,
    @SerializedName("business_name") val businessName: String?,
)
